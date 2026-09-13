<?php

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\CoverageRow;
use FinityLabs\FinCodex\Livewire\HelpDrawer;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\ViewAllPanelsArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * SCOPE-01 as a reader meets it. 14-03 proved the verdicts through the core's
 * ArticleGate; these rows drive the surfaces themselves — the drawer's browse
 * tab, its search and a direct open, the field hints, the panel's global
 * search and the guest drawer on a login page — and none of them carries a
 * line of scoping code: the hook the panel boot installs answers for all of
 * them.
 *
 * One panel per test method (FilamentManager boots the first panel of a PHP
 * request cycle only), and every row seeds before its first read, so no memo
 * needs dropping. Assertions are on titles and on the markers the core puts in
 * its markup, never on Filament's own internals.
 *
 * The helpers are file-local and prefixed, because Pest's are global for the
 * whole run: PanelScopeGateTest already owns finCodexScope{User,Article,Shell}
 * and DrawerReadPathTest owns finCodexReadSlugs.
 */

function finCodexScopeSurfaceUser(string $email = 'surface@example.com'): User
{
    return User::create(['name' => 'Surface', 'email' => $email]);
}

/** One published, public article with a body, optionally carrying one context. */
function finCodexScopeSurfaceArticle(string $slug, string $title, ?ContextType $type = null, string $key = '', ?string $panelId = null): Article
{
    $factory = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => $title, 'body' => 'Body of '.$slug.'.']);

    if ($type !== null) {
        $factory = $factory->withContext($type, $key, $panelId);
    }

    return $factory->create(['slug' => $slug]);
}

/**
 * The eight-article set every row reads, all public and published:
 *
 * - intro            general, so every panel shows it
 * - admin-only       admin's dashboard route, pinned to admin
 * - staff-only       the Dashboard page class pinned to staff (both fixture
 *                    panels register that page, so the prefix is what makes it
 *                    staff's)
 * - outside          a plain Laravel route carrying no panel of its own: the
 *                    set's "any panel" member, read from every panel since
 *                    14-05 and the discriminator none of these rows uses
 * - guides           a general section with no body — a navigation shell —
 *                    holding the single staff-bound child guides/staff-tips
 * - manuals          the Dashboard page class pinned to admin, with the
 *                    general child manuals/roles riding along
 *
 * The section pair is "manuals", not "users": the fixture UserResource
 * declares the slug users for admin AND staff (WithHelp), so that slug is
 * never another panel's alone. The same trap bit 14-03.
 *
 * @return array<string, Article> keyed by slug
 */
function finCodexScopeSeed(): array
{
    $articles = [
        'intro' => finCodexScopeSurfaceArticle('intro', 'Intro guide'),
        'admin-only' => finCodexScopeSurfaceArticle('admin-only', 'Admin only', ContextType::Route, 'filament.admin.pages.dashboard', 'admin'),
        'staff-only' => finCodexScopeSurfaceArticle('staff-only', 'Staff only', ContextType::PageClass, Dashboard::class, 'staff'),
        'outside' => finCodexScopeSurfaceArticle('outside', 'Outside page', ContextType::Route, 'shop.index'),
    ];

    $articles['guides'] = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Guides', 'body' => ''])
        ->create(['slug' => 'guides']);

    $articles['guides/staff-tips'] = finCodexScopeSurfaceArticle('guides/staff-tips', 'Staff tips', ContextType::Route, 'filament.staff.pages.dashboard', 'staff');
    $articles['manuals'] = finCodexScopeSurfaceArticle('manuals', 'Manuals', ContextType::PageClass, Dashboard::class, 'admin');
    $articles['manuals/roles'] = finCodexScopeSurfaceArticle('manuals/roles', 'Manual roles');

    return $articles;
}

/** The panel's drawer, mounted for one page as the panel mount does. */
function finCodexScopeSurfaceDrawer(string $panelId, string $guard, string $pageClass = Dashboard::class): Testable
{
    return Livewire::test(HelpDrawer::class, [
        'pageClass' => $pageClass,
        'panelId' => $panelId,
        'guard' => $guard,
    ]);
}

/**
 * Every global-search result of every category, by title.
 *
 * GlobalSearchTest's finCodexSearchAllResults() does the same flattening, but
 * it is global to a full run only: a single-file run of this file would not
 * have it, so this one is spelled out rather than reached for.
 *
 * @return list<string>
 */
function finCodexScopeSurfaceTitles(?GlobalSearchResults $results): array
{
    $titles = [];

    foreach (($results?->getCategories() ?? []) as $category) {
        foreach ($category as $result) {
            /** @var GlobalSearchResult $result */
            $titles[] = (string) $result->title;
        }
    }

    return $titles;
}

