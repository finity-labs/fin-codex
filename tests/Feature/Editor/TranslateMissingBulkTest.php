<?php

use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use FinityLabs\FinCodex\Ai\NotificationLocale;
use FinityLabs\FinCodex\Ai\NotificationPanel;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Jobs\TranslateArticle;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * AIBULK-02 and the bulk half of AIBULK-04: Translate missing over a selection.
 *
 * Every row drives the article list, so what is asserted is what the admin
 * gets: the toolbar button is resolved out of the live table and pressed
 * through the page, never called by hand. Unlike the row action's file, this
 * one never opens an editor - the whole point of the bulk action is to fill
 * every gap in the selection from the list.
 *
 * A hidden bulk action takes the checkbox column with it, so the AI-off row
 * asserts the markup as well as the button: with AI unavailable the list looks
 * exactly as it did before the slot existed.
 */

/**
 * A host that lets this user read the article list but never update anything
 * on it. It defines update() rather than leaving it out, so the denial is an
 * answer and ArticleAbility's fallback cannot rescue it.
 *
 * Not the shipped DenyAllArticlePolicy: that one refuses viewAny too, and
 * Livewire replays the panel's route middleware on every update request, so
 * the list page answers 403 on the request the press itself makes and the
 * component is gone before the loop can count anything. A user who may not
 * even open the list never reaches this action; a user who may open it and
 * may not write is the case the count exists for.
 */
class FinCodexBulkNoUpdatePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function restore(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function import(Authenticatable $user): bool
    {
        return true;
    }

    public function convert(Authenticatable $user, Article $article): bool
    {
        return true;
    }
}

/**
 * The same host, one article at a time: everything is updatable except the one
 * whose slug is "locked". It is what makes the third clause read "One article
 * was skipped" beside two queued jobs.
 */
class FinCodexBulkLockedSlugPolicy extends FinCodexBulkNoUpdatePolicy
{
    public function update(Authenticatable $user, Article $article): bool
    {
        return $article->slug !== 'locked';
    }
}

