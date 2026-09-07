<?php

use Filament\Auth\Pages\Login;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;

/*
 * One panel per test method (see PanelsTest): FilamentManager boots only the
 * first panel of a request cycle, so each dataset row gets a fresh application.
 * Phase 1's hidden marker became the drawer mount; the wrapper's data-fin-codex-*
 * attributes are the markers these tests count.
 */

function drawerCount(string $html, string $panel): int
{
    return substr_count($html, 'data-fin-codex-drawer="'.$panel.'"');
}

it('mounts exactly one drawer with the panel\'s own shortcut and width on the login page', function (string $path, string $panel, string $guard, string $shortcut, int $width, string $other): void {
    $html = $this->get($path)->assertOk()->getContent();

    expect(drawerCount($html, $panel))->toBe(1)
        ->and(drawerCount($html, $other))->toBe(0)
        ->and(substr_count($html, 'data-codex-drawer'))->toBe(1)
        ->and($html)->toContain('data-fin-codex-shortcut="'.$shortcut.'"')
        ->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => $shortcut]))
        ->toContain('--codex-drawer-width: '.$width.'px')
        ->toContain('data-fin-codex-page="'.Login::class.'"')
        ->toContain('data-fin-codex-guard="'.$guard.'"');
})->with([
    'admin' => ['/admin/login', 'admin', 'web', 'ctrl+/', 480, 'staff'],
    'staff' => ['/staff/login', 'staff', 'staff', 'ctrl+.', 360, 'admin'],
]);

it('mounts exactly one drawer carrying the page identity on an authenticated page', function (string $guard, string $path, string $panel, string $pageClass, ?string $resourceClass, string $other): void {
    $user = User::create(['name' => 'Tester', 'email' => $guard.'@example.com']);

    $html = $this->actingAs($user, $guard)->get($path)->assertOk()->getContent();

    expect(drawerCount($html, $panel))->toBe(1)
        ->and(drawerCount($html, $other))->toBe(0)
        ->and(substr_count($html, 'data-codex-drawer'))->toBe(1)
        ->and($html)->toMatch('/<div[^>]*data-fin-codex-drawer="'.$panel.'"[^>]*data-fin-codex-page="'.preg_quote($pageClass, '/').'"[^>]*data-fin-codex-resource="'.preg_quote((string) $resourceClass, '/').'"[^>]*data-fin-codex-guard="'.$guard.'"/');
})->with([
    'admin dashboard on web' => ['web', '/admin', 'admin', Dashboard::class, null, 'staff'],
    'admin users list (resource identity)' => ['web', '/admin/users', 'admin', UserResource::class, UserResource::class, 'staff'],
    'admin users create (same identity as the list)' => ['web', '/admin/users/create', 'admin', UserResource::class, UserResource::class, 'staff'],
    'admin reports page (its own class)' => ['web', '/admin/reports', 'admin', Reports::class, null, 'staff'],
    'staff dashboard on staff' => ['staff', '/staff', 'staff', Dashboard::class, null, 'admin'],
]);

it('renders no drawer, no button and no stylesheet in a panel without the plugin', function (): void {
    expect($this->get('/plain/login')->assertOk()->getContent())
        ->not->toContain('data-fin-codex')
        ->not->toContain('data-codex-drawer')
        ->not->toContain('codex.css');
});

it('gives the topbar button the same identity as the drawer', function (string $guard, string $path, string $panel, string $pageClass, ?string $resourceClass): void {
    $user = User::create(['name' => 'Tester', 'email' => $guard.'@example.com']);

    $html = $this->actingAs($user, $guard)->get($path)->assertOk()->getContent();

    expect(substr_count($html, 'data-fin-codex-help-button="'.$panel.'"'))->toBe(1)
        ->and(substr_count($html, 'data-fin-codex-page="'.$pageClass.'"'))->toBe(2)
        ->and(substr_count($html, 'data-fin-codex-resource="'.(string) $resourceClass.'"'))->toBe(2)
        ->and(substr_count($html, 'data-fin-codex-guard="'.$guard.'"'))->toBe(2)
        ->and($html)->not->toContain('data-fin-codex-guest-link');
})->with([
    'admin dashboard' => ['web', '/admin', 'admin', Dashboard::class, null],
    'admin users create' => ['web', '/admin/users/create', 'admin', UserResource::class, UserResource::class],
    'staff dashboard' => ['staff', '/staff', 'staff', Dashboard::class, null],
]);

it('shows the same article count in the badge and in the drawer on every page of a resource', function (string $path): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Managing users', 'body' => 'How to manage users.'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin')->create(['slug' => 'managing-users']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Inviting users', 'body' => 'How to invite users.'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin')->create(['slug' => 'inviting-users']);
    $user = User::create(['name' => 'Tester', 'email' => 'web@example.com']);

    $html = $this->actingAs($user, 'web')->get($path)->assertOk()->getContent();

    expect($html)->toMatch('/codex-help-button__badge[^>]*>2</')
        ->toContain('data-codex-page-count="2"')
        ->toContain('data-codex-page-article="managing-users"')
        ->toContain('data-codex-page-article="inviting-users"');
})->with(['/admin/users', '/admin/users/create']);

it('renders the guest help link under the login form and no topbar button', function (): void {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect(substr_count($html, 'data-fin-codex-guest-link="admin"'))->toBe(1)
        ->and($html)->toContain(__('fin-codex::fin-codex.guest.link'))
        ->toContain('codex-help-button--labelled')
        ->not->toContain('data-fin-codex-help-button');
});
