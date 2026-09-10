<?php

use Filament\Notifications\Notification;
use FinityLabs\FinCodex\Editor\ArticleTitle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Ai\AiCallFailed;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Ai\StructuredCompletion;
use FinityLabs\LinCodex\Locale\LocaleResolver;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
 * AIBULK-03's badge half and AIBULK-04's sync clause, in one piece.
 *
 * The other files in this phase each prove one link: the row action queues a
 * job, the bulk action queues one per article, the listener stores a row for
 * an event it is handed. This one presses the button on the real chain and
 * watches the whole thing happen inside the request - action, queued job,
 * ArticleTranslated, listener, notifications row - and then reloads the list
 * to see the flag turn.
 *
 * Neither the queue fake nor the event fake appears anywhere in this file, on
 * purpose - and the two names are kept out of the text as well, so a grep for
 * either over this file answers honestly. phpunit.xml runs the suite on the
 * sync queue connection, so TranslateArticle::dispatch() from the action runs
 * the job inside the Livewire request; faking the queue would swallow the job
 * and prove only that something was pushed, and faking the dispatcher would
 * swallow ArticleTranslated so the listener would never run. The seam is still
 * the fake AiClient bound by finCodexFakeAi() - the only thing this file
 * replaces - so no HTTP call is made and every row runs on every CI row.
 */

/** A fixture user signed in on the admin panel; the same row on every call. */
function finCodexE2eUser(string $name = 'E2E'): User
{
    return User::firstOrCreate(
        ['email' => strtolower($name).'@example.com'],
        ['name' => $name],
    );
}

/**
 * The languages this file's articles are written in. Its own helper rather
 * than a borrowed one: a Pest helper is loaded with its file, so a helper from
 * another test file is undefined the moment this one runs on its own.
 *
 * @param  list<string>  $codes
 */
function finCodexE2eUseLanguages(array $codes = ['en', 'de', 'hu'], string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/**
 * A Markdown article with one translation row per locale => fields pair, the
 * row action's fixture shape: the title reads "EN users" so the notification's
 * own title is readable in an assertion.
 *
 * @param  array<string, array<string, mixed>>  $translations
 */
function finCodexE2eArticle(string $slug, array $translations = []): Article
{
    $article = Article::factory()->public()->published()->markdown()->create(['slug' => $slug]);

    foreach ($translations as $locale => $fields) {
        ArticleTranslation::factory()->create(array_merge([
            'article_id' => $article->id,
            'locale' => $locale,
            'title' => strtoupper($locale).' '.$slug,
            'body' => strtoupper($locale).' body',
        ], $fields));
    }

    return $article;
}

/** The display name lin-codex renders a locale under, the one the body names. */
function finCodexE2eName(string $code): string
{
    return app(LocaleResolver::class)->displayName($code);
}

/**
 * One language's flag state on one row of the rendered list: "present" or
 * "missing", null when the language is not painted at all. The row is cut from
 * its slug marker to the next one (ListArticlesTest's rule, so "users" never
 * matches the "users/roles" row) and the state is read off the attribute pair
 * the languages column writes.
 */
function finCodexE2eFlagState(string $html, string $slug, string $locale): ?string
{
    $start = strpos($html, 'data-fin-codex-slug="'.$slug.'"');

    expect($start)->not->toBeFalse("No row markup for [{$slug}].");

    $rest = substr($html, (int) $start);
    $next = strpos($rest, 'data-fin-codex-slug="', 1);
    $row = $next === false ? $rest : substr($rest, 0, $next);

    $matched = preg_match(
        '/data-fin-codex-locale="'.$locale.'"[^>]*data-fin-codex-state="([a-z]+)"/',
        $row,
        $matches,
    );

    return $matched === 1 ? $matches[1] : null;
}

/** One completion the fake answers a translation call with. */
function finCodexE2eCompletion(string $title = 'Benutzer', string $body = 'Text'): StructuredCompletion
{
    return new StructuredCompletion(['title' => $title, 'excerpt' => null, 'body' => $body], 0, 0, false);
}

beforeEach(function (): void {
    finCodexE2eUseLanguages();
    finCodexEnableAi();

    $this->admin = finCodexE2eUser();
    $this->usesPanel('admin', $this->admin);
});

it('runs the job, stores the translation and the notification, and toasts, all inside one press', function (): void {
    $fake = finCodexFakeAi(FakeAiClient::translating(['title' => 'Benutzer', 'excerpt' => null, 'body' => 'Text']));

    // en only: lacks de and hu.
    $gap = finCodexE2eArticle('users', ['en' => []]);

    Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $gap, data: ['locales' => ['de']])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('fin-codex::fin-codex.editor.translate_missing.queued_title'));

    // The job ran inside that request: the row is written by the fake client.
    $de = ArticleTranslation::query()
        ->where('article_id', $gap->id)
        ->where('locale', 'de')
        ->first();

    expect($de)->not->toBeNull()
        ->and($de->title)->toBe('Benutzer')
        ->and($de->body)->toBe('Text')
        // hu was not ticked, so it is still missing.
        ->and(ArticleTranslation::query()->where('article_id', $gap->id)->where('locale', 'hu')->exists())->toBeFalse()
        // One locale, one structured call: nothing translated twice.
        ->and($fake->requests)->toHaveCount(1);

    $stored = finCodexNotificationsFor($this->admin);

    expect($stored)->toHaveCount(1);

    $row = $stored->first();

    expect($row->data['status'])->toBe('success')
        ->and($row->data['title'])->toBe(__('fin-codex::fin-codex.notification.title.translated'))
        ->and($row->data['body'])->toBe(__('fin-codex::fin-codex.notification.body.translated', [
            'title' => ArticleTitle::ofModel($gap->load('translations')),
            'languages' => finCodexE2eName('de'),
        ]))
        ->and($row->data['body'])->toContain('EN users')
        ->and($row->data['actions'][0]['url'])->toEndWith('/codex-articles/'.$gap->id.'/edit');

    // And the list paints the language the admin just filled.
    $html = Livewire::test(ListArticles::class)->html();

    expect(finCodexE2eFlagState($html, 'users', 'de'))->toBe('present')
        ->and(finCodexE2eFlagState($html, 'users', 'hu'))->toBe('missing');
});