/*
 * The browse tab: the tree every panel page's drawer shows.
 */

it('shows general and own-panel articles in the browse tab and hides the rest', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('admin', finCodexScopeSurfaceUser());

    $html = finCodexScopeSurfaceDrawer('admin', 'web')->call('open')->call('goTo', 'tree')->html();

    expect($html)->toContain('Intro guide')
        ->toContain('Admin only')
        ->toContain('Manuals')
        ->toContain('Manual roles')
        // Its context names no panel of its own, so it restricts nothing.
        ->toContain('Outside page')
        ->not->toContain('Staff only')
        ->not->toContain('Staff tips')
        // The empty section goes with the child that was its only reason to exist.
        ->not->toContain('data-codex-tree-node="guides"')
        ->not->toContain('>Guides<');
});

it('shows the other panel\'s articles in the browse tab on that panel', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('staff', finCodexScopeSurfaceUser('staff-surface@example.com'));

    $html = finCodexScopeSurfaceDrawer('staff', 'staff')->call('open')->call('goTo', 'tree')->html();

    expect($html)->toContain('Intro guide')
        ->toContain('Staff only')
        ->toContain('Staff tips')
        ->toContain('data-codex-tree-node="guides"')
        // The same panel-less article the admin row reads, from here too.
        ->toContain('Outside page')
        ->not->toContain('Admin only')
        // A panel-bound section takes its general child with it.
        ->not->toContain('Manuals')
        ->not->toContain('Manual roles');
});

it('keeps another panel\'s articles out of the drawer search', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('admin', finCodexScopeSurfaceUser());

    $drawer = finCodexScopeSurfaceDrawer('admin', 'web')->call('open')->set('query', 'staff');

    expect($drawer->get('view'))->toBe('search')
        ->and($drawer->html())->not->toContain('Staff only')
        ->not->toContain('Staff tips')
        ->not->toContain('Body of staff-only.');

    $drawer->set('query', 'admin');

    expect($drawer->html())->toContain('Admin only');
});

it('answers not found when a hidden slug is opened directly', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('admin', finCodexScopeSurfaceUser());

    finCodexScopeSurfaceDrawer('admin', 'web')
        ->call('show', 'staff-only')
        ->assertSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertDontSee('Body of staff-only.');
});

/*
 * Field hints. The fixture UserResource's form carries the codexHelp() macro
 * on name (users#assigning-roles) and the explicit action on email
 * (users/roles); "Open help" is the hint's own accessible label, so counting
 * it counts rendered hints.
 */

it('renders the hint of an article bound to this panel', function (): void {
    finCodexScopeSeed();
    finCodexScopeSurfaceArticle('users', 'Users guide');
    finCodexScopeSurfaceArticle('users/roles', 'Roles', ContextType::PageClass, Dashboard::class, 'admin');

    $html = $this->actingAs(finCodexScopeSurfaceUser(), 'web')->get('/admin/users/create')->assertOk()->getContent();

    expect(substr_count($html, 'aria-label="Open help"'))->toBe(2)
        ->and($html)->toContain('/help/users/roles');
});

it('renders no hint for an article bound to another panel', function (): void {
    finCodexScopeSeed();
    finCodexScopeSurfaceArticle('users', 'Users guide');
    finCodexScopeSurfaceArticle('users/roles', 'Roles', ContextType::PageClass, Dashboard::class, 'staff');

    $html = $this->actingAs(finCodexScopeSurfaceUser(), 'web')->get('/admin/users/create')->assertOk()->getContent();

    // The users hint stays: the resource declares that slug, so it is admin's too.
    expect(substr_count($html, 'aria-label="Open help"'))->toBe(1)
        ->and($html)->not->toContain('/help/users/roles');
});

/*
 * The panel's own search field. Staff is the fixture panel whose plugin has
 * globalSearch() on.
 */
it('lists only general and own-panel hits in the panel global search', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('staff', finCodexScopeSurfaceUser('staff-surface@example.com'));

    expect(finCodexScopeSurfaceTitles(Filament::getGlobalSearchProvider()?->getResults('only')))
        ->toContain('Staff only')
        ->not->toContain('Admin only');

    expect(finCodexScopeSurfaceTitles(Filament::getGlobalSearchProvider()?->getResults('intro')))
        ->toContain('Intro guide');
});

/*
 * The guest drawer on a login page: the one read path a panel serves before
 * anybody is signed in. viewAllPanels is never asked for a guest, so the scope
 * is the whole answer.
 */
