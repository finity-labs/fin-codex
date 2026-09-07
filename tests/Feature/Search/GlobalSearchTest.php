<?php

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Filament\Panel;
use Filament\Support\View\ViewManager;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Search\HelpSearchProvider;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Search\HostGlobalSearchProvider;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Auth\ViewerResolver;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Rendering\ArticlePath;
use FinityLabs\LinCodex\Search\Searcher;
use FinityLabs\LinCodex\Search\SearchHit;

/*
 * GS-01 and GS-02. Help reaches the panel's own search field through one
 * path only — the plugin's wrapper around whatever provider the panel had,
 * asking lin-codex's Searcher, which gates before it reads. The Eloquent
 * path the resource would otherwise open is closed here, so "global search
 * off" genuinely means no help results at all.
 */

function finCodexSearchUser(string $email = 'searcher@example.test'): User
{
    return User::create(['name' => 'Searcher', 'email' => $email]);
}

/**
 * Three articles whose slugs and titles all match "users": a public one, an
 * unpublished one and an Authenticated-visibility one. The last two are the
 * rows that light up if anything queries the model instead of the gate.
 */
function finCodexSearchSeedUsers(): void
{
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Users overview', 'body' => 'How users work in this panel.'])
        ->create(['slug' => 'users-overview']);

    Article::factory()->public()->unpublished()
        ->withTranslation('en', ['title' => 'Users draft', 'body' => 'An unfinished note about users.'])
        ->create(['slug' => 'users-draft']);

    Article::factory()->authenticated()->published()
        ->withTranslation('en', ['title' => 'Users internal', 'body' => 'Internal guidance about users.'])
        ->create(['slug' => 'users-internal']);
}

/**
 * The category names in insertion order.
 *
 * @return list<string>
 */
function finCodexSearchCategoryNames(?GlobalSearchResults $results): array
{
    return $results === null ? [] : array_map(strval(...), $results->getCategories()->keys()->all());
}

/**
 * Every result of every category, flattened.
 *
 * @return list<GlobalSearchResult>
 */
function finCodexSearchAllResults(?GlobalSearchResults $results): array
{
    $flat = [];

    foreach (($results?->getCategories() ?? []) as $category) {
        foreach ($category as $result) {
            $flat[] = $result;
        }
    }

    return $flat;
}

/**
 * One category's results as a list.
 *
 * @return list<GlobalSearchResult>
 */
function finCodexSearchCategory(?GlobalSearchResults $results, string $name): array
{
    $category = $results?->getCategories()->get($name);

    return $category === null ? [] : array_values(is_array($category) ? $category : $category->all());
}

it('never offers the article resource to a default global search provider', function (): void {
    $this->usesPanel('admin', finCodexSearchUser());

    expect(ArticleResource::canGloballySearch())->toBeFalse()
        ->and(AdminHelpArticleResource::canGloballySearch())->toBeFalse();
});

/*
 * The admin panel's plugin has globalSearch(false), so nothing of ours runs
 * here at all. What the panel's own provider answers must therefore contain
 * no help whatsoever — not the resource's plural label as a category, and no
 * row linking at the editor.
 */
it('produces no help category on a panel with the option off', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('admin', finCodexSearchUser());

    $results = Filament::getGlobalSearchProvider()?->getResults('users');

    expect(finCodexSearchCategoryNames($results))
        ->not->toContain(AdminHelpArticleResource::getPluralModelLabel())
        ->not->toContain((string) __('fin-codex::fin-codex.search.category'));

    foreach (finCodexSearchAllResults($results) as $result) {
        expect($result->url)->not->toContain('/admin/help-articles');
    }
});

/*
 * The same fact framed as the leak it would be: an Authenticated article
 * seeded while nobody is signed in is invisible to the panel's search.
 */
it('never exposes an authenticated article through the panel search to a guest', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('admin');

    expect(auth('web')->check())->toBeFalse();

    $titles = array_map(
        static fn (GlobalSearchResult $result): string => (string) $result->title,
        finCodexSearchAllResults(Filament::getGlobalSearchProvider()?->getResults('users')),
    );

    expect($titles)->not->toContain('users-internal')
        ->not->toContain('Users internal')
        ->not->toContain('users-draft')
        ->not->toContain('Users draft')
        ->not->toContain('users-overview');
});

