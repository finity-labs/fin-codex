<?php

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\UnorderedList;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpCenter;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * CENTER-01 and the override half of CENTER-06: one page class on one route
 * serving {panel}/help and every {panel}/help/{slug}, behind the panel's own
 * auth and guard, on every panel that carries the plugin — authoring(false)
 * included — plus the helpCenterPage() option and the static resolver Phase 16
 * builds URLs with. Whether a menu points at the page is Phase 16's placement
 * option and lives in tests/Feature/Plugin/HelpCenterPlacementTest.php.
 *
 * The wildcard slug is the interesting part: Filament exposes no hook for a
 * route's where(), so the page overrides getRoutePath() and the provider
 * declares a GLOBAL Route::pattern() for the parameter name. These rows prove
 * both halves meet — a path-like slug arrives whole, and the generated URL
 * keeps its slashes rather than percent-encoding them.
 *
 * Helpers are file-local and finCodexHelpCenter*-prefixed. Pest "global"
 * helpers are only loaded for the files a run actually loads, so a single-file
 * run of this file cannot see a sibling test file's functions (the convention
 * tests/Feature/Scope/PanelScopeSurfacesTest.php states for the same reason).
 */

/** A fixture user signed in on the given panel guard. */
function finCodexHelpCenterUser(string $guard = 'web', string $email = 'reader@example.com'): User
{
    $user = User::create(['name' => 'Reader', 'email' => $email]);

    test()->actingAs($user, $guard);

    return $user;
}

/**
 * A panel of its own carrying one configured plugin instance.
 *
 * Built outside the fixture registry: Panel::plugin() calls the plugin's
 * register() there and then, which is the whole subject here, and a panel the
 * registry knows would move what every all-panels test counts.
 */
function finCodexHelpCenterPanel(FinCodexPlugin $plugin, string $id = 'help-center'): Panel
{
    return Panel::make()->id($id)->path($id)->plugin($plugin);
}

/*
 * -----------------------------------------------------------------------
 * Registration and routing.
 * -----------------------------------------------------------------------
 */

it('registers the page on every panel that carries the plugin and on none that does not', function (): void {
    expect(array_values(Filament::getPanel('admin')->getPages()))->toContain(HelpCenter::class)
        ->and(array_values(Filament::getPanel('staff')->getPages()))->toContain(HelpCenter::class)
        ->and(array_values(Filament::getPanel('portal')->getPages()))->toContain(HelpCenter::class)
        ->and(array_values(Filament::getPanel('plain')->getPages()))->not->toContain(HelpCenter::class);
});

it('gives every plugin panel a help route and the plain panel none', function (): void {
    expect(Route::has('filament.admin.pages.help'))->toBeTrue()
        ->and(Route::has('filament.staff.pages.help'))->toBeTrue()
        ->and(Route::has('filament.portal.pages.help'))->toBeTrue()
        ->and(Route::has('filament.plain.pages.help'))->toBeFalse();
});

it('keeps the page on a panel that only reads help', function (): void {
    $panel = finCodexHelpCenterPanel(FinCodexPlugin::make()->authoring(false), 'reader');

    expect(array_values($panel->getPages()))->toBe([HelpCenter::class])
        ->and(array_values($panel->getResources()))->toBe([]);
});

it('answers on the landing and on a path-like article slug for a signed-in admin', function (): void {
    finCodexHelpCenterUser();

    $landing = route('filament.admin.pages.help');
    $article = route('filament.admin.pages.help', [HelpCenter::SLUG_PARAMETER => 'account/signing-in']);

    expect($landing)->toEndWith('/admin/help')
        ->and($article)->toEndWith('/admin/help/account/signing-in')
        ->and($article)->not->toContain('%2F');

    $this->get($landing)->assertOk();
    $this->get($article)->assertOk();
});

it('answers on the staff panel behind the staff guard', function (): void {
    finCodexHelpCenterUser('staff', 'staff@example.com');

    $this->get(route('filament.staff.pages.help'))->assertOk();
    $this->get(route('filament.staff.pages.help', [HelpCenter::SLUG_PARAMETER => 'account/signing-in']))->assertOk();
});