/** A fixture user signed in on the admin panel; the same row on every call. */
function finCodexBulkUser(string $name = 'Bulk'): User
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
function finCodexBulkUseLanguages(array $codes = ['en', 'de', 'hu'], string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/**
 * An article with one translation row per entry of $translations. A '' title
 * writes a blank row - present in the table, still counted as missing, which
 * is what an abandoned half-translation looks like.
 *
 * @param  array<string, string>  $translations  locale => title
 */
function finCodexBulkArticle(string $slug, array $translations): Article
{
    $article = Article::factory()->public()->published()->markdown()->create(['slug' => $slug]);

    foreach ($translations as $locale => $title) {
        ArticleTranslation::factory()->create([
            'article_id' => $article->id,
            'locale' => $locale,
            'title' => $title,
            'body' => $title === '' ? '' : $title.' body',
        ]);
    }

    return $article;
}

/** The mounted bulk modal's live language CheckboxList. */
function finCodexBulkLocaleList(Testable $component): CheckboxList
{
    $page = $component->instance();
    $schema = $page->getSchema((string) $page->getMountedActionSchemaName());
    $locales = $schema?->getFlatFields()['locales'] ?? null;

    expect($locales)->toBeInstanceOf(CheckboxList::class);

    /** @var CheckboxList $locales */
    return $locales;
}

/**
 * The mounted bulk modal's language options, code => display, read off the
 * live CheckboxList rather than out of the rendered HTML: the list's own
 * flag tooltips already carry every display name.
 *
 * @return array<string, string>
 */
function finCodexBulkOptions(Testable $component): array
{
    return finCodexBulkLocaleList($component)->getOptions();
}

/**
 * The expected options for $codes, in that order, with the display names the
 * table itself reads.
 *
 * @param  list<string>  $codes
 *
 * @return array<string, string>
 */
function finCodexBulkDisplays(array $codes): array
{
    $displays = array_column(TranslationTabs::languages()['languages'], 'display', 'code');

    $expected = [];

    foreach ($codes as $code) {
        $expected[$code] = (string) $displays[$code];
    }

    return $expected;
}

/** The mounted bulk action's modal description. */
function finCodexBulkDescription(Testable $component): string
{
    $description = $component->instance()->getMountedAction()?->getModalDescription();

    return $description instanceof Htmlable ? $description->toHtml() : (string) $description;
}

/**
 * The summary toast the press is expected to send: two numbers always, the
 * not-permitted clause as a third sentence only when there is one.
 */
function finCodexBulkToast(int $queued, int $nothing, int $notPermitted = 0): Notification
{
    $body = (string) __('fin-codex::fin-codex.editor.translate_missing.bulk_summary', [
        'queued' => $queued,
        'nothing' => $nothing,
    ]);

    if ($notPermitted > 0) {
        $body .= ' '.trans_choice(
            'fin-codex::fin-codex.editor.translate_missing.bulk_not_permitted',
            $notPermitted,
            ['count' => $notPermitted],
        );
    }

    return Notification::make()
        ->success()
        ->title(__('fin-codex::fin-codex.editor.translate_missing.queued_title'))
        ->body($body);
}

beforeEach(function (): void {
    finCodexBulkUseLanguages();
    finCodexFakeAi();
    finCodexEnableAi();
    $this->usesPanel('admin', finCodexBulkUser());
});

it('is the table\'s first bulk action while AI is available and hidden otherwise', function (): void {
    finCodexBulkArticle('billing', ['en' => 'Billing']);

    $component = Livewire::test(ListArticles::class)
        ->assertTableBulkActionExists('translate_missing')
        ->assertTableBulkActionVisible('translate_missing')
        ->assertTableBulkActionDoesNotExist('delete');

    expect($component->html())->toContain('fi-ta-selection-cell');

    finCodexEnableAi(['enabled' => false]);

    $off = Livewire::test(ListArticles::class)
        ->assertTableBulkActionHidden('translate_missing');

    expect($off->html())->not->toContain('fi-ta-selection-cell');
});

it('offers every configured non-default language pre-checked, in settings order', function (): void {
    $gapDe = finCodexBulkArticle('billing', ['en' => 'Billing', 'hu' => 'Számlázás']);
    $gapBoth = finCodexBulkArticle('users', ['en' => 'Users']);

    $component = Livewire::test(ListArticles::class)
        ->mountTableBulkAction('translate_missing', [$gapDe, $gapBoth])
        ->assertTableBulkActionDataSet(['locales' => ['de', 'hu']]);

    expect(finCodexBulkOptions($component))->toBe(finCodexBulkDisplays(['de', 'hu']));

    finCodexBulkUseLanguages(['en', 'hu', 'de']);

    $reordered = Livewire::test(ListArticles::class)
        ->mountTableBulkAction('translate_missing', [$gapDe, $gapBoth])
        ->assertTableBulkActionDataSet(['locales' => ['hu', 'de']]);

    expect(finCodexBulkOptions($reordered))->toBe(finCodexBulkDisplays(['hu', 'de']))
        ->and(finCodexBulkOptions($reordered))->not->toHaveKey('en');
});

it('lays the languages out in three columns rather than one tall one', function (): void {
    $gapBoth = finCodexBulkArticle('users', ['en' => 'Users']);

    $locales = finCodexBulkLocaleList(
        Livewire::test(ListArticles::class)->mountTableBulkAction('translate_missing', [$gapBoth]),
    );

    // An integer count is a large-breakpoint one and Filament's own grid
    // helper defaults every smaller breakpoint to one column, so a narrow
    // modal on a phone still gets the single column it has room for.
    expect($locales->getColumns('lg'))->toBe(3)
        ->and($locales->getColumns())->toBe(['lg' => 3]);
});

it('names how many articles are selected and says the work runs in the background', function (): void {
    $gapDe = finCodexBulkArticle('billing', ['en' => 'Billing', 'hu' => 'Számlázás']);
    $gapBoth = finCodexBulkArticle('users', ['en' => 'Users']);

    $two = finCodexBulkDescription(
        Livewire::test(ListArticles::class)->mountTableBulkAction('translate_missing', [$gapDe, $gapBoth]),
    );

    expect($two)->toBe(trans_choice('fin-codex::fin-codex.editor.translate_missing.bulk_description', 2, ['count' => 2]))
        ->and($two)->toContain('2 articles are selected')
        ->and($two)->toContain('runs in the background');

    $one = finCodexBulkDescription(
        Livewire::test(ListArticles::class)->mountTableBulkAction('translate_missing', [$gapDe]),
    );

    expect($one)->toBe(trans_choice('fin-codex::fin-codex.editor.translate_missing.bulk_description', 1, ['count' => 1]))
        ->and($one)->toContain('One article is selected')
        ->and($one)->toContain('runs in the background');
});

it('queues one job per article for the ticked languages it lacks and reports the two counts', function (): void {
    Queue::fake();

    $user = finCodexBulkUser();
    $full = finCodexBulkArticle('billing', ['en' => 'Billing', 'de' => 'Abrechnung', 'hu' => 'Számlázás']);
    $gapDe = finCodexBulkArticle('users', ['en' => 'Users', 'hu' => 'Felhasználók']);
    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$full, $gapDe, $gapBoth], data: ['locales' => ['de']])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified(finCodexBulkToast(queued: 2, nothing: 1));

    Queue::assertPushed(TranslateArticle::class, 2);

    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $gapDe->id
        && $job->locales === ['de']
        && $job->userId === $user->id);

    // hu was not ticked, so the article that lacks both still gets only de.
    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $gapBoth->id
        && $job->locales === ['de']
        && $job->userId === $user->id);

    Queue::assertNotPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $full->id);
});