/*
 * GS-02, tested on the wrapper itself so that its wiring into the panel
 * stays a separate question. The staff panel is the one whose plugin has
 * globalSearch() on.
 */

function finCodexSearchHelpCategory(): string
{
    return (string) __('fin-codex::fin-codex.search.category');
}

/**
 * The wrapper over the fixture host provider (or over $inner), asked once.
 */
function finCodexSearchWrapped(string $query, ?GlobalSearchProvider $inner = null): ?GlobalSearchResults
{
    return (new HelpSearchProvider($inner ?? new HostGlobalSearchProvider))->getResults($query);
}

/**
 * The Help category's results, as a list.
 *
 * @return list<GlobalSearchResult>
 */
function finCodexSearchHelpResults(?GlobalSearchResults $results): array
{
    return finCodexSearchCategory($results, finCodexSearchHelpCategory());
}

/**
 * @param  list<GlobalSearchResult>  $results
 *
 * @return list<string>
 */
function finCodexSearchTitles(array $results): array
{
    return array_map(static fn (GlobalSearchResult $result): string => (string) $result->title, $results);
}

it('delegates to the panel provider and appends Help last', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('staff', finCodexSearchUser());

    $results = finCodexSearchWrapped('users');
    $names = finCodexSearchCategoryNames($results);

    expect($names)->toBe([HostGlobalSearchProvider::CATEGORY, finCodexSearchHelpCategory()])
        ->and(end($names))->toBe(finCodexSearchHelpCategory());

    $shop = finCodexSearchCategory($results, HostGlobalSearchProvider::CATEGORY);

    expect($shop)->toHaveCount(1)
        ->and((string) $shop[0]->title)->toBe('Shop result for users')
        ->and($shop[0]->url)->toBe('/shop');
});

it('passes a null answer from the panel provider straight through', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('staff', finCodexSearchUser());

    $silent = new class implements GlobalSearchProvider
    {
        public function getResults(string $query): ?GlobalSearchResults
        {
            return null;
        }
    };

    expect(finCodexSearchWrapped('users', $silent))->toBeNull();
});

/*
 * The hits are Searcher's, not a query of our own: the expectation is
 * computed from the service with the panel's viewer and the wrapper's own
 * limit, so a hand-rolled LIKE could not satisfy it.
 */
it('takes the help results from Searcher with the panel guard viewer', function (): void {
    finCodexSearchSeedUsers();

    $panel = $this->usesPanel('staff', finCodexSearchUser());

    $viewer = app(ViewerResolver::class)->resolve($panel->getAuthGuard());
    $expected = array_map(
        static fn (SearchHit $hit): string => $hit->title,
        app(Searcher::class)->search('users', $viewer, null, 5)->hits,
    );

    expect($expected)->not->toBeEmpty();

    expect(finCodexSearchTitles(finCodexSearchHelpResults(finCodexSearchWrapped('users'))))->toBe($expected);
});

it('keeps an authenticated article out of a guest search and in a member one', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('staff');

    expect(auth('staff')->check())->toBeFalse();

    $guest = finCodexSearchTitles(finCodexSearchHelpResults(finCodexSearchWrapped('users')));

    expect($guest)->toContain('Users overview')
        ->not->toContain('Users internal')
        ->not->toContain('Users draft');

    $this->usesPanel('staff', finCodexSearchUser());

    $member = finCodexSearchTitles(finCodexSearchHelpResults(finCodexSearchWrapped('users')));

    expect($member)->toContain('Users overview')
        ->toContain('Users internal')
        ->not->toContain('Users draft');
});

it('shows at most five help results in the dropdown', function (): void {
    foreach (range(1, 7) as $n) {
        Article::factory()->public()->published()
            ->withTranslation('en', ['title' => 'Widgets chapter '.$n, 'body' => 'All about widgets, part '.$n.'.'])
            ->create(['slug' => 'widgets-'.$n]);
    }

    $this->usesPanel('staff', finCodexSearchUser());

    expect(finCodexSearchHelpResults(finCodexSearchWrapped('widgets')))->toHaveCount(5);
});