it('sends a guest to the panel login', function (): void {
    $this->get(route('filament.admin.pages.help'))
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('mounts the whole path-like slug, slashes intact, and null on the landing', function (): void {
    test()->usesPanel('admin', finCodexHelpCenterUser());

    Livewire::test(HelpCenter::class, [HelpCenter::SLUG_PARAMETER => 'account/signing-in'])
        ->assertSet('codexSlug', 'account/signing-in');

    Livewire::test(HelpCenter::class)->assertSet('codexSlug', null);
});

it('builds its own URLs through the page, keeping the slashes', function (): void {
    test()->usesPanel('admin', finCodexHelpCenterUser());

    expect(HelpCenter::getUrl([HelpCenter::SLUG_PARAMETER => 'account/signing-in']))
        ->toEndWith('/admin/help/account/signing-in')
        ->not->toContain('%2F')
        ->and(HelpCenter::getUrl())->toEndWith('/admin/help');
});

/*
 * -----------------------------------------------------------------------
 * Navigation, titles and access.
 * -----------------------------------------------------------------------
 */

it('produces no navigation item under the default placement while staying open to a panel user', function (): void {
    // Portal, not admin: since Phase 16 the fixture panels carry explicit
    // placements (admin is in the navigation, staff in both) and portal is the
    // one that still takes the shipped default. Where the item goes is
    // HelpCenterPlacementTest's; this row only holds the promise Phase 15 made
    // — the page is registered and open whether or not a menu points at it.
    test()->usesPanel('portal', finCodexHelpCenterUser());

    expect(HelpCenter::shouldRegisterNavigation())->toBeFalse()
        ->and(HelpCenter::canAccess())->toBeTrue()
        ->and(array_values(Filament::getPanel('portal')->getPages()))->toContain(HelpCenter::class);
});

it('shows the static Help heading while the browser tab follows the article', function (): void {
    test()->usesPanel('admin', finCodexHelpCenterUser());

    $help = (string) __('fin-codex::fin-codex.help_center.title');

    $landing = Livewire::test(HelpCenter::class)->instance();

    expect($landing->getHeading())->toBe($help)
        ->and($landing->getTitle())->toBe($help);

    // No such article, so the tab title falls back to the same word; the
    // article's own title is proven in ArticleColumnTest.
    $missing = Livewire::test(HelpCenter::class, [HelpCenter::SLUG_PARAMETER => 'nothing/here'])->instance();

    expect($missing->getHeading())->toBe($help)
        ->and($missing->getTitle())->toBe($help);
});

it('renders the three-column grid, the rail included', function (): void {
    test()->usesPanel('admin', finCodexHelpCenterUser());

    // The rail's own contents are ContentsRailTest's and SearchTabTest's; this
    // row only pins the grid the three columns live in.
    Livewire::test(HelpCenter::class)
        ->assertOk()
        ->assertSee('fin-codex-help__rail', escape: false)
        ->assertSee('data-fin-codex-help-rail', escape: false)
        ->assertSee('fin-codex-help__article', escape: false);
});

it('pins the hand-tuned column spans and the single-column headings list', function (): void {
    test()->usesPanel('admin', finCodexHelpCenterUser());

    // The three spans are not an arbitrary grid: Albert widened the article
    // column and narrowed "On this page" against the real application on
    // 2026-09-13, and the list under that narrow column needs one column of its
    // own because UnorderedList defaults to two from sm upwards. All three
    // numbers are easy to undo by accident from a later layout edit, so they
    // are read back off the schema rather than off the rendered style string,
    // which is Filament's to change.
    $article = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Signing in', 'body' => "## Passwords\n\nType your **password**.\n\n### Resetting\n\nUse the link."])
        ->create(['slug' => 'account/signing-in']);

    forgetHelpMemo();

    $content = Livewire::test(HelpCenter::class, [HelpCenter::SLUG_PARAMETER => $article->slug])
        ->instance()
        ->getSchema('content');

    $grid = $content?->getComponents()[0] ?? null;

    expect($grid)->toBeInstanceOf(Grid::class)
        ->and($grid->getColumns('lg'))->toBe(12);

    // Rail, article, headings, in grid order. The headings column only exists
    // because the seeded article has two of them.
    [$rail, $body, $headings] = $grid->getChildComponents();

    expect($rail->getColumnSpan('lg'))->toBe(3)
        ->and($body->getColumnSpan('lg'))->toBe(7)
        ->and($headings->getColumnSpan('lg'))->toBe(2)
        // 3 + 7 + 2 = 12, so the grid still fills its row.
        ->and($rail->getColumnSpan('lg') + $body->getColumnSpan('lg') + $headings->getColumnSpan('lg'))->toBe(12);

    $list = $headings->getChildComponents()[0]->getChildComponents()[0];

    expect($list)->toBeInstanceOf(UnorderedList::class)
        ->and($list->getColumns('lg'))->toBe(1);
});

/*
 * -----------------------------------------------------------------------
 * helpCenterPage() and the static resolver (CENTER-06, override half).
 * -----------------------------------------------------------------------
 */

it('puts a helpCenterPage() override on the panel instead of the shipped page', function (): void {
    $panel = finCodexHelpCenterPanel(FinCodexPlugin::make()->helpCenterPage(AdminHelpCenter::class), 'override');

    expect(array_values($panel->getPages()))->toContain(AdminHelpCenter::class)
        ->not->toContain(HelpCenter::class);
});

it('resolves the shipped page for a panel with no override and for one without the plugin', function (): void {
    test()->usesPanel('admin');

    expect(FinCodexPlugin::helpCenterPageClass())->toBe(HelpCenter::class)
        ->and(FinCodexPlugin::helpCenterPageClass('portal'))->toBe(HelpCenter::class)
        ->and(FinCodexPlugin::helpCenterPageClass('plain'))->toBe(HelpCenter::class)
        ->and(FinCodexPlugin::helpCenterPageClass('nope'))->toBe(HelpCenter::class);
});

it('resolves a real override and ignores one that does not extend the shipped page', function (): void {
    // Straight into the registry, not through Filament::registerPanel(): the
    // facade's version defers the registration to a resolving() callback on the
    // registry, which has long since been resolved by the time a test runs.
    $registry = app(PanelRegistry::class);

    $registry->register(finCodexHelpCenterPanel(FinCodexPlugin::make()->helpCenterPage(AdminHelpCenter::class), 'good'));
    $registry->register(finCodexHelpCenterPanel(FinCodexPlugin::make()->helpCenterPage(Dashboard::class), 'stray'));

    expect(FinCodexPlugin::helpCenterPageClass('good'))->toBe(AdminHelpCenter::class)
        ->and(FinCodexPlugin::helpCenterPageClass('stray'))->toBe(HelpCenter::class);
});

it('keeps the option fluent and readable back off the plugin', function (): void {
    $plugin = FinCodexPlugin::make();

    expect($plugin->getHelpCenterPage())->toBeNull()
        ->and($plugin->helpCenterPage(AdminHelpCenter::class))->toBe($plugin)
        ->and($plugin->getHelpCenterPage())->toBe(AdminHelpCenter::class);
});