it('records the language the panel is being read in once for the press, not once per article', function (): void {
    Queue::fake();

    // The panel is read in German while the application is configured in
    // English; the worker that runs the jobs has neither session nor request.
    app()->setLocale('de');

    $full = finCodexBulkArticle('billing', ['en' => 'Billing', 'de' => 'Abrechnung', 'hu' => 'Számlázás']);
    $gapDe = finCodexBulkArticle('users', ['en' => 'Users', 'hu' => 'Felhasználók']);
    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    // A press that queues nothing at all still records it: the language is a
    // fact about the request, written before the loop, and not something a
    // dispatch carries in.
    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$full], data: ['locales' => ['de', 'hu']])
        ->assertHasNoTableBulkActionErrors();

    Queue::assertNothingPushed();

    expect(Context::get(NotificationLocale::KEY))->toBe('de');

    // And a press that queues two jobs writes it once, not twice: two
    // articles are two jobs, but one language and one request.
    Context::spy();

    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$gapDe, $gapBoth], data: ['locales' => ['de', 'hu']])
        ->assertHasNoTableBulkActionErrors();

    Queue::assertPushed(TranslateArticle::class, 2);

    Context::shouldHaveReceived('add', [NotificationLocale::KEY, 'de'])->once();
});

it('records the panel the press was made on once for the press', function (): void {
    Queue::fake();

    $gapDe = finCodexBulkArticle('users', ['en' => 'Users', 'hu' => 'Felhasználók']);
    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    expect(Context::get(NotificationPanel::KEY))->toBeNull();

    Context::spy();

    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$gapDe, $gapBoth], data: ['locales' => ['de', 'hu']])
        ->assertHasNoTableBulkActionErrors();

    Queue::assertPushed(TranslateArticle::class, 2);

    // Two articles are two jobs, but one panel and one request: the listener
    // reads it back on a worker that has no panel of its own.
    Context::shouldHaveReceived('add', [NotificationPanel::KEY, 'admin'])->once();
});

it('raises a short execution limit by the timeout of every language the press can queue', function (): void {
    Queue::fake();

    $gapDe = finCodexBulkArticle('users', ['en' => 'Users', 'hu' => 'Felhasználók']);
    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    $original = ini_get('max_execution_time');

    try {
        // A wall-clock host with the stock 30-second limit: on the sync
        // driver every job this press queues runs inline in the request.
        ini_set('max_execution_time', '30');

        Livewire::test(ListArticles::class)
            ->callTableBulkAction('translate_missing', [$gapDe, $gapBoth], data: ['locales' => ['de', 'hu']])
            ->assertHasNoTableBulkActionErrors();

        // The ceiling of the press - two articles times two ticked languages
        // at the configured 120 seconds - plus the helper's own 30 seconds.
        // One of the four is skipped, and a limit is never lowered anyway.
        expect((int) ini_get('max_execution_time'))->toBe(2 * 2 * 120 + 30);
    } finally {
        // Back to the CLI default, or the rest of the suite runs on a clock.
        set_time_limit(is_string($original) ? (int) $original : 0);
    }

    Queue::assertPushed(TranslateArticle::class, 2);
});

it('fills every gap when the pick is left as it is', function (): void {
    Queue::fake();

    $gapDe = finCodexBulkArticle('users', ['en' => 'Users', 'hu' => 'Felhasználók']);
    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    Livewire::test(ListArticles::class)
        ->mountTableBulkAction('translate_missing', [$gapDe, $gapBoth])
        ->callMountedTableBulkAction()
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified(finCodexBulkToast(queued: 2, nothing: 0));

    Queue::assertPushed(TranslateArticle::class, 2);

    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $gapDe->id
        && $job->locales === ['de']);

    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $gapBoth->id
        && $job->locales === ['de', 'hu']);
});

it('queues nothing for a selection that lacks nothing', function (): void {
    Queue::fake();

    $full = finCodexBulkArticle('billing', ['en' => 'Billing', 'de' => 'Abrechnung', 'hu' => 'Számlázás']);

    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$full], data: ['locales' => ['de', 'hu']])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified(finCodexBulkToast(queued: 0, nothing: 1));

    Queue::assertNothingPushed();
});

