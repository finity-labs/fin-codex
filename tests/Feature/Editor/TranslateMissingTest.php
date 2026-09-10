<?php

use FinityLabs\FinCodex\Ai\NotificationLocale;
use FinityLabs\FinCodex\Ai\NotificationPanel;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Jobs\TranslateArticle;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * AIBULK-01 and the row half of AIBULK-04: Translate missing, the action
 * beside Edit on the article list.
 *
 * Every row drives ListArticles, so what is asserted is what the admin gets:
 * the button is resolved out of the live table and pressed through the page.
 * The seam is the fake AiClient bound by finCodexFakeAi() plus the stored AI
 * settings, which is the whole availability wiring.
 *
 * A hidden TABLE row action still resolves - the opposite of the schema
 * actions on the language tabs - so every negative row asserts it is hidden
 * rather than that it does not exist.
 *
 * The queue is faked in every row that presses the button: the harness runs
 * on the sync driver, so an unfaked press would translate inline through the
 * job and prove something else entirely. The sync chain is 11-06's subject.
 */

/**
 * A host policy that lets an admin read the list but never edit an article.
 *
 * The forged press needs one: the deny-all policy refuses viewAny too, so the
 * list page 403s on the request that carries the press and the component is
 * gone before the action is reached. This one leaves the page open and denies
 * exactly the ability the action gates on.
 */
class FinCodexMissingReadOnlyPolicy
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
        return false;
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, Article $article): bool
    {
        return false;
    }
}