it('stores one notification per article through the bulk action', function (): void {
    $fake = finCodexFakeAi((new FakeAiClient)
        ->push(finCodexE2eCompletion())
        ->push(finCodexE2eCompletion()));

    // Lacks de alone, and lacks both: one de job each.
    $gapDe = finCodexE2eArticle('billing', ['en' => [], 'hu' => []]);
    $gapBoth = finCodexE2eArticle('users', ['en' => []]);

    $summary = (string) __('fin-codex::fin-codex.editor.translate_missing.bulk_summary', [
        'queued' => 2,
        'nothing' => 0,
    ]);

    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$gapDe, $gapBoth], data: ['locales' => ['de']])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified(
            Notification::make()
                ->success()
                ->title(__('fin-codex::fin-codex.editor.translate_missing.queued_title'))
                ->body($summary),
        );

    expect(ArticleTranslation::query()
        ->whereIn('article_id', [$gapDe->id, $gapBoth->id])
        ->where('locale', 'de')
        ->count())->toBe(2)
        ->and($fake->requests)->toHaveCount(2);

    $rows = finCodexNotificationsFor($this->admin);

    // Two rows under the same fixed title, each naming its own article in the
    // body: the title says what happened, the body says what it happened to.
    $titles = $rows
        ->map(static fn (DatabaseNotification $row): string => (string) $row->data['title'])
        ->unique()
        ->values()
        ->all();

    $bodies = $rows
        ->map(static fn (DatabaseNotification $row): string => (string) $row->data['body'])
        ->sort()
        ->values()
        ->all();

    expect($rows)->toHaveCount(2)
        ->and($titles)->toBe([(string) __('fin-codex::fin-codex.notification.title.translated')])
        ->and($bodies[0])->toContain('EN billing')
        ->and($bodies[1])->toContain('EN users');
});

it('turns the notification into a warning and logs when a language fails', function (): void {
    finCodexFakeAi((new FakeAiClient)->push(new AiCallFailed(AiReason::RATE_LIMITED)));

    $gap = finCodexE2eArticle('users', ['en' => []]);

    Log::spy();

    Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $gap, data: ['locales' => ['de']])
        ->assertHasNoTableActionErrors()
        // The press itself succeeded, so the queue-time toast still shows: the
        // failure is the job's news, and it arrives in the bell.
        ->assertNotified(__('fin-codex::fin-codex.editor.translate_missing.queued_title'));

    expect(ArticleTranslation::query()->where('article_id', $gap->id)->where('locale', 'de')->exists())->toBeFalse();

    $row = finCodexNotificationsFor($this->admin)->first();

    expect($row)->not->toBeNull()
        ->and($row->data['status'])->toBe('warning')
        ->and($row->data['title'])->toBe(__('fin-codex::fin-codex.notification.title.failed'))
        ->and($row->data['body'])->toBe(__('fin-codex::fin-codex.notification.body.failed_only', [
            'title' => ArticleTitle::ofModel($gap->load('translations')),
            'languages' => finCodexE2eName('de').' ('.AiReason::label(AiReason::RATE_LIMITED).')',
        ]))
        ->and($row->data['body'])->toContain('EN users');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'fin-codex: AI translation failed for some languages'
            && $context['article_id'] === $gap->id
            && $context['user_id'] === $this->admin->id
            && $context['failed'] === ['de' => AiReason::RATE_LIMITED]);
});

it('keeps the press green when the notification cannot be stored', function (): void {
    finCodexFakeAi(FakeAiClient::translating(['title' => 'Benutzer', 'excerpt' => null, 'body' => 'Text']));

    $gap = finCodexE2eArticle('users', ['en' => []]);

    // A host that never ran make:notifications-table loses the bell and
    // nothing else: the translation is written and the log carries the report.
    Schema::drop('notifications');

    Log::spy();

    Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $gap, data: ['locales' => ['de']])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('fin-codex::fin-codex.editor.translate_missing.queued_title'));

    expect(ArticleTranslation::query()->where('article_id', $gap->id)->where('locale', 'de')->exists())->toBeTrue();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'fin-codex: could not store the translation notification'
            && $context['article_id'] === $gap->id
            && $context['user_id'] === $this->admin->id
            && $context['locales'] === ['de']);
});
