<?php

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Enums\HelpCenterPlacement;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;

/*
 * PLACE-01: where a panel's Help Center is reachable from.
 *
 * One option with four named states — the user menu, the navigation, both,
 * neither — plus the four options that decide what the navigation item looks
 * like. Under every one of them the page stays registered and its route stays
 * live, so the drawer footer, the field hints, the global search rows and a
 * bookmark all still reach it; the placement moves the two MENU ENTRIES and
 * nothing else.
 *
 * Helpers are file-local and finCodexPlacement*-prefixed. A sibling test
 * file's functions only exist when that file is loaded, so a single-file run
 * cannot see them; anything two files need lives in tests/Pest.php, which
 * every run loads.
 */

/** @return array<string, mixed> The five placement options as the plugin reports them. */
function finCodexPlacementOptions(FinCodexPlugin $plugin): array
{
    return [
        'placement' => $plugin->getHelpCenterPlacement(),
        'group' => $plugin->getHelpCenterNavigationGroup(),
        'sort' => $plugin->getHelpCenterNavigationSort(),
        'label' => $plugin->getHelpCenterNavigationLabel(),
        'icon' => $plugin->getHelpCenterNavigationIcon(),
    ];
}

/*
 * -----------------------------------------------------------------------
 * The enum.
 * -----------------------------------------------------------------------
 */

it('names both surfaces from each placement', function (): void {
    expect(HelpCenterPlacement::UserMenu->inUserMenu())->toBeTrue()
        ->and(HelpCenterPlacement::UserMenu->inNavigation())->toBeFalse()
        ->and(HelpCenterPlacement::Navigation->inUserMenu())->toBeFalse()
        ->and(HelpCenterPlacement::Navigation->inNavigation())->toBeTrue()
        ->and(HelpCenterPlacement::Both->inUserMenu())->toBeTrue()
        ->and(HelpCenterPlacement::Both->inNavigation())->toBeTrue()
        ->and(HelpCenterPlacement::None->inUserMenu())->toBeFalse()
        ->and(HelpCenterPlacement::None->inNavigation())->toBeFalse();
});

it('backs the placement with a string, because it is UI-only and never stored', function (): void {
    expect(array_map(fn (HelpCenterPlacement $case): string => $case->value, HelpCenterPlacement::cases()))
        ->toBe(['user_menu', 'navigation', 'both', 'none']);
});

/*
 * -----------------------------------------------------------------------
 * The five options.
 * -----------------------------------------------------------------------
 */

it('ships the Help Center in the user menu, at the top level, at the bottom of the sidebar', function (): void {
    // Group null and sort 1000 on purpose: the Help Center is the reading
    // surface, not an admin screen, so it is not filed with the three
    // authoring screens and it lands below whatever the host arranged.
    expect(finCodexPlacementOptions(FinCodexPlugin::make()))->toBe([
        'placement' => HelpCenterPlacement::UserMenu,
        'group' => null,
        'sort' => 1000,
        'label' => 'Help center',
        'icon' => Heroicon::OutlinedBookOpen,
    ]);
});

it('returns itself from every placement setter and takes a literal', function (): void {
    $plugin = FinCodexPlugin::make();

    expect($plugin->helpCenterPlacement(HelpCenterPlacement::Both))->toBe($plugin)
        ->and($plugin->helpCenterNavigationGroup('Reading'))->toBe($plugin)
        ->and($plugin->helpCenterNavigationSort(10))->toBe($plugin)
        ->and($plugin->helpCenterNavigationLabel('Manual'))->toBe($plugin)
        ->and($plugin->helpCenterNavigationIcon(Heroicon::OutlinedAcademicCap))->toBe($plugin)
        ->and(finCodexPlacementOptions($plugin))->toBe([
            'placement' => HelpCenterPlacement::Both,
            'group' => 'Reading',
            'sort' => 10,
            'label' => 'Manual',
            'icon' => Heroicon::OutlinedAcademicCap,
        ]);
});