it('adds no help category when the search is rate limited', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('staff', finCodexSearchUser());

    config(['lin-codex.search.rate_limit.user' => 1]);

    expect(finCodexSearchHelpResults(finCodexSearchWrapped('users')))->not->toBeEmpty();

    $throttled = finCodexSearchWrapped('users');

    expect(finCodexSearchCategoryNames($throttled))->toBe([HostGlobalSearchProvider::CATEGORY])
        ->and(finCodexSearchCategory($throttled, HostGlobalSearchProvider::CATEGORY))->toHaveCount(1);
});

it('adds no help category when nothing matches', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('staff', finCodexSearchUser());

    $results = finCodexSearchWrapped('zzzqqqnothing');

    expect(finCodexSearchCategoryNames($results))->toBe([HostGlobalSearchProvider::CATEGORY])
        ->and(finCodexSearchHelpResults($results))->toBe([]);
});

/*
 * The row: the help-center URL, the section path as an unlabelled detail
 * (a list, so Filament prints no <dt>), and never the <mark>-carrying
 * snippet, whose markup would show as text in an escaped detail.
 */
it('builds the row from the article path and the section path', function (): void {
    $parent = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Guides', 'body' => 'The guide index.'])
        ->create(['slug' => 'guides']);

    Article::factory()->public()->published()->childOf($parent, 'zebra-handling')
        ->withTranslation('en', ['title' => 'Zebra handling', 'body' => 'How to handle a zebra.'])
        ->create();

    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Zebra overview', 'body' => 'A zebra at the top level.'])
        ->create(['slug' => 'zebra-overview']);

    $this->usesPanel('staff', finCodexSearchUser());

    $rows = [];

    foreach (finCodexSearchHelpResults(finCodexSearchWrapped('zebra')) as $result) {
        $rows[(string) $result->title] = $result;
    }

    expect($rows)->toHaveKeys(['Zebra handling', 'Zebra overview'])
        ->and($rows['Zebra handling']->url)->toBe(ArticlePath::href('guides/zebra-handling'))
        ->and($rows['Zebra handling']->details)->toBe(['Guides'])
        ->and(array_is_list($rows['Zebra handling']->details))->toBeTrue()
        ->and($rows['Zebra overview']->url)->toBe(ArticlePath::href('zebra-overview'))
        ->and($rows['Zebra overview']->details)->toBe([]);

    foreach ($rows as $result) {
        expect((string) $result->title)->not->toContain('<mark>');

        foreach ($result->details as $detail) {
            expect((string) $detail)->not->toContain('<mark>');
        }
    }
});

it('carries one open-here action that opens the drawer in place', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('staff', finCodexSearchUser());

    $rows = [];

    foreach (finCodexSearchHelpResults(finCodexSearchWrapped('users')) as $result) {
        $rows[(string) $result->title] = $result;
    }

    expect($rows)->toHaveKey('Users overview');

    $actions = $rows['Users overview']->actions;

    expect($actions)->toHaveCount(1);

    $action = $actions[0];

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getLabel())->toBe((string) __('fin-codex::fin-codex.search.open_here'))
        ->and($action->getUrl())->toBe(ArticlePath::href('users-overview'))
        ->and($action->getUrl())->toBe($rows['Users overview']->url)
        ->and($action->getAlpineClickHandler())
        ->toContain("document.querySelector('[data-codex-drawer]')")
        ->toContain('$event.preventDefault()')
        ->toContain("new CustomEvent('codex:open'")
        ->toContain('users-overview');
});

/*
 * The container extender is application-wide, so the wrapper can be reached
 * from a panel that never registered the plugin. FinCodexPlugin::get() would
 * throw a LogicException there.
 */
it('leaves a panel without the plugin exactly as its own provider answered', function (): void {
    finCodexSearchSeedUsers();

    $this->usesPanel('plain');

    $results = finCodexSearchWrapped('users');

    expect(finCodexSearchCategoryNames($results))->toBe([HostGlobalSearchProvider::CATEGORY])
        ->and(finCodexSearchCategory($results, HostGlobalSearchProvider::CATEGORY))->toHaveCount(1);
});

