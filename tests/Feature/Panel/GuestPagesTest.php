<?php

use Filament\Facades\Filament;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use Illuminate\Support\Facades\URL;

/*
 * PANEL-05's spec. The guest entry point is the labelled "Need help?" link
 * HelpMount::guestLink() renders at SIMPLE_PAGE_END, inside the simple page
 * card after the form content; the drawer itself comes from the same BODY_END
 * hook as on app pages. guestDrawer(false) removes both on simple-layout pages
 * and leaves signed-in pages alone. One panel per test method (see PanelsTest).
 */

function finCodexGuestLinkCount(string $html, string $panel): int
{
    return substr_count($html, 'data-fin-codex-guest-link="'.$panel.'"');
}

function finCodexGuestPlugin(string $panel): FinCodexPlugin
{
    $plugin = Filament::getPanel($panel)->getPlugin('fin-codex');

    expect($plugin)->toBeInstanceOf(FinCodexPlugin::class);

    /** @var FinCodexPlugin $plugin */
    return $plugin;
}

it('mounts the drawer and a help link under the form on every simple-layout auth page', function (string $path, string $panel, string $guard): void {
    $html = $this->get($path)->assertOk()->getContent();

    expect(substr_count($html, 'data-fin-codex-drawer="'.$panel.'"'))->toBe(1)
        ->and(finCodexGuestLinkCount($html, $panel))->toBe(1)
        ->and($html)->toContain(__('fin-codex::fin-codex.guest.link'))
        ->toContain('codex-help-button--labelled')
        ->toMatch('/<div[^>]*data-fin-codex-guest-link="'.$panel.'"[^>]*data-fin-codex-guard="'.$guard.'"/')
        ->toMatch('/<div[^>]*data-fin-codex-drawer="'.$panel.'"[^>]*data-fin-codex-guard="'.$guard.'"/')
        ->not->toContain('data-fin-codex-help-button')
        ->toContain('data-codex-page-count="0"');

    $link = strpos($html, 'data-fin-codex-guest-link="'.$panel.'"');

    expect($link)->toBeGreaterThan(strpos($html, 'fi-simple-page-content'))
        ->toBeLessThan(strpos($html, '</main>'));
})->with([
    'admin login' => ['/admin/login', 'admin', 'web'],
    'admin register' => ['/admin/register', 'admin', 'web'],
    'admin password reset request' => ['/admin/password-reset/request', 'admin', 'web'],
    'staff login' => ['/staff/login', 'staff', 'staff'],
    'staff register' => ['/staff/register', 'staff', 'staff'],
    'staff password reset request' => ['/staff/password-reset/request', 'staff', 'staff'],
]);

it('mounts the drawer and the link on the signed password-reset page', function (): void {
    $url = URL::signedRoute('filament.admin.auth.password-reset.reset', ['email' => 'guest@example.com', 'token' => 'token']);

    $html = $this->get($url)->assertOk()->getContent();

    expect(substr_count($html, 'data-fin-codex-drawer="admin"'))->toBe(1)
        ->and(finCodexGuestLinkCount($html, 'admin'))->toBe(1)
        ->and($html)->not->toContain('data-fin-codex-help-button');
});

it('renders no drawer, no link and no drawer script for guests when guestDrawer is off', function (): void {
    finCodexGuestPlugin('admin')->guestDrawer(false);

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->not->toContain('data-fin-codex-drawer')
        ->not->toContain('data-fin-codex-guest-link')
        ->not->toContain('data-codex-drawer')
        ->not->toContain('x-data="codexDrawer(')
        ->toContain('/codex/assets/codex.css?v=');
});

it('keeps the drawer and the button on signed-in pages when guestDrawer is off', function (): void {
    finCodexGuestPlugin('admin')->guestDrawer(false);
    $user = User::create(['name' => 'Tester', 'email' => 'web@example.com']);

    $html = $this->actingAs($user, 'web')->get('/admin')->assertOk()->getContent();

    expect(substr_count($html, 'data-fin-codex-drawer="admin"'))->toBe(1)
        ->and(substr_count($html, 'data-fin-codex-help-button="admin"'))->toBe(1)
        ->and($html)->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => 'ctrl+/']));
});

it('evaluates a closure-valued guestDrawer per request', function (): void {
    finCodexGuestPlugin('admin')->guestDrawer(fn (): bool => false);

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->not->toContain('data-fin-codex-drawer')
        ->not->toContain('data-fin-codex-guest-link');
});
