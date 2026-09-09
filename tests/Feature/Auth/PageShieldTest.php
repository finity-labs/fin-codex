<?php

declare(strict_types=1);

use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\ShieldedHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\UnregisteredShieldedHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Shield\ShieldStub;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;

/*
 * AUTH-02: Traits\HasPageShieldSupport on the settings and coverage pages.
 *
 * Two branches, both proven here, neither of them needing
 * bezhansalleh/filament-shield in require-dev:
 *
 *   - no Shield: the page is open unless the host defined the Gate ability
 *     page_HelpSettings / page_HelpCoverage, which is FIN-CODEX'S OWN
 *     convention (Shield 4's real key is 'View:HelpSettings');
 *   - Shield present: the page asks Shield for its permission key and checks
 *     that permission on the user.
 *
 * The Shield branch runs on a fixture subclass that overrides the trait's two
 * protected seams, NOT on a stubbed BezhanSalleh\* class. class_exists() is
 * process-global, so a real stub would flip every other test in the file — and
 * in the suite — onto the Shield branch depending on load order. That is the
 * whole reason the rows below stay green under --order-by=random.
 */

/** A signed-out fixture user; nothing here needs more than being authenticatable. */
function finCodexShieldUser(string $email = 'shield@example.com'): User
{
    return User::create(['name' => 'Shield', 'email' => $email]);
}

/**
 * Portal is the panel that carries the BUILT-IN pages (admin and staff name
 * their own subclasses through settingsPage()/coveragePage(), and the ability
 * is named after class_basename, so their hook is page_AdminHelpSettings).
 * Both pages read plugin options through FinCodexPlugin::get(), which throws
 * off-panel, and the Shield branch calls Filament::auth() unguarded.
 */
function finCodexShieldOnPortal(): User
{
    $user = finCodexShieldUser();

    test()->usesPanel('portal', $user);

    return $user;
}

/*
 * -----------------------------------------------------------------------
 * No Shield: open by default, and gated by an opt-in Gate ability.
 * -----------------------------------------------------------------------
 */

it('leaves both pages open when no gate ability is defined', function (): void {
    finCodexShieldOnPortal();

    expect(Gate::has('page_HelpSettings'))->toBeFalse()
        ->and(Gate::has('page_HelpCoverage'))->toBeFalse()
        ->and(HelpSettings::canAccess())->toBeTrue()
        ->and(HelpCoverage::canAccess())->toBeTrue();
});

it('denies the settings page through a defined gate ability', function (): void {
    finCodexShieldOnPortal();

    Gate::define('page_HelpSettings', fn (): bool => false);

    expect(HelpSettings::canAccess())->toBeFalse();
});

it('grants the settings page through a defined gate ability', function (): void {
    finCodexShieldOnPortal();

    Gate::define('page_HelpSettings', fn (): bool => true);

    expect(HelpSettings::canAccess())->toBeTrue();
});

it('denies the coverage page through its own gate ability', function (): void {
    finCodexShieldOnPortal();

    Gate::define('page_HelpCoverage', fn (): bool => false);

    expect(HelpCoverage::canAccess())->toBeFalse();
});

it('grants the coverage page through its own gate ability', function (): void {
    finCodexShieldOnPortal();

    Gate::define('page_HelpCoverage', fn (): bool => true);

    expect(HelpCoverage::canAccess())->toBeTrue();
});

it('gates the two pages independently of each other', function (): void {
    finCodexShieldOnPortal();

    // Only the settings ability is defined; coverage must not inherit the no.
    Gate::define('page_HelpSettings', fn (): bool => false);

    expect(HelpSettings::canAccess())->toBeFalse()
        ->and(HelpCoverage::canAccess())->toBeTrue();

    // And the other way round, on abilities that now both exist.
    Gate::define('page_HelpCoverage', fn (): bool => false);
    Gate::define('page_HelpSettings', fn (): bool => true);

    expect(HelpSettings::canAccess())->toBeTrue()
        ->and(HelpCoverage::canAccess())->toBeFalse();
});

/*
 * -----------------------------------------------------------------------
 * A denied page disappears rather than merely refusing.
 * -----------------------------------------------------------------------
 */

it('drops a denied page from navigation', function (): void {
    finCodexShieldOnPortal();

    expect(HelpSettings::shouldRegisterNavigation())->toBeTrue()
        ->and(HelpCoverage::shouldRegisterNavigation())->toBeTrue();

    Gate::define('page_HelpSettings', fn (): bool => false);

    expect(HelpSettings::shouldRegisterNavigation())->toBeFalse()
        // The coverage item is the control: navigation is still being built.
        ->and(HelpCoverage::shouldRegisterNavigation())->toBeTrue();
});

/*
 * The next two rows are ONE render each, and deliberately not one test with a
 * before/after pair. Page::registerNavigationItems() pushes onto the Panel's
 * own $navigationItems array, and the Panel is a singleton for the PHP
 * request; a second render in the same process re-reads that array rather than
 * re-asking shouldRegisterNavigation(). So the gate decides when navigation is
 * registered, once, and a control render inside the deny test would leave the
 * item behind and fail it for the wrong reason. Testbench rebuilds the
 * application per test, which is what keeps these two independent.
 */