it('refuses an empty pick and queues nothing', function (): void {
    Queue::fake();

    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$gapBoth], data: ['locales' => []])
        ->assertHasTableBulkActionErrors(['locales' => 'required']);

    Queue::assertNothingPushed();

    expect(__('fin-codex::fin-codex.editor.translate_missing.pick_one'))->toBe('Tick at least one language.');
});

it('skips and counts the articles the admin may not update', function (): void {
    Queue::fake();

    $full = finCodexBulkArticle('billing', ['en' => 'Billing', 'de' => 'Abrechnung', 'hu' => 'Számlázás']);
    $gapDe = finCodexBulkArticle('users', ['en' => 'Users', 'hu' => 'Felhasználók']);
    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    // The policy is swapped after the mount, which is also the realistic
    // story: a permission revoked while the admin has the list open.
    $component = Livewire::test(ListArticles::class);

    Gate::policy(Article::class, FinCodexBulkNoUpdatePolicy::class);

    $component
        ->callTableBulkAction('translate_missing', [$gapDe, $gapBoth, $full], data: ['locales' => ['de', 'hu']])
        ->assertNotified(finCodexBulkToast(queued: 0, nothing: 0, notPermitted: 3));

    Queue::assertNothingPushed();

    // The article that lacks nothing is counted as not permitted, not as
    // needing nothing: the ability is asked first, before the gaps.
    expect(trans_choice('fin-codex::fin-codex.editor.translate_missing.bulk_not_permitted', 3, ['count' => 3]))
        ->toBe('3 articles were skipped because you may not update them.');
});

it('counts the one article it may not update and queues the rest', function (): void {
    Queue::fake();

    $user = finCodexBulkUser();
    $locked = finCodexBulkArticle('locked', ['en' => 'Locked']);
    $gapBoth = finCodexBulkArticle('zebra', ['en' => 'Zebra']);

    $component = Livewire::test(ListArticles::class);

    Gate::policy(Article::class, FinCodexBulkLockedSlugPolicy::class);

    $component
        ->callTableBulkAction('translate_missing', [$locked, $gapBoth], data: ['locales' => ['de', 'hu']])
        ->assertNotified(finCodexBulkToast(queued: 1, nothing: 0, notPermitted: 1));

    Queue::assertPushed(TranslateArticle::class, 1);

    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $gapBoth->id
        && $job->locales === ['de', 'hu']
        && $job->userId === $user->id);

    Queue::assertNotPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $locked->id);

    expect(trans_choice('fin-codex::fin-codex.editor.translate_missing.bulk_not_permitted', 1, ['count' => 1]))
        ->toBe('One article was skipped because you may not update it.');
});

it('reads the gaps off the loaded translations, with no query per article', function (): void {
    Queue::fake();

    $first = finCodexBulkArticle('alpha', ['en' => 'Alpha']);
    $second = finCodexBulkArticle('beta', ['en' => 'Beta']);
    $third = finCodexBulkArticle('gamma', ['en' => 'Gamma']);

    $translations = app(ArticleTranslation::class)->getTable();

    /**
     * Every statement the press issues against the translations table, over a
     * page that holds the same three rows whatever the selection is.
     *
     * @param  list<Article>  $selection
     *
     * @return list<string>
     */
    $reads = function (array $selection) use ($translations): array {
        $component = Livewire::test(ListArticles::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $component->callTableBulkAction('translate_missing', $selection, data: ['locales' => ['de', 'hu']]);

        $queries = array_map(fn (array $entry): string => (string) $entry['query'], DB::getQueryLog());

        DB::disableQueryLog();

        return array_values(array_filter($queries, fn (string $query): bool => str_contains($query, $translations)));
    };

    $one = $reads([$first]);
    $three = $reads([$first, $second, $third]);

    // The render is identical either way, so the only thing that could grow
    // with the selection is the loop - and it does not.
    expect($one)->not->toBeEmpty()
        ->and($three)->toHaveCount(count($one));

    // Every read is a set: MissingTranslations::for() on an article whose
    // relation was NOT loaded would issue "article_id" = ? once per row.
    foreach ($three as $query) {
        expect($query)->not->toContain('"article_id" = ');
    }

    Queue::assertPushed(TranslateArticle::class, 4);
});

it('treats a blank default language like any other article', function (): void {
    Queue::fake();

    $blank = finCodexBulkArticle('blank', ['en' => '']);

    Livewire::test(ListArticles::class)
        ->callTableBulkAction('translate_missing', [$blank], data: ['locales' => ['de', 'hu']])
        ->assertNotified(finCodexBulkToast(queued: 1, nothing: 0));

    Queue::assertPushed(TranslateArticle::class, 1);

    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $blank->id
        && $job->locales === ['de', 'hu']);
});