/** A fixture user signed in on the admin panel. */
function finCodexMissingUser(string $name = 'Missing'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * The languages this file's articles are written in. Its own helper rather
 * than a borrowed one: a Pest helper is loaded with its file, so a helper
 * from another test file is undefined the moment this one runs alone.
 *
 * @param  list<string>  $codes
 */
function finCodexMissingUseLanguages(array $codes = ['en', 'de', 'hu'], string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/**
 * One translation row, with a filled title and body unless overridden.
 *
 * @param  array<string, mixed>  $fields
 */
function finCodexMissingTranslate(Article $article, string $locale, array $fields = []): ArticleTranslation
{
    return ArticleTranslation::factory()->create(array_merge([
        'article_id' => $article->id,
        'locale' => $locale,
        'title' => strtoupper($locale).' '.$article->slug,
        'body' => strtoupper($locale).' body',
    ], $fields));
}

/**
 * A Markdown article with one translation row per locale => fields pair.
 *
 * @param  array<string, array<string, mixed>>  $translations
 */
function finCodexMissingArticle(string $slug, array $translations = []): Article
{
    $article = Article::factory()->public()->published()->markdown()->create(['slug' => $slug]);

    foreach ($translations as $locale => $fields) {
        finCodexMissingTranslate($article, $locale, $fields);
    }

    return $article;
}

/** The configured display name of one language, as the settings hold it. */
function finCodexMissingDisplay(string $code): string
{
    $names = array_column(TranslationTabs::languages()['languages'], 'display', 'code');

    return $names[$code] ?? $code;
}

/**
 * How many settings queries one whole list render costs. The log is flushed
 * first so a seed written by the row before is never counted.
 */
function finCodexMissingSettingsQueries(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListArticles::class);

    $queries = DB::getQueryLog();

    DB::disableQueryLog();

    return count(array_filter(
        $queries,
        static fn (array $entry): bool => str_contains((string) ($entry['query'] ?? ''), 'settings'),
    ));
}

beforeEach(function (): void {
    finCodexMissingUseLanguages();
    finCodexFakeAi();
    finCodexEnableAi();

    $this->admin = finCodexMissingUser();
    $this->usesPanel('admin', $this->admin);

    // en only: lacks de and hu.
    $this->gap = finCodexMissingArticle('gap', ['en' => []]);
    // en + de: lacks hu alone.
    $this->gapHu = finCodexMissingArticle('gap-hu', ['en' => [], 'de' => []]);
    // Nothing missing at all.
    $this->full = finCodexMissingArticle('full', ['en' => [], 'de' => [], 'hu' => []]);
    // The default language has a body but no title: nothing to translate from.
    $this->blank = finCodexMissingArticle('blank', ['en' => ['title' => '']]);
});

it('shows Translate missing beside Edit on an article that lacks a language and hides it on a full one', function (): void {
    Livewire::test(ListArticles::class)
        ->assertTableActionVisible('translate_missing', $this->gap)
        ->assertTableActionVisible('translate_missing', $this->gapHu)
        ->assertTableActionHidden('translate_missing', $this->full)
        // A blank default language is not a reason to hide it; the modal
        // explains that one instead.
        ->assertTableActionVisible('translate_missing', $this->blank)
        ->assertTableActionExists('edit', record: $this->gap)
        ->assertTableActionsExistInOrder(['edit', 'translate_missing']);
});

it('pre-checks exactly the missing languages, in settings order', function (): void {
    Livewire::test(ListArticles::class)
        ->mountTableAction('translate_missing', $this->gap)
        ->assertTableActionDataSet(['locales' => ['de', 'hu']]);

    Livewire::test(ListArticles::class)
        ->mountTableAction('translate_missing', $this->gapHu)
        ->assertTableActionDataSet(['locales' => ['hu']]);

    // Settings order, not alphabetical: reorder the languages and the pick
    // follows. A fresh component, because the table is built once per mount.
    finCodexMissingUseLanguages(['en', 'hu', 'de']);

    Livewire::test(ListArticles::class)
        ->mountTableAction('translate_missing', $this->gap)
        ->assertTableActionDataSet(['locales' => ['hu', 'de']]);
});

it('lists the missing languages by their configured display names and says the work is queued', function (): void {
    $component = Livewire::test(ListArticles::class)
        ->mountTableAction('translate_missing', $this->gap);

    $action = $component->instance()->getMountedAction();
    $checkboxes = $component->instance()->getSchema('mountedActionSchema0')?->getComponent('locales');

    expect($action)->not->toBeNull()
        ->and($action->getModalDescription())
        ->toBe(__('fin-codex::fin-codex.editor.translate_missing.description'))
        ->and((string) $action->getModalHeading())->toContain('EN gap')
        ->and($checkboxes)->not->toBeNull()
        ->and($checkboxes->getOptions())->toBe([
            'de' => finCodexMissingDisplay('de'),
            'hu' => finCodexMissingDisplay('hu'),
        ])
        // Three columns, as in the bulk modal: the two modals look alike. An
        // integer count is a large-breakpoint one and Filament's own grid
        // helper defaults every smaller breakpoint to one column, so a narrow
        // modal on a phone still gets the single column it has room for.
        ->and($checkboxes->getColumns('lg'))->toBe(3)
        ->and($checkboxes->getColumns())->toBe(['lg' => 3]);
});

it('hides it for each unavailable state', function (Closure $state): void {
    $state();

    Livewire::test(ListArticles::class)
        ->assertTableActionHidden('translate_missing', $this->gap)
        ->assertTableActionExists('edit', record: $this->gap);
})->with([
    'sdk_missing' => [fn (): FakeAiClient => finCodexFakeAi(new FakeAiClient(installed: false))],
    'disabled' => [function (): void {
        finCodexFakeAi();
        finCodexEnableAi(['enabled' => false]);
    }],
    'no_key' => [function (): void {
        finCodexFakeAi();
        finCodexEnableAi(['provider' => null]);
    }],
    // Last, because spatie loads the group on the first write: enabling AI on
    // rows that were just deleted throws instead of enabling anything.
    'not_migrated' => [function (): void {
        finCodexFakeAi();
        finCodexAiUnseed();
    }],
]);

it('takes it away from a user the policy refuses', function (): void {
    // The policy is swapped after the mount: a deny-all policy in force from
    // the start 403s the list page itself, so the mount would fail for the
    // wrong reason.
    $component = Livewire::test(ListArticles::class)
        ->assertTableActionVisible('translate_missing', $this->gap);

    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    $component->assertTableActionHidden('translate_missing', $this->gap);
});

it('issues the settings reads once per table build, not per row', function (): void {
    foreach (['row-a', 'row-b', 'row-c', 'row-d'] as $slug) {
        finCodexMissingArticle($slug, ['en' => []]);
    }

    Livewire::test(ListArticles::class)->assertTableActionVisible('translate_missing', $this->gap);

    $on = finCodexMissingSettingsQueries();

    finCodexEnableAi(['enabled' => false]);

    $off = finCodexMissingSettingsQueries();

    // The memoised candidates() costs two settings reads on the first row and
    // nothing after; without lin-codex's memo four extra rows would cost eight.
    expect($on)->toBeLessThanOrEqual($off + 3);
});

it('queues one job for the ticked languages and the signed-in admin', function (): void {
    Queue::fake();

    Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $this->gap, data: ['locales' => ['hu']])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('fin-codex::fin-codex.editor.translate_missing.queued_title'));

    Queue::assertPushed(TranslateArticle::class, 1);
    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $this->gap->id
        && $job->locales === ['hu']
        && $job->userId === $this->admin->id);
});

it('records the language the panel is being read in with the press', function (): void {
    Queue::fake();

    // The panel is read in German while the application is configured in
    // English. A language switcher keeps that choice in the session, and the
    // worker that runs the job has neither session nor request, so the press
    // is the only place the language can be picked up.
    app()->setLocale('de');

    expect(Context::get(NotificationLocale::KEY))->toBeNull();

    Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $this->gap, data: ['locales' => ['hu']])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(TranslateArticle::class, 1);

    // Laravel serialises the context into the payload it just wrote and
    // restores it on the worker, which is how the listener gets to read it.
    expect(Context::get(NotificationLocale::KEY))->toBe('de');
});