it('lists both pages in the rendered panel when nothing is denied', function (): void {
    $this->actingAs(finCodexShieldUser())
        ->get(route('filament.portal.pages.dashboard'))
        ->assertOk()
        ->assertSee((string) __('fin-codex::fin-codex.settings.navigation'))
        ->assertSee((string) __('fin-codex::fin-codex.coverage.navigation'));
});

it('stops listing a denied page in the rendered panel', function (): void {
    Gate::define('page_HelpSettings', fn (): bool => false);

    $this->actingAs(finCodexShieldUser())
        ->get(route('filament.portal.pages.dashboard'))
        ->assertOk()
        ->assertDontSee((string) __('fin-codex::fin-codex.settings.navigation'))
        // Still there: only the denied page went away.
        ->assertSee((string) __('fin-codex::fin-codex.coverage.navigation'));
});

it('refuses the denied page on its own route', function (): void {
    $user = finCodexShieldUser();

    $url = route('filament.portal.pages.codex-settings');

    $this->actingAs($user)->get($url)->assertOk();

    Gate::define('page_HelpSettings', fn (): bool => false);

    // Filament's own mountCanAuthorizeAccess() aborts on canAccess().
    $this->actingAs($user)->get($url)->assertForbidden();
});

/*
 * -----------------------------------------------------------------------
 * Shield present: the key comes from Shield, the answer from the user.
 * -----------------------------------------------------------------------
 */

it('asks shield for the page permission rather than building the name', function (): void {
    finCodexShieldOnPortal();

    $permission = (new ReflectionMethod(ShieldedHelpSettings::class, 'getPagePermission'))
        ->invoke(null);

    // Shield 4's shape, not Shield 3's page_{Class}.
    expect($permission)->toBe(ShieldStub::PERMISSION)
        ->toBe('View:HelpSettings')
        ->not->toBe('page_ShieldedHelpSettings');
});

it('grants the page when the user has shield\'s permission', function (): void {
    finCodexShieldOnPortal();

    Gate::define(ShieldStub::PERMISSION, fn (): bool => true);

    expect(ShieldedHelpSettings::canAccess())->toBeTrue()
        ->and(ShieldedHelpSettings::shouldRegisterNavigation())->toBeTrue();
});

it('denies the page when the user lacks shield\'s permission', function (): void {
    finCodexShieldOnPortal();

    Gate::define(ShieldStub::PERMISSION, fn (): bool => false);

    expect(ShieldedHelpSettings::canAccess())->toBeFalse()
        ->and(ShieldedHelpSettings::shouldRegisterNavigation())->toBeFalse();
});

it('denies the page when shield\'s permission was never granted to anyone', function (): void {
    finCodexShieldOnPortal();

    // No ability, no policy: Laravel answers no, which is Shield's own
    // behaviour for a permission the user's roles do not carry.
    expect(Gate::has(ShieldStub::PERMISSION))->toBeFalse()
        ->and(ShieldedHelpSettings::canAccess())->toBeFalse();
});

it('stays open when shield has no permission registered for the page', function (): void {
    finCodexShieldOnPortal();

    $permission = (new ReflectionMethod(UnregisteredShieldedHelpSettings::class, 'getPagePermission'))
        ->invoke(null);

    expect($permission)->toBeNull()
        // parent::canAccess(), i.e. open — a page shield has never heard of
        // must not lock everybody out.
        ->and(UnregisteredShieldedHelpSettings::canAccess())->toBeTrue();
});

it('ignores the gate hook entirely while shield is available', function (): void {
    finCodexShieldOnPortal();

    // The no-Shield hook, named after this fixture class, saying no.
    Gate::define('page_ShieldedHelpSettings', fn (): bool => false);
    Gate::define(ShieldStub::PERMISSION, fn (): bool => true);

    expect(ShieldedHelpSettings::canAccess())->toBeTrue();
});

/*
 * -----------------------------------------------------------------------
 * The point of the fixture design: no Shield class is ever defined.
 * -----------------------------------------------------------------------
 */

it('defines no shield class anywhere in the suite', function (): void {
    // Plain strings, never ::class and never a use import: 08-04 settled that
    // Shield is named as a string everywhere in this package, and here it also
    // keeps Pint's fully_qualified_strict_types fixer from hoisting a
    // BezhanSalleh import into the one file whose point is that there is none.
    // The second argument is false, so nothing is autoloaded either way.
    expect(class_exists('BezhanSalleh\FilamentShield\FilamentShieldPlugin', false))->toBeFalse()
        ->and(class_exists('BezhanSalleh\FilamentShield\Facades\FilamentShield', false))->toBeFalse()
        // ...so the real pages are on the Gate branch, whatever ran before this.
        ->and((new ReflectionMethod(HelpSettings::class, 'isShieldAvailable'))->invoke(null))->toBeFalse()
        ->and((new ReflectionMethod(HelpCoverage::class, 'isShieldAvailable'))->invoke(null))->toBeFalse();
});