it('scopes the guest drawer on the panel login page', function (): void {
    finCodexScopeSeed();
    finCodexScopeSurfaceArticle('admin-login', 'Admin login help', ContextType::Route, 'filament.admin.auth.login', 'admin');
    finCodexScopeSurfaceArticle('staff-login', 'Staff login help', ContextType::Route, 'filament.staff.auth.login', 'staff');

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain('data-codex-page-article="admin-login"')
        ->not->toContain('data-codex-page-article="staff-login"')
        ->not->toContain('Staff login help');

    $tree = finCodexScopeSurfaceDrawer('admin', 'web', Login::class)->call('goTo', 'tree')->html();

    expect(auth('web')->check())->toBeFalse()
        ->and($tree)->toContain('Intro guide')
        ->toContain('Admin login help')
        ->not->toContain('Staff only')
        ->not->toContain('Staff login help');
});

/*
 * The install seeds two starter articles, account/signing-in and
 * account/creating-an-account, bound by class to Filament's Login and Register
 * pages and carrying no panel of their own. No panel registers those pages, so
 * from 14-03 until 14-05 the scope hid them inside every panel — the login page
 * they were written for included. The shape is seeded here rather than the
 * install run, because the rule is what these rows are about.
 */

it('shows the starter login article in the guest drawer on the panel login page', function (): void {
    finCodexScopeSeed();
    finCodexScopeSurfaceArticle('account/signing-in', 'Signing in', ContextType::PageClass, Login::class);

    $this->get('/admin/login')->assertOk();

    $tree = finCodexScopeSurfaceDrawer('admin', 'web', Login::class)->call('goTo', 'tree')->html();

    expect(auth('web')->check())->toBeFalse()
        ->and($tree)->toContain('Signing in')
        ->toContain('Intro guide')
        ->not->toContain('Staff only');
});

it('shows that same starter article in another panel\'s drawer', function (): void {
    finCodexScopeSeed();
    finCodexScopeSurfaceArticle('account/signing-in', 'Signing in', ContextType::PageClass, Login::class);
    $this->usesPanel('staff', finCodexScopeSurfaceUser('staff-surface@example.com'));

    $tree = finCodexScopeSurfaceDrawer('staff', 'staff')->call('open')->call('goTo', 'tree')->html();

    // Every panel, not the one the class happens to resolve into: no panel
    // registers Filament's auth pages at all.
    expect($tree)->toContain('Signing in')
        ->not->toContain('Admin only');
});

/*
 * SCOPE-03 on a real surface: the host decides, per viewer, who reads every
 * panel's help. The control row underneath it is the same drawer with the
 * shipped policy, which grants nobody.
 *
 * What the lift adds is staff's three articles and the section that holds one
 * of them; "Outside page" is no discriminator here, because a panel-less
 * context is read from admin with or without the lift.
 */

it('shows every panel\'s articles to a viewer whose host policy grants viewAllPanels', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('admin', finCodexScopeSurfaceUser());

    // After usesPanel(): the plugin's boot registers the shipped policy again.
    Gate::policy(Article::class, ViewAllPanelsArticlePolicy::class);

    $html = finCodexScopeSurfaceDrawer('admin', 'web')->call('open')->call('goTo', 'tree')->html();

    expect($html)->toContain('Staff only')
        ->toContain('Staff tips')
        ->toContain('data-codex-tree-node="guides"');
});

it('shows the same viewer nothing extra under the shipped policy', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('admin', finCodexScopeSurfaceUser());

    $html = finCodexScopeSurfaceDrawer('admin', 'web')->call('open')->call('goTo', 'tree')->html();

    expect($html)->toContain('Admin only')
        ->not->toContain('Staff only')
        ->not->toContain('Staff tips')
        ->not->toContain('data-codex-tree-node="guides"');
});

/*
 * Everything outside a panel request, unchanged. The core's JSON API and its
 * media route serve no panel, so the hook returns early on them; the coverage
 * report and the editor read the model and the report rather than the reader's
 * gate, so a panel being current changes nothing there either.
 */

/**
 * End one in-process request and start the next the way a worker does.
 *
 * PanelScopeBootTest documents this at length: Testbench reuses one container
 * for every $this->get() of a test and Filament's manager is a scoped binding
 * holding the current panel, so without these two calls the panel a previous
 * request made current is still current on the next one. Octane flushes
 * exactly these between requests; FPM gets a fresh process. Spelled out again
 * here rather than borrowed, because that file's copy exists only in a run
 * that loads it.
 */
function finCodexScopeSurfaceEndRequest(): void
{
    app()->forgetScopedInstances();

    Facade::clearResolvedInstances();
}

it('answers the core json api with every article when no panel is current', function (): void {
    finCodexScopeSeed();

    $this->get('/codex/api/tree')->assertOk()
        ->assertJsonFragment(['slug' => 'admin-only'])
        ->assertJsonFragment(['slug' => 'staff-only'])
        ->assertJsonFragment(['slug' => 'outside'])
        ->assertJsonFragment(['slug' => 'guides/staff-tips']);

    $this->get('/codex/api/search?q=staff')->assertOk()->assertJsonFragment(['slug' => 'staff-only']);

    $this->get('/codex/api/articles/staff-only')->assertOk()->assertJsonFragment(['slug' => 'staff-only']);
});