it('evaluates a closure in every placement getter', function (): void {
    $plugin = FinCodexPlugin::make()
        ->helpCenterPlacement(fn (): HelpCenterPlacement => HelpCenterPlacement::None)
        ->helpCenterNavigationGroup(fn (): string => 'Library')
        ->helpCenterNavigationSort(fn (): int => 20)
        ->helpCenterNavigationLabel(fn (): string => 'Handbook')
        ->helpCenterNavigationIcon(fn (): Heroicon => Heroicon::OutlinedBookmark);

    expect(finCodexPlacementOptions($plugin))->toBe([
        'placement' => HelpCenterPlacement::None,
        'group' => 'Library',
        'sort' => 20,
        'label' => 'Handbook',
        'icon' => Heroicon::OutlinedBookmark,
    ]);
});

it('degrades a closure that answers null to the documented default', function (): void {
    // Placement, label and icon have a default that is not null, so a closure
    // answering null must land back on it rather than fatal. Group and sort
    // default to a top-level item at sort 1000, and null there is a host
    // genuinely saying "no group" and "no sort".
    $plugin = FinCodexPlugin::make()
        ->helpCenterPlacement(fn () => null)
        ->helpCenterNavigationGroup(fn () => null)
        ->helpCenterNavigationSort(fn () => null)
        ->helpCenterNavigationLabel(fn () => null)
        ->helpCenterNavigationIcon(fn () => null);

    expect(finCodexPlacementOptions($plugin))->toBe([
        'placement' => HelpCenterPlacement::UserMenu,
        'group' => null,
        'sort' => null,
        'label' => 'Help center',
        'icon' => Heroicon::OutlinedBookOpen,
    ]);
});

it('leaves the three authoring screens on their own navigation options', function (): void {
    // The Help Center reads five options of its own; navigationGroup() and
    // navigationSort() keep meaning exactly what they meant before this
    // phase — the editor, Help settings and Help coverage.
    $plugin = FinCodexPlugin::make()->navigationGroup('Support')->navigationSort(5);

    expect($plugin->getHelpCenterNavigationGroup())->toBeNull()
        ->and($plugin->getHelpCenterNavigationSort())->toBe(1000)
        ->and($plugin->getNavigationGroup())->toBe('Support')
        ->and($plugin->getNavigationSort())->toBe(5);
});

it('translates the navigation label in all three locales', function (): void {
    $key = 'fin-codex::fin-codex.help_center.navigation';

    expect(__($key, [], 'en'))->toBe('Help center')
        ->and(__($key, [], 'de'))->toBe('Hilfecenter')
        ->and(__($key, [], 'hu'))->toBe('Súgóközpont');
});

/*
 * -----------------------------------------------------------------------
 * The two entries.
 * -----------------------------------------------------------------------
 */

/** A fixture user signed in on the given panel guard. */
function finCodexPlacementUser(string $guard = 'web', string $email = 'placement@example.com'): User
{
    $user = User::create(['name' => 'Placement Reader', 'email' => $email]);

    test()->actingAs($user, $guard);

    return $user;
}

/**
 * A panel of its own carrying one configured plugin instance, set current and
 * serving.
 *
 * Built outside the fixture registry, the way HelpCenterPageTest builds its
 * own: Panel::plugin() calls the plugin's register() there and then, which is
 * the subject here, and a panel the registry knows would move what every
 * all-panels test counts. Its routes are never registered, so it can answer
 * what the panel holds but never what a URL does — the rows that need a live
 * route use a fixture panel.
 */
function finCodexPlacementPanel(FinCodexPlugin $plugin, string $id): Panel
{
    $panel = Panel::make()->id($id)->path($id)->plugin($plugin);

    Filament::setCurrentPanel($panel);
    Filament::setServingStatus();

    return $panel;
}

/** The plugin instance a fixture panel registered, typed for the assertions below. */
function finCodexPlacementPluginOf(string $panel): FinCodexPlugin
{
    $plugin = Filament::getPanel($panel)->getPlugin('fin-codex');

    expect($plugin)->toBeInstanceOf(FinCodexPlugin::class);

    /** @var FinCodexPlugin $plugin */
    return $plugin;
}

