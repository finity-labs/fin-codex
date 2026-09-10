<?php

use Filament\Notifications\DatabaseNotification;
use FinityLabs\FinCodex\Ai\NotificationLocale;
use FinityLabs\FinCodex\Ai\NotificationPanel;
use FinityLabs\FinCodex\Ai\NotifyTranslationFinished;
use FinityLabs\FinCodex\Editor\ArticleTitle;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Events\ArticleTranslated;
use FinityLabs\LinCodex\Jobs\TranslateArticle;
use FinityLabs\LinCodex\Locale\LocaleResolver;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Translation\TranslationReport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/*
 * AIBULK-03: the admin who queued a translation run is told how it went.
 *
 * Every row dispatches ArticleTranslated by hand, with the real dispatcher and
 * never Event::fake(), because the listener IS what is under test here: faking
 * the dispatcher would assert that the event was sent, not that a row landed
 * in the notifications table. The end-to-end chain - row action, queued job,
 * event, listener, badge - is 11-06's.
 */

/** A fixture user, the admin whose id the event carries. */
function finCodexNotifyUser(string $name = 'Notify'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * The languages this file's reports name. Its own helper rather than a
 * borrowed one: a Pest helper is loaded with its file, so a helper from
 * another test file is undefined the moment this one runs alone.
 *
 * @param  list<string>  $codes
 */
function finCodexNotifyUseLanguages(array $codes = ['en', 'de', 'hu', 'ro'], string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/** An article with one English translation, the way the editor leaves it. */
function finCodexNotifyArticle(string $title = 'Users'): Article
{
    $article = Article::factory()->markdown()->create(['slug' => 'users']);

    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => $title,
        'excerpt' => 'Manage users.',
        'body' => 'How users work.',
    ]);

    return $article;
}

/**
 * A report recorded in the order the job would have worked through it:
 * translated first, then failed, then skipped.
 *
 * @param  list<string>  $translated
 * @param  array<string, string>  $failed  locale => AiReason key
 * @param  list<string>  $skipped
 */
function finCodexNotifyReport(array $translated = [], array $failed = [], array $skipped = []): TranslationReport
{
    $report = new TranslationReport;

    foreach ($translated as $locale) {
        $report->translated($locale, 1, 2);
    }

    foreach ($failed as $locale => $reason) {
        $report->failed($locale, $reason);
    }

    foreach ($skipped as $locale) {
        $report->skipped($locale);
    }

    return $report;
}

/** Fire the event the job fires, with the real dispatcher. */
function finCodexNotifyFire(Article|int $article, ?int $userId, TranslationReport $report): void
{
    event(new ArticleTranslated($article instanceof Article ? $article->id : $article, $userId, $report));
}

/** The display name lin-codex renders a locale under. */
function finCodexNotifyName(string $code): string
{
    return app(LocaleResolver::class)->displayName($code);
}

beforeEach(function (): void {
    finCodexNotifyUseLanguages();
});

it('heads a success row with a fixed title and names the article and its new languages in the body', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    $this->usesPanel('admin', $user);

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['de', 'hu']));

    $stored = finCodexNotificationsFor($user);

    expect($stored)->toHaveCount(1);

    $row = $stored->first();

    expect($row->type)->toBe(DatabaseNotification::class)
        ->and($row->data['format'])->toBe('filament')
        ->and($row->data['status'])->toBe('success')
        ->and($row->data['title'])->toBe(__('fin-codex::fin-codex.notification.title.translated'))
        // The title says what happened, never what the article is called: out
        // of context "Users" reads as news about users.
        ->and($row->data['title'])->not->toBe(ArticleTitle::ofModel($article->load('translations')))
        ->and($row->data['title'])->not->toContain('Users')
        ->and($row->data['body'])->toBe(__('fin-codex::fin-codex.notification.body.translated', [
            'title' => ArticleTitle::ofModel($article->load('translations')),
            'languages' => finCodexNotifyName('de').', '.finCodexNotifyName('hu'),
        ]))
        ->and($row->data['body'])->toContain('Users')
        ->and($row->data['duration'])->toBe('persistent')
        ->and($row->data['actions'])->toHaveCount(1)
        ->and($row->data['actions'][0]['label'])->toBe(__('fin-codex::fin-codex.notification.open'))
        ->and($row->data['actions'][0]['url'])->toEndWith('/codex-articles/'.$article->id.'/edit')
        ->and($row->read_at)->toBeNull();
});

