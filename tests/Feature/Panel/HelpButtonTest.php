<?php

use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Livewire\Livewire;

/*
 * PANEL-01's spec, one panel per test method (see PanelsTest). The button is
 * the core <x-lin-codex::help-button> inside fin-codex's wire:ignore theme
 * wrapper; the wrapper's data-fin-codex-help-button attribute is the marker
 * these tests count. Pest helpers are global and a single-file run does not
 * load PluginOptionsTest, so this file carries its own plugin lookup. The
 * plugin setters and Panel::topbar() are read at render time, so flipping
 * them before the request is enough and nothing leaks between tests (fresh
 * application per test).
 */

function finCodexButtonCount(string $html, string $panel): int
{
    return substr_count($html, 'data-fin-codex-help-button="'.$panel.'"');
}

/** Two public and one authenticated article on the panel's dashboard: a signed-in user sees all three. */
function finCodexSeedDashboardArticles(string $panel): void
{
    Article::factory()->public()->withTranslation('en', ['title' => 'Dashboard basics', 'body' => 'What the dashboard shows.'])
        ->withContext(ContextType::PageClass, Dashboard::class, $panel)->create(['slug' => 'dash-one']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Dashboard widgets', 'body' => 'How to read the widgets.'])
        ->withContext(ContextType::PageClass, Dashboard::class, $panel)->create(['slug' => 'dash-two']);
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Dashboard for members', 'body' => 'Members only.'])
        ->withContext(ContextType::PageClass, Dashboard::class, $panel)->create(['slug' => 'dash-members']);
}

function finCodexWebUser(): User
{
    return User::create(['name' => 'Tester', 'email' => 'web@example.com']);
}

/** The live FinCodexPlugin a fixture panel registered; its setters are read lazily at render. */
function finCodexHelpPlugin(string $panel): FinCodexPlugin
{
    $plugin = Filament::getPanel($panel)->getPlugin('fin-codex');

    if (! $plugin instanceof FinCodexPlugin) {
        throw new RuntimeException("Panel {$panel} has no FinCodexPlugin.");
    }

    return $plugin;
}

it('shows the page\'s article count in the badge and the same count in the drawer', function (): void {
    finCodexSeedDashboardArticles('admin');

    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect(finCodexButtonCount($html, 'admin'))->toBe(1)
        ->and($html)->toMatch('/codex-help-button__badge[^>]*>3</')
        ->toContain('data-codex-page-count="3"')
        ->toContain('data-codex-page-article="dash-one"')
        ->toContain('data-codex-page-article="dash-two"')
        ->toContain('data-codex-page-article="dash-members"');
});

it('keeps the button without a badge when the page has no articles', function (): void {
    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect(finCodexButtonCount($html, 'admin'))->toBe(1)
        ->and($html)->not->toContain('codex-help-button__badge')
        ->toContain('data-codex-page-count="0"');
});

it('renders the button at the panel\'s configured topbar hook', function (string $guard, string $path, string $panel, string $position): void {
    $user = User::create(['name' => 'Tester', 'email' => $guard.'@example.com']);

    $html = $this->actingAs($user, $guard)->get($path)->assertOk()->getContent();

    $button = strpos($html, '<div wire:ignore data-fin-codex-help-button="'.$panel.'"');
    $end = strpos($html, 'class="fi-topbar-end"');
    $nav = strpos($html, 'fi-topbar');

    expect($button)->not->toBeFalse()
        ->and($end)->not->toBeFalse()
        ->and($nav)->not->toBeFalse()
        ->and($button)->toBeGreaterThan($nav)
        ->and(finCodexButtonCount($html, $panel))->toBe(1)
        ->and($html)->toContain('fi-body-has-topbar');

    if ($position === 'after') {
        expect($button)->toBeGreaterThan($end);
    } else {
        expect($button)->toBeLessThan($end);
    }
})->with([
    'admin at TOPBAR_END' => ['web', '/admin', 'admin', 'after'],
    'staff at TOPBAR_START' => ['staff', '/staff', 'staff', 'before'],
]);

it('falls back to the sidebar footer when the panel has no topbar and no explicit hook', function (): void {
    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/portal')->assertOk()->getContent();

    expect(finCodexButtonCount($html, 'portal'))->toBe(1)
        ->and($html)->not->toContain('fi-body-has-topbar')
        ->not->toContain('class="fi-topbar-end"')
        ->and(strpos($html, 'data-fin-codex-help-button="portal"'))->toBeGreaterThan(strpos($html, 'fi-sidebar'));
});

it('decides the fallback at render time, so a topbar turned on after registration wins', function (): void {
    Filament::getPanel('portal')->topbar(true);

    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/portal')->assertOk()->getContent();

    expect(finCodexButtonCount($html, 'portal'))->toBe(1)
        ->and($html)->toContain('fi-body-has-topbar')
        ->and(strpos($html, 'data-fin-codex-help-button="portal"'))->toBeGreaterThan(strpos($html, 'class="fi-topbar-end"'));
});

it('honours an explicit hook on a panel with a topbar and never doubles the button', function (): void {
    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect(finCodexButtonCount($html, 'admin'))->toBe(1)
        ->and(substr_count($html, 'data-fin-codex-drawer="admin"'))->toBe(1);
});

it('removes the button but keeps the drawer and the shortcut with helpButton(false)', function (): void {
    finCodexHelpPlugin('admin')->helpButton(false);

    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('data-fin-codex-help-button')
        ->and(substr_count($html, 'data-fin-codex-drawer="admin"'))->toBe(1)
        ->and($html)->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => 'ctrl+/']));
});

