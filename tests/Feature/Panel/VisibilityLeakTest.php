<?php

use Filament\Auth\Pages\Login;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Livewire\HelpDrawer;
use FinityLabs\LinCodex\Models\Article;
use Livewire\Livewire;

/*
 * PANEL-03's spec. fin-codex hands lin-codex the panel's guard and nothing
 * else: ViewerResolver turns that guard into a viewer (a guest, or a user
 * signed in on another guard counts as a guest) and ArticleGate admits
 * public articles only for guests. No fin-codex code decides visibility, so
 * these rows must agree with lin-codex's own HelpDrawerLeakTest across the
 * drawer's four read paths: the page list captured at mount, the tree,
 * search and open(slug). One panel per test method (see PanelsTest).
 */

/**
 * A public and an authenticated article, both matching the panel's login
 * route. Seeded before the first request, so no memo needs dropping.
 */
function finCodexSeedLoginArticles(string $panel): void
{
    Article::factory()->public()->withTranslation('en', ['title' => 'Signing in', 'body' => 'Public login help.'])
        ->withContext(ContextType::Route, 'filament.'.$panel.'.auth.login', $panel)->create(['slug' => 'signing-in']);
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Staff secret', 'body' => 'The secret body.'])
        ->withContext(ContextType::Route, 'filament.'.$panel.'.auth.login', $panel)->create(['slug' => 'staff-secret']);
}

/** One authenticated article on the panel's dashboard class. */
function finCodexSeedDashboardSecret(string $panel): void
{
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Dashboard secret', 'body' => 'The dashboard body.'])
        ->withContext(ContextType::PageClass, Dashboard::class, $panel)->create(['slug' => 'dash-secret']);
}

it('shows a guest only the public article on the login page', function (): void {
    finCodexSeedLoginArticles('admin');

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain('data-codex-page-count="1"')
        ->toContain('data-codex-page-article="signing-in"')
        ->not->toContain('data-codex-page-article="staff-secret"')
        ->not->toContain('Staff secret')
        ->not->toContain('The secret body.');
});

it('treats a user signed in on another guard as a guest on the staff login page', function (): void {
    finCodexSeedLoginArticles('staff');
    $web = User::create(['name' => 'Web', 'email' => 'web@example.com']);

    $html = $this->actingAs($web, 'web')->get('/staff/login')->assertOk()->getContent();

    expect($html)->toContain('data-fin-codex-guard="staff"')
        ->toContain('data-codex-page-count="1"')
        ->toContain('data-codex-page-article="signing-in"')
        ->not->toContain('data-codex-page-article="staff-secret"')
        ->not->toContain('Staff secret')
        ->not->toContain('The secret body.');
});

it('treats a user signed in on the staff guard as a guest on the admin login page', function (): void {
    finCodexSeedLoginArticles('admin');
    $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com']);

    $html = $this->actingAs($staff, 'staff')->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain('data-fin-codex-guard="web"')
        ->toContain('data-codex-page-count="1"')
        ->toContain('data-codex-page-article="signing-in"')
        ->not->toContain('data-codex-page-article="staff-secret"')
        ->not->toContain('Staff secret')
        ->not->toContain('The secret body.');
});

it('shows an authenticated article to a user signed in on the panel\'s own guard', function (string $guard, string $path, string $panel): void {
    finCodexSeedDashboardSecret($panel);
    $user = User::create(['name' => 'Member', 'email' => $guard.'@example.com']);

    $html = $this->actingAs($user, $guard)->get($path)->assertOk()->getContent();

    expect($html)->toMatch('/codex-help-button__badge[^>]*>1</')
        ->toContain('data-codex-page-count="1"')
        ->toContain('data-codex-page-article="dash-secret"')
        ->toContain('Dashboard secret');
})->with([
    'admin on web' => ['web', '/admin', 'admin'],
    'staff on staff' => ['staff', '/staff', 'staff'],
]);

it('never leaks the authenticated article through the tree, search or a direct open under the other guard', function (): void {
    finCodexSeedLoginArticles('staff');
    $web = User::create(['name' => 'Web', 'email' => 'web@example.com']);
    $this->actingAs($web, 'web');

    Livewire::test(HelpDrawer::class, ['pageClass' => Login::class, 'panelId' => 'staff', 'guard' => 'staff'])
        ->assertSet('guard', 'staff')
        ->call('goTo', 'tree')
        ->assertSeeHtml('data-codex-tree-node="signing-in"')
        ->assertDontSeeHtml('data-codex-tree-node="staff-secret"')
        ->call('goTo', 'search')
        ->set('query', 'secret')
        ->assertDontSee('Staff secret')
        ->assertDontSee('The secret body.')
        ->call('open', 'staff-secret')
        ->assertSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertDontSee('The secret body.');
});

it('opens the authenticated article for a user signed in on the panel guard', function (): void {
    finCodexSeedLoginArticles('staff');
    $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com']);
    $this->actingAs($staff, 'staff');

    Livewire::test(HelpDrawer::class, ['pageClass' => Login::class, 'panelId' => 'staff', 'guard' => 'staff'])
        ->assertSet('guard', 'staff')
        ->call('open', 'staff-secret')
        ->assertDontSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertSee('The secret body.')
        ->call('goTo', 'tree')
        ->assertSeeHtml('data-codex-tree-node="staff-secret"')
        ->assertSeeHtml('data-codex-tree-node="signing-in"')
        ->call('goTo', 'search')
        ->set('query', 'secret')
        ->assertSee('Staff secret');
});

it('hands the panel guard, not the default guard, to the link and the drawer', function (): void {
    finCodexSeedLoginArticles('staff');
    $web = User::create(['name' => 'Web', 'email' => 'web@example.com']);

    $html = $this->actingAs($web, 'web')->get('/staff/login')->assertOk()->getContent();

    expect($html)->toMatch('/<div[^>]*data-fin-codex-guest-link="staff"[^>]*data-fin-codex-guard="staff"/')
        ->toMatch('/<div[^>]*data-fin-codex-drawer="staff"[^>]*data-fin-codex-guard="staff"/')
        ->not->toContain('data-fin-codex-guard="web"')
        ->and(auth('web')->check())->toBeTrue()
        ->and(auth('staff')->check())->toBeFalse()
        ->and(config('auth.defaults.guard'))->toBe('web');
});
