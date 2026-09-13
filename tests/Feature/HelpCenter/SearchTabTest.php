<?php

use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Js;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * CENTER-03: the rail's search — the field above the tab strip, the tab that
 * follows the query on its own, and the hits.
 *
 * Two mechanisms carry a trap each and both are asserted here rather than
 * described. The tab strip is bound to a Livewire property, so Filament renders
 * the panel of the ACTIVE tab only and never reads the tab-persistence flag;
 * the rows read the rendered panel back to prove the schema shows the tab the
 * reader just moved to, in the same request. And the core searcher spends a
 * rate-limiter token on every call while Filament builds the content schema
 * twice for one keystroke, so the limiter's own counter is read directly: one
 * keystroke must cost exactly one token.
 *
 * Helpers are file-local and finCodexHelpSearch*-prefixed. Pest "global"
 * helpers only exist for the files a run loads, so a single-file run of this
 * file cannot see a sibling test file's functions.
 */

/**
 * A fixture user signed in on the web guard, created once per test: several
 * rows mount the page more than once and a second User::create() with the same
 * address would trip the unique index rather than prove anything.
 */
function finCodexHelpSearchUser(string $email = 'search@example.com'): User
{
    $user = User::firstOrCreate(['email' => $email], ['name' => 'Reader']);

    test()->actingAs($user, 'web');

    return $user;
}

/**
 * Two articles the word "roles" reaches: the parent, whose body mentions it,
 * and the child, whose title is it. The child's section path is therefore
 * "Users guide".
 */
function finCodexHelpSearchSeed(): void
{
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Users guide', 'excerpt' => 'All about users.', 'body' => 'Managing **roles** and users.'])
        ->create(['slug' => 'users']);

    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Roles', 'body' => 'What a role can do.'])
        ->create(['slug' => 'users/roles']);

    forgetHelpMemo();
}

/** The page mounted on the admin panel, on the landing unless a slug is given. */
function finCodexHelpSearchPage(?string $slug = null): Testable
{
    test()->usesPanel('admin', finCodexHelpSearchUser());
    forgetHelpMemo();

    return Livewire::test(HelpCenter::class, $slug === null ? [] : [HelpCenter::SLUG_PARAMETER => $slug]);
}

/**
 * The tab key of the tab panel the page actually rendered, or null when it
 * rendered none.
 *
 * Tabs bound to a Livewire property emit the panel of the active tab only
 * (Tab::toEmbeddedHtml returns an empty string for every other one, and for an
 * active tab whose schema is empty), so this is what the reader sees — unlike
 * the strip's buttons, which carry every tab's marker on every render.
 */
function finCodexHelpSearchRenderedTab(string $html): ?string
{
    return preg_match('/data-fin-codex-help-tab="([a-z]+)"[^>]*role="tabpanel"/', $html, $matches) === 1
        ? $matches[1]
        : null;
}

/** The Alpine persistence key Filament renders for one collapsible section id. */
function finCodexHelpSearchPersistKey(string $id): string
{
    return 'section-${'.Js::from($id).' ?? $el.id}-isCollapsed';
}

/*
 * -----------------------------------------------------------------------
 * The rail shell.
 * -----------------------------------------------------------------------
 */

it('puts the search field above the tab strip inside one collapsible rail section', function (): void {
    finCodexHelpSearchSeed();

    $html = finCodexHelpSearchPage()->html();

    $rail = strpos($html, 'data-fin-codex-help-rail="true"');
    $field = strpos($html, 'data-fin-codex-help-search="true"');
    $strip = strpos($html, 'data-fin-codex-help-tab="contents"');

    expect($rail)->toBeInt()
        ->and($field)->toBeInt()
        ->and($strip)->toBeInt()
        // The field is never behind a tab: the reader types without choosing
        // where to type first.
        ->and($rail)->toBeLessThan($field)
        ->and($field)->toBeLessThan($strip)
        ->and($html)->toContain('data-fin-codex-help-tab="search"')
        ->toContain(__('fin-codex::fin-codex.help_center.rail_heading'))
        ->toContain(__('fin-codex::fin-codex.help_center.contents'))
        ->toContain((string) __('lin-codex::lin-codex.ui.search'));
});

it('folds the whole rail away and remembers that, so a narrow screen can put the article first', function (): void {
    finCodexHelpSearchSeed();

    $html = finCodexHelpSearchPage()->html();

    // Collapsible AND persisted, under an id of its own: the rail is one
    // section on every panel and across every article, so the reader arranges
    // it once.
    expect($html)->toContain('id="fin-codex-help-rail"')
        ->toContain('fi-collapsible')
        ->toContain(finCodexHelpSearchPersistKey('fin-codex-help-rail'));
});

it('keeps the typed query in the browser session so a hit can be opened and left', function (): void {
    finCodexHelpSearchSeed();

    $html = finCodexHelpSearchPage()->html();

    expect($html)->toContain("sessionStorage.setItem('fin-codex-help-q'")
        ->toContain("sessionStorage.getItem('fin-codex-help-q')")
        // wire:ignore, so Livewire never morphs the block and x-init runs once
        // per mount rather than on every update.
        ->toContain('wire:ignore');
});

/*
 * -----------------------------------------------------------------------
 * The tab follows the query.
 * -----------------------------------------------------------------------
 */