it('keeps the guest link on the login page with helpButton(false)', function (): void {
    finCodexHelpPlugin('admin')->helpButton(false);

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect(substr_count($html, 'data-fin-codex-guest-link="admin"'))->toBe(1)
        ->and($html)->not->toContain('data-fin-codex-help-button');
});

it('labels the icon-only button for assistive tech and gives it a Filament tooltip', function (): void {
    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('aria-label="Help"')
        ->toContain('x-tooltip="{ content: &#039;Help&#039;, theme: $store.theme }"')
        ->toMatch('/class="codex-help-button[^"]*fin-codex-help-button"/')
        ->not->toContain('codex-help-button--labelled')
        ->not->toContain('codex-help-button__label');
});

/*
 * A refresh-topbar dispatch re-renders the Topbar component in a Livewire
 * update request, where CurrentPage sees no page: the server renders the
 * button badge-less by design and wire:ignore keeps the first paint's badge
 * on the client. The unit-test endpoint is the same kind of request, so the
 * final assertion documents that locked behaviour rather than a bug.
 */
it('keeps the button through a refresh-topbar re-render of the Topbar component', function (): void {
    $this->usesPanel('admin', finCodexWebUser());

    Livewire::test(Topbar::class)
        ->assertSeeHtml('<div wire:ignore data-fin-codex-help-button="admin"')
        ->dispatch('refresh-topbar')
        ->assertSeeHtml('<div wire:ignore data-fin-codex-help-button="admin"')
        ->assertDontSeeHtml('codex-help-button__badge');
});

it('passes each panel\'s own shortcut and width to its drawer', function (string $guard, string $path, string $shortcut, int $width, string $other): void {
    $user = User::create(['name' => 'Tester', 'email' => $guard.'@example.com']);

    $html = $this->actingAs($user, $guard)->get($path)->assertOk()->getContent();

    expect($html)->toContain('data-fin-codex-shortcut="'.$shortcut.'"')
        ->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => $shortcut]))
        ->not->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => $other]))
        ->toContain('--codex-drawer-width: '.$width.'px');
})->with([
    'admin: ctrl+/ and 480px' => ['web', '/admin', 'ctrl+/', 480, 'ctrl+.'],
    'staff: ctrl+. and 360px' => ['staff', '/staff', 'ctrl+.', 360, 'ctrl+/'],
]);

it('disables the shortcut on a panel configured with shortcut(null)', function (): void {
    finCodexHelpPlugin('admin')->shortcut(null);

    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('data-fin-codex-shortcut=""')
        ->toContain('\u0022shortcut\u0022:null')
        ->not->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => 'ctrl+/']))
        ->and(substr_count($html, 'data-fin-codex-drawer="admin"'))->toBe(1);
});

it('disables the shortcut on a panel configured with an empty shortcut', function (): void {
    finCodexHelpPlugin('admin')->shortcut('');

    $html = $this->actingAs(finCodexWebUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('data-fin-codex-shortcut=""')
        ->toContain('\u0022shortcut\u0022:null')
        ->not->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => 'ctrl+/']))
        ->and(substr_count($html, 'data-fin-codex-drawer="admin"'))->toBe(1);
});