/*
 * GS-01's registration clause: the wrapper is installed per panel in
 * FinCodexPlugin::boot(), on the container and never on the Panel object.
 */

/**
 * The plugin instance the panel carries, for a second boot() call.
 */
function finCodexSearchPluginOf(Panel $panel): FinCodexPlugin
{
    $plugin = $panel->getPlugin('fin-codex');

    expect($plugin)->toBeInstanceOf(FinCodexPlugin::class);

    /** @var FinCodexPlugin $plugin */
    return $plugin;
}

it('wraps the panel provider on a panel whose plugin asked for it', function (): void {
    $panel = $this->usesPanel('staff', finCodexSearchUser());

    $provider = Filament::getGlobalSearchProvider();

    expect($provider)->toBeInstanceOf(HelpSearchProvider::class);

    /** @var HelpSearchProvider $provider */
    expect($provider->inner())->toBeInstanceOf(DefaultGlobalSearchProvider::class)
        ->and($provider->inner())->not->toBeInstanceOf(HelpSearchProvider::class)
        ->and($panel->getGlobalSearchProvider())->toBeInstanceOf(HelpSearchProvider::class);
});

it('leaves the provider alone on a panel whose plugin did not ask', function (string $panelId): void {
    $this->usesPanel($panelId, finCodexSearchUser());

    expect(Filament::getGlobalSearchProvider())
        ->toBeInstanceOf(DefaultGlobalSearchProvider::class)
        ->not->toBeInstanceOf(HelpSearchProvider::class);
})->with(['admin', 'portal']);

/*
 * Panel::globalSearch(false) — Filament's own setter, unrelated to the
 * plugin option of the same name — leaves the panel with no provider at
 * all, so there is nothing to wrap and nothing is registered.
 */
it('registers nothing when the panel has turned global search off entirely', function (): void {
    $panel = Filament::getPanel('staff');
    $panel->globalSearch(false);

    finCodexSearchPluginOf($panel)->boot($panel);

    expect($panel->getGlobalSearchProvider())->toBeNull()
        ->and(app(DefaultGlobalSearchProvider::class))->not->toBeInstanceOf(HelpSearchProvider::class);
});

it('wraps exactly once however often the panel boots', function (): void {
    $panel = $this->usesPanel('staff', finCodexSearchUser());

    finCodexSearchPluginOf($panel)->boot($panel);
    finCodexSearchPluginOf($panel)->boot($panel);

    $provider = Filament::getGlobalSearchProvider();

    expect($provider)->toBeInstanceOf(HelpSearchProvider::class);

    /** @var HelpSearchProvider $provider */
    expect($provider->inner())->not->toBeInstanceOf(HelpSearchProvider::class)
        ->and($provider->inner())->toBeInstanceOf(DefaultGlobalSearchProvider::class);
});

it('appends Help to a host provider through the real panel wiring', function (): void {
    finCodexSearchSeedUsers();

    $panel = $this->usesPanel('staff', finCodexSearchUser());
    $panel->globalSearch(HostGlobalSearchProvider::class);

    finCodexSearchPluginOf($panel)->boot($panel);

    $results = Filament::getGlobalSearchProvider()?->getResults('users');

    expect(finCodexSearchCategoryNames($results))
        ->toBe([HostGlobalSearchProvider::CATEGORY, finCodexSearchHelpCategory()])
        ->and(finCodexSearchTitles(finCodexSearchHelpResults($results)))->toContain('Users overview');
});

/*
 * The Phase 4 SPA exception is the regression the boot() split is really
 * about: it used to be the whole method body, and appending the global
 * search block after its early return would have skipped it on every
 * non-SPA panel.
 */
it('still appends the help-center SPA exception after the boot split', function (): void {
    Filament::getPanel('admin')->spa();

    $this->usesPanel('admin', finCodexSearchUser());

    $view = app(ViewManager::class);

    expect($view->hasSpaMode())->toBeTrue()
        ->and($view->hasSpaMode('/admin/users'))->toBeTrue()
        ->and($view->hasSpaMode('/help/users'))->toBeFalse();
});