it('selects the search tab by itself past the minimum length and returns to contents when the query is emptied', function (): void {
    finCodexHelpSearchSeed();

    $page = finCodexHelpSearchPage()->assertSet('tab', 'contents');

    // One character is below lin-codex.search.min_length, so it is not a
    // search yet and the reader is not moved off the tree.
    $page->set('query', 'r')->assertSet('tab', 'contents');

    $page->set('query', 'roles')->assertSet('tab', 'search');

    $page->set('query', '')->assertSet('tab', 'contents');
});

it('renders the tab the query moved to in that very request', function (): void {
    finCodexHelpSearchSeed();

    $page = finCodexHelpSearchPage();

    expect(finCodexHelpSearchRenderedTab($page->html()))->toBe('contents');

    // Filament caches each schema for the request and builds content() while
    // it is still handling the field update, before the tab has moved; without
    // the render-time cache clear this would still be the contents tab.
    $page->set('query', 'roles');

    expect(finCodexHelpSearchRenderedTab($page->html()))->toBe('search');

    $page->set('query', '');

    expect(finCodexHelpSearchRenderedTab($page->html()))->toBe('contents');
});

/*
 * -----------------------------------------------------------------------
 * The hits.
 * -----------------------------------------------------------------------
 */

it('lists a hit in the rail with its title, its section path and the core snippet', function (): void {
    finCodexHelpSearchSeed();

    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Admin roles', 'body' => 'The admin role.'])
        ->create(['slug' => 'users/roles/admin']);

    forgetHelpMemo();

    $html = finCodexHelpSearchPage()->set('query', 'roles')->html();

    expect($html)->toContain('data-fin-codex-help-hit="users/roles"')
        ->toContain('data-fin-codex-help-hit="users/roles/admin"')
        // Root-first, so a reader knows which part of the library a hit is in.
        ->toContain('Users guide › Roles')
        // SnippetBuilder's output: everything escaped, <mark> and nothing else.
        // Escaping it again would show the reader the tag.
        ->toContain('<mark>roles</mark>')
        ->not->toContain('&lt;mark&gt;');
});

it('links every hit as a real anchor into the help center', function (): void {
    finCodexHelpSearchSeed();

    $html = finCodexHelpSearchPage()->set('query', 'roles')->html();

    expect($html)->toContain('href="'.HelpCenter::getUrl([HelpCenter::SLUG_PARAMETER => 'users/roles']).'"')
        ->not->toContain("mountAction('open-");
});

it('shows the core no-results line for a query that matches nothing', function (): void {
    finCodexHelpSearchSeed();

    $page = finCodexHelpSearchPage()->set('query', 'kangaroo');

    expect($page->html())->toContain(__('lin-codex::lin-codex.ui.no_results'))
        ->and(finCodexHelpSearchRenderedTab($page->html()))->toBe('search');
});

it('shows the core rate-limit line with the seconds to wait', function (): void {
    finCodexHelpSearchSeed();

    // A tier of zero refuses every search up front, which is the core's own way
    // of expressing a spent limiter.
    config()->set('lin-codex.search.rate_limit.user', 0);

    expect(finCodexHelpSearchPage()->set('query', 'roles')->html())
        ->toContain((string) __('lin-codex::lin-codex.ui.rate_limited', ['seconds' => 60]))
        ->not->toContain('data-fin-codex-help-hit');
});

it('asks for fifty hits and lets the core clamp them', function (): void {
    foreach (range(1, 5) as $index) {
        Article::factory()->public()->published()
            ->withTranslation('en', ['title' => 'Roles '.$index, 'body' => 'About roles.'])
            ->create(['slug' => 'roles-'.$index]);
    }

    forgetHelpMemo();

    // The default limit is deliberately below the corpus and the cap
    // deliberately below fifty, so the count can only be the CORE's clamp: one
    // hit would mean the page took the default and five would mean it ignored
    // the cap.
    config()->set('lin-codex.search.limit', 1);
    config()->set('lin-codex.search.max_limit', 3);

    $html = finCodexHelpSearchPage()->set('query', 'roles')->html();

    expect(substr_count($html, 'data-fin-codex-help-hit='))->toBe(3);
});

/*
 * -----------------------------------------------------------------------
 * The limiter, spent once per render.
 * -----------------------------------------------------------------------
 */

it('spends one limiter token per keystroke, though Filament builds the schema twice', function (): void {
    finCodexHelpSearchSeed();

    $user = finCodexHelpSearchUser();
    $key = 'codex-search:user:'.$user->getKey();

    finCodexHelpSearchPage()->set('query', 'roles');

    // Filament builds content() once while it handles the field update and
    // again at render, and every Searcher::search() call spends a token. Two
    // per keystroke turns a working search into "Too many searches" for a fast
    // typist, so the result is memoised on the trimmed query.
    expect(RateLimiter::attempts($key))->toBe(1);
});

it('never reaches the searcher below the minimum length', function (): void {
    finCodexHelpSearchSeed();

    $user = finCodexHelpSearchUser();
    $key = 'codex-search:user:'.$user->getKey();

    // Even with the tab forced over, one character costs the reader nothing and
    // renders no panel at all.
    $page = finCodexHelpSearchPage()->set('query', 'r')->set('tab', 'search');

    expect(RateLimiter::attempts($key))->toBe(0)
        ->and(finCodexHelpSearchRenderedTab($page->html()))->toBeNull();
});
