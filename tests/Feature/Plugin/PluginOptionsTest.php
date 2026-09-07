<?php

use Filament\Facades\Filament;
use Filament\View\PanelsRenderHook;
use FinityLabs\FinCodex\Enums\NavigationGroup;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\StaffHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\StaffHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;

/** @return array<string, mixed> Every option as the panel reports it. */
function pluginOptions(FinCodexPlugin $plugin): array
{
    return [
        'helpButtonRenderHook' => $plugin->getHelpButtonRenderHook(),
        'shortcut' => $plugin->getShortcut(),
        'drawerWidth' => $plugin->getDrawerWidth(),
        'globalSearch' => $plugin->hasGlobalSearch(),
        'navigationGroup' => $plugin->getNavigationGroup(),
        'navigationSort' => $plugin->getNavigationSort(),
        'articleResource' => $plugin->getArticleResource(),
        'settingsPage' => $plugin->getSettingsPage(),
        'coveragePage' => $plugin->getCoveragePage(),
        'policyNamespace' => $plugin->getPolicyNamespace(),
    ];
}

/** @return array<string, mixed> The literal values AdminPanelProvider sets. */
function adminOptions(): array
{
    return [
        'helpButtonRenderHook' => PanelsRenderHook::TOPBAR_END,
        'shortcut' => 'ctrl+/',
        'drawerWidth' => 480,
        'globalSearch' => false,
        'navigationGroup' => 'Help',
        'navigationSort' => 90,
        'articleResource' => AdminHelpArticleResource::class,
        'settingsPage' => AdminHelpSettings::class,
        'coveragePage' => AdminHelpCoverage::class,
        'policyNamespace' => 'App\\Policies',
    ];
}

/** @return array<string, mixed> What StaffPanelProvider's closures evaluate to. */
function staffOptions(): array
{
    return [
        'helpButtonRenderHook' => PanelsRenderHook::TOPBAR_START,
        'shortcut' => 'ctrl+.',
        'drawerWidth' => 360,
        'globalSearch' => true,
        'navigationGroup' => 'Support',
        'navigationSort' => 5,
        'articleResource' => StaffHelpArticleResource::class,
        'settingsPage' => StaffHelpSettings::class,
        'coveragePage' => StaffHelpCoverage::class,
        'policyNamespace' => 'Staff\\Policies',
    ];
}

it('starts from the documented defaults on a fresh instance', function (): void {
    expect(pluginOptions(FinCodexPlugin::make()))->toBe([
        'helpButtonRenderHook' => PanelsRenderHook::TOPBAR_END,
        'shortcut' => 'ctrl+/',
        'drawerWidth' => 480,
        'globalSearch' => false,
        'navigationGroup' => NavigationGroup::Help,
        'navigationSort' => null,
        'articleResource' => null,
        'settingsPage' => null,
        'coveragePage' => null,
        'policyNamespace' => 'App\\Policies',
    ])->and(FinCodexPlugin::make())->not->toBe(FinCodexPlugin::make());
});

it('returns itself from every setter and evaluates closures in the getters', function (): void {
    $plugin = FinCodexPlugin::make();

    expect($plugin->shortcut(fn (): string => 'alt+h'))->toBe($plugin)
        ->and($plugin->globalSearch(fn (): bool => true))->toBe($plugin)
        ->and($plugin->drawerWidth(fn (): int => 600))->toBe($plugin)
        ->and($plugin->getShortcut())->toBe('alt+h')
        ->and($plugin->hasGlobalSearch())->toBeTrue()
        ->and($plugin->getDrawerWidth())->toBe(600)
        ->and($plugin->shortcut(null)->getShortcut())->toBeNull();
});

it('reads each panel\'s own values through filament(\'fin-codex\')', function (string $panel, array $expected): void {
    Filament::setCurrentPanel(Filament::getPanel($panel));

    $plugin = filament('fin-codex');

    expect($plugin)->toBeInstanceOf(FinCodexPlugin::class)
        ->and($plugin)->toBe(FinCodexPlugin::get())
        ->and(pluginOptions($plugin))->toBe($expected);
})->with([
    'admin (literal values)' => ['admin', adminOptions()],
    'staff (closure values)' => ['staff', staffOptions()],
]);

it('gives every option a different value on the two panels', function (): void {
    foreach (array_keys(adminOptions()) as $option) {
        expect(adminOptions()[$option])->not->toBe(staffOptions()[$option], "option {$option} must differ between admin and staff");
    }
});

it('throws for a panel without the plugin', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('plain'));

    expect(fn () => filament('fin-codex'))->toThrow(LogicException::class);
});

/** The plugin instance a fixture panel registered, typed for the assertions below. */
function finCodexPluginOf(string $panel): FinCodexPlugin
{
    $plugin = Filament::getPanel($panel)->getPlugin('fin-codex');

    expect($plugin)->toBeInstanceOf(FinCodexPlugin::class);

    /** @var FinCodexPlugin $plugin */
    return $plugin;
}

it('defaults helpButton and guestDrawer to on and evaluates closures', function (): void {
    $plugin = FinCodexPlugin::make();

    expect($plugin->hasHelpButton())->toBeTrue()
        ->and($plugin->hasGuestDrawer())->toBeTrue()
        ->and($plugin->helpButton(false))->toBe($plugin)
        ->and($plugin->hasHelpButton())->toBeFalse()
        ->and($plugin->helpButton(fn (): bool => true)->hasHelpButton())->toBeTrue()
        ->and($plugin->guestDrawer(fn (): bool => false))->toBe($plugin)
        ->and($plugin->hasGuestDrawer())->toBeFalse()
        ->and($plugin->guestDrawer()->hasGuestDrawer())->toBeTrue();
});

it('tracks whether the help button hook was set explicitly and keeps TOPBAR_END as the default', function (): void {
    $plugin = FinCodexPlugin::make();

    expect($plugin->hasExplicitHelpButtonRenderHook())->toBeFalse()
        ->and($plugin->getHelpButtonRenderHook())->toBe(PanelsRenderHook::TOPBAR_END);

    $plugin->helpButtonRenderHook(PanelsRenderHook::SIDEBAR_FOOTER);

    expect($plugin->hasExplicitHelpButtonRenderHook())->toBeTrue()
        ->and($plugin->getHelpButtonRenderHook())->toBe(PanelsRenderHook::SIDEBAR_FOOTER)
        ->and($plugin->helpButtonRenderHook(fn (): string => PanelsRenderHook::TOPBAR_START)->getHelpButtonRenderHook())->toBe(PanelsRenderHook::TOPBAR_START);
});

it('reads explicit hooks on admin and staff and the default on portal', function (): void {
    $portal = finCodexPluginOf('portal');

    expect(finCodexPluginOf('admin')->hasExplicitHelpButtonRenderHook())->toBeTrue()
        ->and(finCodexPluginOf('staff')->hasExplicitHelpButtonRenderHook())->toBeTrue()
        ->and($portal->hasExplicitHelpButtonRenderHook())->toBeFalse()
        ->and($portal->getHelpButtonRenderHook())->toBe(PanelsRenderHook::TOPBAR_END)
        ->and($portal->getShortcut())->toBe('ctrl+/')
        ->and($portal->getDrawerWidth())->toBe(480);
});