/**
 * Every Help Center entry the panel is holding, visible or not.
 *
 * Read off the raw property by reflection rather than through
 * getUserMenuItemGroups(), for two reasons. That accessor synthesises the
 * profile and logout items, which resolve panel routes a panel built inside a
 * test body does not have; and it keys every item by its name, so a second
 * registration would be folded silently into the first and a count taken from
 * there could never go red.
 *
 * @return list<Action>
 */
function finCodexPlacementEntries(Panel $panel): array
{
    $groups = (new ReflectionProperty($panel, 'userMenuItemGroups'))->getValue($panel);

    $entries = [];

    foreach ($groups as $group) {
        foreach ($group as $item) {
            if ($item instanceof Action && $item->getName() === 'fin-codex-help-center') {
                $entries[] = $item;
            }
        }
    }

    return $entries;
}

/** The Help Center's user-menu entry as the panel holds it, visible or not. */
function finCodexPlacementEntry(Panel $panel): ?Action
{
    return finCodexPlacementEntries($panel)[0] ?? null;
}

it('puts one entry in the user menu and no item in the navigation by default', function (): void {
    $panel = test()->usesPanel('portal', finCodexPlacementUser());

    $entry = finCodexPlacementEntry($panel);

    expect($entry)->not->toBeNull()
        ->and($entry->isVisible())->toBeTrue()
        ->and($entry->getLabel())->toBe('Help center')
        ->and($entry->getUrl())->toBe('http://localhost/portal/help')
        ->and(array_keys(Filament::getPanel('portal')->getUserMenuItems()))->toContain('fin-codex-help-center')
        ->and(HelpCenter::shouldRegisterNavigation())->toBeFalse();
});

it('answers each surface from the placement and keeps the page in all four', function (HelpCenterPlacement $placement, bool $inUserMenu, bool $inNavigation): void {
    finCodexPlacementUser();

    $panel = finCodexPlacementPanel(
        FinCodexPlugin::make()->helpCenterPlacement($placement),
        'placement-'.str_replace('_', '-', $placement->value),
    );

    expect(finCodexPlacementEntry($panel)?->isVisible())->toBe($inUserMenu)
        ->and(HelpCenter::shouldRegisterNavigation())->toBe($inNavigation)
        ->and(array_values($panel->getPages()))->toContain(HelpCenter::class);
})->with([
    'user menu (the default)' => [HelpCenterPlacement::UserMenu, true, false],
    'navigation' => [HelpCenterPlacement::Navigation, false, true],
    'both' => [HelpCenterPlacement::Both, true, true],
    'none' => [HelpCenterPlacement::None, false, false],
]);

it('withholds both entries from a viewer the page gate refuses', function (): void {
    // The same refusal mechanism PageShieldTest uses for the settings and
    // coverage pages. Filament already hides the navigation item for a viewer
    // who may not open the page; the user-menu entry only does because the
    // Action asks the page class itself.
    Gate::define('page_HelpCenter', fn (): bool => false);

    finCodexPlacementUser();

    $panel = finCodexPlacementPanel(FinCodexPlugin::make()->helpCenterPlacement(HelpCenterPlacement::Both), 'refused');

    expect(HelpCenter::canAccess())->toBeFalse()
        ->and(finCodexPlacementEntry($panel)?->isVisible())->toBeFalse()
        ->and(HelpCenter::shouldRegisterNavigation())->toBeFalse();
});

it('reads the navigation item\'s group, sort, label and icon from the plugin', function (): void {
    finCodexPlacementUser();

    finCodexPlacementPanel(
        FinCodexPlugin::make()
            ->helpCenterPlacement(HelpCenterPlacement::Navigation)
            ->helpCenterNavigationGroup('Reading')
            ->helpCenterNavigationSort(10)
            ->helpCenterNavigationLabel('Manual')
            ->helpCenterNavigationIcon(Heroicon::OutlinedAcademicCap)
            // The three authoring screens' own options, which the Help Center
            // must go on ignoring.
            ->navigationGroup('Support')
            ->navigationSort(5),
        'configured',
    );

    expect(HelpCenter::getNavigationGroup())->toBe('Reading')
        ->and(HelpCenter::getNavigationSort())->toBe(10)
        ->and(HelpCenter::getNavigationLabel())->toBe('Manual')
        ->and(HelpCenter::getNavigationIcon())->toBe(Heroicon::OutlinedAcademicCap);
});