it('turns warning and names the failed languages with their reason labels, never the skipped ones', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(
        ['de'],
        ['hu' => AiReason::RATE_LIMITED],
        ['ro'],
    ));

    $row = finCodexNotificationsFor($user)->first();

    $translated = __('fin-codex::fin-codex.notification.body.translated', [
        'title' => ArticleTitle::ofModel($article->load('translations')),
        'languages' => finCodexNotifyName('de'),
    ]);
    $failed = __('fin-codex::fin-codex.notification.body.also_failed', [
        'languages' => finCodexNotifyName('hu').' ('.AiReason::label(AiReason::RATE_LIMITED).')',
    ]);

    expect($row->data['status'])->toBe('warning')
        // Something did arrive, so the title is still the translated one; the
        // amber and the second sentence carry the failure.
        ->and($row->data['title'])->toBe(__('fin-codex::fin-codex.notification.title.translated'))
        ->and($row->data['body'])->toBe($translated.' '.$failed)
        ->and($row->data['body'])->toContain('Users')
        ->and($row->data['body'])->not->toContain(finCodexNotifyName('ro'));
});

it('names the article in the one sentence a run that translated nothing gets', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(
        [],
        ['hu' => AiReason::RATE_LIMITED],
        ['ro'],
    ));

    $row = finCodexNotificationsFor($user)->first();

    expect($row->data['status'])->toBe('warning')
        ->and($row->data['title'])->toBe(__('fin-codex::fin-codex.notification.title.failed'))
        ->and($row->data['body'])->toBe(__('fin-codex::fin-codex.notification.body.failed_only', [
            'title' => ArticleTitle::ofModel($article->load('translations')),
            'languages' => finCodexNotifyName('hu').' ('.AiReason::label(AiReason::RATE_LIMITED).')',
        ]))
        ->and($row->data['body'])->toContain('Users')
        ->and($row->data['body'])->not->toContain(finCodexNotifyName('ro'));
});

it('says nothing was needed when every language was skipped', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport([], [], ['de', 'hu']));

    $row = finCodexNotificationsFor($user)->first();

    expect($row->data['status'])->toBe('success')
        ->and($row->data['title'])->toBe(__('fin-codex::fin-codex.notification.title.nothing'))
        ->and($row->data['body'])->toBe(__('fin-codex::fin-codex.notification.body.nothing_to_do', [
            'title' => ArticleTitle::ofModel($article->load('translations')),
        ]))
        ->and($row->data['body'])->toContain('Users');
});

it('renders the whole notification in the language the press was made in, not the worker\'s', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    // A German title as well, so the article's own name is something the
    // locale can get wrong: under en the body would read "Users".
    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'de',
        'title' => 'Benutzer',
        'excerpt' => 'Benutzer verwalten.',
        'body' => 'So funktionieren Benutzer.',
    ]);

    // The worker's own locale, which is where the wrong language came from:
    // the panel was being read in German, the application is configured in
    // English, and a queued job has neither session nor request.
    app()->setLocale('en');
    Context::add(NotificationLocale::KEY, 'de');

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['hu'], ['ro' => AiReason::RATE_LIMITED]));

    $row = finCodexNotificationsFor($user)->first();

    $german = (string) __('fin-codex::fin-codex.notification.body.translated', [
        'title' => 'Benutzer',
        'languages' => finCodexNotifyName('hu'),
    ], 'de').' '.(string) __('fin-codex::fin-codex.notification.body.also_failed', [
        'languages' => finCodexNotifyName('ro').' ('.(string) __('lin-codex::lin-codex.ai.reasons.rate_limited', [], 'de').')',
    ], 'de');

    expect($row->data['title'])->toBe((string) __('fin-codex::fin-codex.notification.title.translated', [], 'de'))
        ->and($row->data['title'])->not->toBe((string) __('fin-codex::fin-codex.notification.title.translated', [], 'en'))
        // The article's own title, the fixed labels and the reason label all
        // came out of the same closure, so all three followed the locale.
        ->and($row->data['body'])->toBe($german)
        ->and($row->data['body'])->toContain('Benutzer')
        ->and($row->data['body'])->not->toContain('Users')
        ->and($row->data['actions'][0]['label'])->toBe((string) __('fin-codex::fin-codex.notification.open', [], 'de'));

    // And the worker is handed back the locale it was running under: the next
    // job on the same process is not answered in this one's language.
    expect(app()->getLocale())->toBe('en');
});