it('records the panel the press was made on with the press', function (): void {
    Queue::fake();

    // The worker that runs the job has no current panel, so a press on a
    // panel that is not the host's default one is the only place the panel
    // can be picked up: the listener needs it for the guard it reads the
    // admin through and for the edit page it links.
    expect(Context::get(NotificationPanel::KEY))->toBeNull();

    Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $this->gap, data: ['locales' => ['hu']])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(TranslateArticle::class, 1);

    expect(Context::get(NotificationPanel::KEY))->toBe('admin');
});

it('raises a short execution limit by the timeout of every language it queues', function (): void {
    Queue::fake();

    $original = ini_get('max_execution_time');

    try {
        // A wall-clock host with the stock 30-second limit: on the sync
        // driver the two calls this press sets off would be killed halfway.
        ini_set('max_execution_time', '30');

        Livewire::test(ListArticles::class)
            ->callTableAction('translate_missing', $this->gap, data: ['locales' => ['de', 'hu']])
            ->assertHasNoTableActionErrors();

        // Two languages at the configured 120 seconds, plus the helper's own
        // 30 seconds of headroom - the tab action's arithmetic for one call.
        expect((int) ini_get('max_execution_time'))->toBe(2 * 120 + 30);
    } finally {
        // Back to the CLI default, or the rest of the suite runs on a clock.
        set_time_limit(is_string($original) ? (int) $original : 0);
    }

    Queue::assertPushed(TranslateArticle::class, 1);
});

it('queues every pre-checked language when the pick is left as it is', function (): void {
    Queue::fake();

    Livewire::test(ListArticles::class)
        ->mountTableAction('translate_missing', $this->gap)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(TranslateArticle::class, 1);
    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $this->gap->id
        && $job->locales === ['de', 'hu']
        && $job->userId === $this->admin->id);
});

it('refuses an empty pick with a message and queues nothing', function (): void {
    Queue::fake();

    $component = Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $this->gap, data: ['locales' => []])
        ->assertHasTableActionErrors(['locales' => 'required']);

    // The message the admin reads, not just the rule that fired. It lives in
    // the error bag rather than in the rendered page: Filament's modal body
    // is rendered inside the modal, which this build only paints once it is
    // open in the browser.
    expect($component->errors()->get('mountedActions.0.data.locales'))
        ->toBe([__('fin-codex::fin-codex.editor.translate_missing.pick_one')]);

    Queue::assertNothingPushed();

    // The refused action stays mounted, so the second press needs a fresh
    // component: calling it again on this one throws instead of pressing.
    Livewire::test(ListArticles::class)
        ->callTableAction('translate_missing', $this->gap, data: ['locales' => ['de']])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(TranslateArticle::class, 1);
});

it('drops a ticked language that was filled in the meantime', function (): void {
    Queue::fake();

    $component = Livewire::test(ListArticles::class)
        ->mountTableAction('translate_missing', $this->gap)
        ->assertTableActionDataSet(['locales' => ['de', 'hu']]);

    finCodexMissingTranslate($this->gap, 'hu');

    $component->callMountedTableAction()->assertHasNoTableActionErrors();

    Queue::assertPushed(TranslateArticle::class, 1);
    Queue::assertPushed(TranslateArticle::class, fn (TranslateArticle $job): bool => $job->articleId === $this->gap->id
        && $job->locales === ['de']);
});

it('stays visible on a blank default language and explains it in the modal instead', function (array $en): void {
    Queue::fake();

    $article = finCodexMissingArticle('blank-source', ['en' => $en]);

    $component = Livewire::test(ListArticles::class)
        ->assertTableActionVisible('translate_missing', $article)
        ->mountTableAction('translate_missing', $article);

    $action = $component->instance()->getMountedAction();

    expect($action)->not->toBeNull()
        ->and($action->shouldOpenModal())->toBeTrue()
        ->and($action->getModalDescription())->toBe(__(
            'fin-codex::fin-codex.editor.translate_missing.blank_source',
            ['language' => finCodexMissingDisplay('en')],
        ))
        ->and($action->getModalSubmitAction())->toBeNull();

    $component->callMountedTableAction()
        ->assertNotNotified(__('fin-codex::fin-codex.editor.translate_missing.queued_title'));

    Queue::assertNothingPushed();
})->with([
    'no title' => [['title' => '']],
    'no body' => [['body' => '']],
]);

it('queues nothing on a forged press from a refused user', function (): void {
    Queue::fake();

    $component = Livewire::test(ListArticles::class)
        ->assertTableActionVisible('translate_missing', $this->gap);

    Gate::policy(Article::class, FinCodexMissingReadOnlyPolicy::class);

    // Not callTableAction(): that asserts the action is visible first, which
    // is the thing being denied. Filament answers the forged press by
    // refusing the mount, so nothing is ever called.
    $component->mountTableAction('translate_missing', $this->gap)->callMountedTableAction();

    Queue::assertNothingPushed();
});