it('leaves the page registered and answering under None', function (): void {
    // The roadmap's own criterion: None withholds the two menu entries and
    // nothing else. Driven on a fixture panel rather than a built one because
    // only a fixture panel has live routes, and the point of the row is that
    // the URL still answers.
    $panel = test()->usesPanel('portal', finCodexPlacementUser());

    finCodexPlacementPluginOf('portal')->helpCenterPlacement(HelpCenterPlacement::None);

    expect(finCodexPlacementEntry($panel)?->isVisible())->toBeFalse()
        ->and(array_keys($panel->getUserMenuItems()))->not->toContain('fin-codex-help-center')
        ->and(HelpCenter::shouldRegisterNavigation())->toBeFalse()
        ->and(array_values($panel->getPages()))->toContain(HelpCenter::class);

    $this->get(route('filament.portal.pages.help'))->assertOk();
});

it('sorts the entry after Profile, so the menu keeps the viewer\'s name as its header', function (): void {
    // Correction 3's regression guard. At sort -2 our entry becomes the FIRST
    // item of the block Filament groups on a negative sort, and because it
    // carries a URL the dropdown stops treating the viewer's name as its
    // header on every panel without a profile page. Staff is such a panel.
    test()->usesPanel('staff', finCodexPlacementUser('staff', 'staff-placement@example.com'));

    expect(array_keys(Filament::getPanel('staff')->getUserMenuItems()))
        ->toBe(['profile', 'fin-codex-help-center', 'logout']);
});

it('does not accumulate the user-menu entry across boots', function (): void {
    // Registration lives in register(), which runs once when the provider
    // builds the panel; boot() runs on every request and would multiply the
    // entry on a long-lived worker.
    test()->usesPanel('portal', finCodexPlacementUser());

    Filament::getPanel('portal')->boot();
    Filament::getPanel('portal')->boot();

    expect(count(finCodexPlacementEntries(Filament::getPanel('portal'))))->toBe(1);
});

it('renders no entry on a panel with no user menu and leaves the page reachable', function (): void {
    // Filament returns early before it reads the item list, so the placement
    // never gets a say — the same promise None makes, arrived at from the
    // host's side.
    Filament::getPanel('portal')->userMenu(false);

    $user = finCodexPlacementUser();

    $html = $this->actingAs($user, 'web')->get('/portal')->assertOk()->getContent();

    expect($html)->not->toContain('fi-user-menu')
        ->not->toContain('Help center');

    $this->get(route('filament.portal.pages.help'))->assertOk();
});

it('places each fixture panel\'s Help Center where its own options say', function (string $panel, string $guard, string $label, string $group, bool $inUserMenu): void {
    // The two fixture panels are the phase's only rendered proof that the
    // placement reaches a real sidebar: admin carries a literal Navigation
    // placement, staff a closure-valued Both, and both name their own group,
    // sort, label and icon. Every rendered-HTML row in the suite sees these
    // two providers, which is why this plan runs last.
    $user = finCodexPlacementUser($guard, $panel.'-render@example.com');

    $html = $this->actingAs($user, $guard)->get('/'.$panel)->assertOk()->getContent();

    test()->usesPanel($panel, $user);

    $entries = array_keys(Filament::getPanel($panel)->getUserMenuItems());

    expect($html)->toContain($label)
        ->toContain($group)
        ->and(HelpCenter::shouldRegisterNavigation())->toBeTrue();

    $inUserMenu
        ? expect($entries)->toContain('fin-codex-help-center')
        : expect($entries)->not->toContain('fin-codex-help-center');
})->with([
    'admin, in the navigation alone' => ['admin', 'web', 'Manual', 'Reading', false],
    'staff, in both surfaces' => ['staff', 'staff', 'Handbook', 'Library', true],
]);