it('falls back to the locale it is running under when the press left none behind', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    // A job queued by something other than the two actions, or a payload
    // written before the key existed: nothing in the context to read.
    expect(Context::get(NotificationLocale::KEY))->toBeNull();

    app()->setLocale('de');

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['hu']));

    $row = finCodexNotificationsFor($user)->first();

    expect($row->data['title'])->toBe((string) __('fin-codex::fin-codex.notification.title.translated', [], 'de'))
        ->and($row->data['body'])->toBe((string) __('fin-codex::fin-codex.notification.body.translated', [
            // Only an English translation exists, so the default language
            // names the article; the wording around it is still German.
            'title' => 'Users',
            'languages' => finCodexNotifyName('hu'),
        ], 'de'))
        ->and(app()->getLocale())->toBe('de');
});

it('is registered by the service provider', function (): void {
    expect(Event::hasListeners(ArticleTranslated::class))->toBeTrue();

    $raw = Event::getRawListeners();

    expect($raw)->toHaveKey(ArticleTranslated::class)
        ->and($raw[ArticleTranslated::class])->toContain(NotifyTranslationFinished::class);
});

it('tells nobody when the user id is null or the user is gone', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    finCodexNotifyFire($article, null, finCodexNotifyReport(['de']));

    expect(DB::table('notifications')->count())->toBe(0);

    finCodexNotifyFire($article, $user->id + 100, finCodexNotifyReport(['de']));

    expect(DB::table('notifications')->count())->toBe(0);
});

it('names a deleted article by its id inside the body and offers no button', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();
    $id = $article->id;

    $article->delete();

    finCodexNotifyFire($id, $user->id, finCodexNotifyReport([], [
        'de' => AiReason::UNKNOWN,
        'hu' => AiReason::UNKNOWN,
    ]));

    $stored = finCodexNotificationsFor($user);

    expect($stored)->toHaveCount(1);

    $row = $stored->first();

    $deleted = (string) __('fin-codex::fin-codex.notification.deleted_article', ['id' => $id]);

    expect($row->data['title'])->toBe(__('fin-codex::fin-codex.notification.title.failed'))
        // The deleted-article string is the article's NAME now, not the title.
        ->and($row->data['title'])->not->toContain($deleted)
        ->and($row->data['body'])->toBe(__('fin-codex::fin-codex.notification.body.failed_only', [
            'title' => $deleted,
            'languages' => finCodexNotifyName('de').' ('.AiReason::label(AiReason::UNKNOWN).'), '
                .finCodexNotifyName('hu').' ('.AiReason::label(AiReason::UNKNOWN).')',
        ]))
        ->and($row->data['body'])->toContain($deleted)
        ->and($row->data['actions'])->toBe([])
        ->and($row->data['status'])->toBe('warning');
});

it('stores the row inside the call on a faked queue', function (): void {
    Queue::fake();

    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['de']));

    expect(finCodexNotificationsFor($user))->toHaveCount(1);

    Queue::assertNothingPushed();
});

it('logs a warning naming the failed languages and their reasons whether or not anyone is told', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    Log::spy();

    $report = finCodexNotifyReport([], [
        'hu' => AiReason::RATE_LIMITED,
        'ro' => AiReason::TIMEOUT,
    ]);

    finCodexNotifyFire($article, $user->id, $report);

    expect(finCodexNotificationsFor($user))->toHaveCount(1);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'fin-codex: AI translation failed for some languages'
            && $context['article_id'] === $article->id
            && $context['user_id'] === $user->id
            && $context['failed'] === ['hu' => 'rate_limited', 'ro' => 'timeout']);

    finCodexNotifyFire($article, null, $report);

    expect(finCodexNotificationsFor($user))->toHaveCount(1);

    Log::shouldHaveReceived('warning')->twice();
});