it('leaves the core json api unscoped after a panel request in the same process', function (): void {
    finCodexScopeSeed();

    $this->actingAs(finCodexScopeSurfaceUser(), 'web')->get('/admin')->assertOk();

    finCodexScopeSurfaceEndRequest();

    // The hook stays in the core's slot; what does not survive is the panel.
    expect(config('lin-codex.auth.gate'))->toBe(PanelScopeGate::class)
        ->and(Filament::getCurrentPanel())->toBeNull();

    $this->get('/codex/api/tree')->assertOk()
        ->assertJsonFragment(['slug' => 'admin-only'])
        ->assertJsonFragment(['slug' => 'staff-only'])
        ->assertJsonFragment(['slug' => 'outside'])
        ->assertJsonFragment(['slug' => 'guides/staff-tips']);

    $this->get('/codex/api/search?q=staff')->assertOk()->assertJsonFragment(['slug' => 'staff-only']);

    $this->get('/codex/api/articles/staff-only')->assertOk()->assertJsonFragment(['slug' => 'staff-only']);
});

/**
 * A throw-away docs tree holding one file article bound to the staff panel and
 * the image it references.
 *
 * The media route serves the images of FILE articles, and this package's
 * fixture docs (tests/Fixtures/docs) carry none — several rows assert their
 * exact shape — so the tree is written for the row instead of added there.
 *
 * @return string the docs path, already configured as the file source
 */
function finCodexScopeSurfaceMediaDocs(): string
{
    $root = sys_get_temp_dir().'/fin-codex-scope-media-'.bin2hex(random_bytes(4));

    mkdir($root.'/en/images', 0o755, true);

    file_put_contents($root.'/en/staff-shot.md', <<<'MARKDOWN'
        ---
        visibility: public
        contexts: [route:filament.staff.pages.dashboard]
        ---

        # Staff shot

        ![Shot](images/shot.png)
        MARKDOWN);

    file_put_contents($root.'/en/images/shot.png', 'binary');

    config()->set('lin-codex.sources.filesystem.paths', [$root]);

    app()->forgetInstance(FilesystemSource::class);

    forgetHelpMemo();

    return $root;
}

it('serves the media route of another panel\'s article after a panel request', function (): void {
    $docs = finCodexScopeSurfaceMediaDocs();

    try {
        finCodexScopeSeed();

        // The control: the file article is really there and really references
        // the image, so the row below asks the gate about a real owner.
        $body = $this->get('/codex/api/articles/staff-shot')->assertOk()->json('data.html');

        expect((string) $body)->toContain('/codex/media/en/images/shot.png');

        $this->actingAs(finCodexScopeSurfaceUser(), 'web')->get('/admin')->assertOk();

        finCodexScopeSurfaceEndRequest();

        expect(Filament::getCurrentPanel())->toBeNull();

        // The image's only owner is the staff-bound file article, which the
        // drawer on /admin had just hidden; on the core's own route nothing is
        // scoped and the gate answers as it did on 0.4.
        $this->get('/codex/media/en/images/shot.png')->assertOk();
    } finally {
        File::deleteDirectory($docs);
    }
});

it('leaves the coverage report unscoped on a panel', function (): void {
    finCodexScopeSeed();
    $this->usesPanel('admin', finCodexScopeSurfaceUser());

    $rows = app(CoverageReport::class)->rows();

    $slugs = array_values(array_filter(array_map(
        static fn (CoverageRow $row): ?string => $row->slug,
        $rows,
    )));

    $staffDashboard = array_values(array_filter(
        $rows,
        static fn (CoverageRow $row): bool => in_array('filament.staff.pages.dashboard', $row->routeNames(), true),
    ));

    // The report is the editor's map of every panel, so the staff screen keeps
    // its covering article while admin is the current panel. Which of the two
    // staff-bound articles won the screen is the report's business, not this
    // row's.
    expect($slugs)->toContain('staff-only')
        ->and($staffDashboard)->toHaveCount(1)
        ->and($staffDashboard[0]->panelId)->toBe('staff')
        ->and($staffDashboard[0]->covered())->toBeTrue();
});

it('leaves the editor listing every article on a panel', function (): void {
    $articles = finCodexScopeSeed();
    $this->usesPanel('admin', finCodexScopeSurfaceUser());

    Livewire::test(ListArticles::class)->assertCanSeeTableRecords([
        $articles['staff-only'],
        $articles['outside'],
        $articles['guides/staff-tips'],
    ]);
});