it('logs no warning when nothing failed', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    Log::spy();

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['de'], [], ['hu']));

    expect(finCodexNotificationsFor($user))->toHaveCount(1);

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('logs an error with context and lets the job finish when the notification cannot be stored', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    $report = finCodexNotifyReport(['de'], ['hu' => AiReason::QUOTA_EXCEEDED]);

    Schema::drop('notifications');

    Log::spy();

    finCodexNotifyFire($article, $user->id, $report);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'fin-codex: could not store the translation notification'
            && $context['article_id'] === $article->id
            && $context['user_id'] === $user->id
            && $context['locales'] === ['de', 'hu']
            && $context['report'] === $report->toArray()
            && $context['exception'] instanceof QueryException);

    Log::shouldHaveReceived('warning')->once();
});

it('resolves the recipient and the button through the panel the press was made on, not the default one', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    /*
     * A host shaped like the ones this listener used to lose: the panel the
     * admin pressed in is NOT the default one, and the default panel's guard
     * cannot answer for the admin at all - its provider does not exist, the
     * stand-in here for a second panel on a second table. A worker has no
     * current panel, so a listener that guessed would ask exactly this guard.
     */
    config(['auth.guards.web.provider' => 'nobody-at-all']);

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['de']));

    expect(finCodexNotificationsFor($user))->toHaveCount(0);

    // The press recorded its panel beside its locale, and the payload carried
    // both to the worker.
    Context::add(NotificationPanel::KEY, 'staff');

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['de']));

    $stored = finCodexNotificationsFor($user);

    expect($stored)->toHaveCount(1);

    // And the button opens the article on that panel - through its own
    // resource override, at its own route - rather than on the default one.
    expect($stored->first()->data['actions'][0]['url'])
        ->toContain('/staff/')
        ->toEndWith('/codex-articles/'.$article->id.'/edit')
        ->not->toContain('/admin/');
});

it('falls back to the current-or-default panel when the press recorded none', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    // An older payload, or a job queued by something other than the two
    // actions: nothing in the context to read, and the behaviour is the one
    // this listener always had.
    expect(Context::get(NotificationPanel::KEY))->toBeNull();

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['de']));

    expect(finCodexNotificationsFor($user)->first()->data['actions'][0]['url'])
        ->toContain('/admin/')
        ->toEndWith('/codex-articles/'.$article->id.'/edit');

    // A panel that has since been taken out of the host reads the same way:
    // the id resolves to nothing and nothing is guessed from it.
    Context::add(NotificationPanel::KEY, 'panel-that-was-removed');

    finCodexNotifyFire($article, $user->id, finCodexNotifyReport(['hu']));

    expect(finCodexNotificationsFor($user))->toHaveCount(2)
        ->and(finCodexNotificationsFor($user)->last()->data['actions'][0]['url'])->toContain('/admin/');
});

it('logs the failure and lets the job finish when the notification cannot even be built', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    finCodexFakeAi(FakeAiClient::translating(['title' => 'Benutzer', 'excerpt' => null, 'body' => 'Text']));
    finCodexEnableAi();

    /*
     * The panel recorded carries no article resource, so the edit URL names a
     * route it never registered and composing throws - the step that used to
     * sit one line above the catch, where a throwable failed a job whose
     * translations were already written.
     */
    Context::add(NotificationPanel::KEY, 'plain');

    Log::spy();

    dispatch_sync(new TranslateArticle($article->id, ['de'], $user->id));

    expect(ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'de')->exists())->toBeTrue()
        ->and(finCodexNotificationsFor($user))->toHaveCount(0);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'fin-codex: could not store the translation notification'
            && $context['article_id'] === $article->id
            && $context['user_id'] === $user->id
            && $context['exception'] instanceof RouteNotFoundException);
});

it('keeps the queued job green when the store fails inside it', function (): void {
    $user = finCodexNotifyUser();
    $article = finCodexNotifyArticle();

    finCodexFakeAi(FakeAiClient::translating(['title' => 'Benutzer', 'excerpt' => null, 'body' => 'Text']));
    finCodexEnableAi();

    Schema::drop('notifications');

    Log::spy();

    dispatch_sync(new TranslateArticle($article->id, ['de'], $user->id));

    expect(ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'de')->exists())->toBeTrue();

    Log::shouldHaveReceived('error')->once();
});
