<?php

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\View\PanelsRenderHook;
use FinityLabs\FinCodex\Panel\CurrentPage;
use FinityLabs\FinCodex\Panel\PageIdentity;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\CreateUser;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\EditUser;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\ListUsers;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\View\PageHelpResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/*
 * The identity is derived from the route and the current panel, never from
 * render-hook scopes. Scopes are captured here only as a consistency check:
 * what CurrentPage reports must equal the first scope Filament hands the
 * BODY_END hook on every page. One panel per test method; several requests
 * to the same panel in one test are fine.
 */

/**
 * Capture what CurrentPage reports while the page renders, plus the scopes Filament hands the hook: a
 * BODY_END hook added to the panel before the request is flushed when the panel boots inside it.
 */
function finCodexCaptureIdentity(string $panel): stdClass
{
    $captured = new stdClass;
    $captured->identity = null;
    $captured->scopes = [];

    Filament::getPanel($panel)->renderHook(PanelsRenderHook::BODY_END, function (array $scopes = []) use ($captured): string {
        $captured->identity = app(CurrentPage::class)->identity();
        $captured->scopes = $scopes;

        return '';
    });

    return $captured;
}

it('identifies app pages by their page class and resource pages by their resource class', function (string $guard, string $path, string $livewireClass, ?string $resourceClass, string $pageClass, string $panel): void {
    $user = User::create(['name' => 'Tester', 'email' => $guard.'@example.com']);
    $captured = finCodexCaptureIdentity($panel);

    $this->actingAs($user, $guard)->get($path)->assertOk();

    expect($captured->identity)->toBeInstanceOf(PageIdentity::class)
        ->and($captured->identity->livewireClass)->toBe($livewireClass)
        ->and($captured->identity->resourceClass)->toBe($resourceClass)
        ->and($captured->identity->pageClass())->toBe($pageClass)
        ->and($captured->identity->panelId)->toBe($panel)
        ->and($captured->identity->guard)->toBe($guard)
        ->and($captured->identity->isSimplePage)->toBeFalse()
        ->and($captured->identity->hasPage())->toBeTrue()
        ->and($captured->identity->hasPanel())->toBeTrue()
        ->and($captured->scopes[0] ?? null)->toBe($livewireClass);

    if ($resourceClass !== null) {
        expect($captured->scopes)->toContain($resourceClass);
    }
})->with([
    'admin dashboard' => ['web', '/admin', Dashboard::class, null, Dashboard::class, 'admin'],
    'admin users index' => ['web', '/admin/users', ListUsers::class, UserResource::class, UserResource::class, 'admin'],
    'admin users create' => ['web', '/admin/users/create', CreateUser::class, UserResource::class, UserResource::class, 'admin'],
    'admin users edit' => ['web', '/admin/users/1/edit', EditUser::class, UserResource::class, UserResource::class, 'admin'],
    'admin reports' => ['web', '/admin/reports', Reports::class, null, Reports::class, 'admin'],
    'staff dashboard' => ['staff', '/staff', Dashboard::class, null, Dashboard::class, 'staff'],
]);

it('identifies the login page as a simple page under the panel guard', function (string $path, string $panel, string $guard): void {
    $captured = finCodexCaptureIdentity($panel);

    $this->get($path)->assertOk();

    expect($captured->identity)->toBeInstanceOf(PageIdentity::class)
        ->and($captured->identity->livewireClass)->toBe(Login::class)
        ->and($captured->identity->resourceClass)->toBeNull()
        ->and($captured->identity->pageClass())->toBe(Login::class)
        ->and($captured->identity->isSimplePage)->toBeTrue()
        ->and($captured->identity->panelId)->toBe($panel)
        ->and($captured->identity->guard)->toBe($guard)
        ->and($captured->scopes[0] ?? null)->toBe(Login::class);
})->with([
    'admin login' => ['/admin/login', 'admin', 'web'],
    'staff login' => ['/staff/login', 'staff', 'staff'],
]);

it('reads a Livewire 4 livewire_component route action before the controller class', function (): void {
    $route = Route::get('/macro-page', fn (): string => 'ok')->middleware('web');
    $route->setAction(array_merge($route->getAction(), ['livewire_component' => Reports::class]));

    $this->get('/macro-page')->assertOk();

    $identity = app(CurrentPage::class)->identity();

    expect($identity->livewireClass)->toBe(Reports::class)
        ->and($identity->pageClass())->toBe(Reports::class)
        ->and($identity->panelId)->toBeNull();
});

it('reports no page and no panel on a route outside every panel', function (): void {
    Route::get('/outside', fn (): string => 'ok')->middleware('web');

    $this->get('/outside')->assertOk();

    $identity = app(CurrentPage::class)->identity();
    $none = PageIdentity::none();

    expect($identity->livewireClass)->toBeNull()
        ->and($identity->resourceClass)->toBeNull()
        ->and($identity->panelId)->toBeNull()
        ->and($identity->guard)->toBeNull()
        ->and($identity->isSimplePage)->toBeFalse()
        ->and($identity->hasPage())->toBeFalse()
        ->and($identity->hasPanel())->toBeFalse()
        ->and($identity->pageClass())->toBeNull()
        ->and($identity->livewireClass)->toBe($none->livewireClass)
        ->and($identity->resourceClass)->toBe($none->resourceClass)
        ->and($identity->panelId)->toBe($none->panelId)
        ->and($identity->guard)->toBe($none->guard)
        ->and($identity->isSimplePage)->toBe($none->isSimplePage);
});

it('rejects the Livewire update route so a topbar refresh has no page', function (): void {
    $this->usesPanel('admin');

    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => str_ends_with((string) $r->getName(), 'livewire.update'));

    expect($route)->not->toBeNull()
        ->and($route->getControllerClass())->toBe(HandleRequests::class);

    $request = Request::create('/'.ltrim($route->uri(), '/'), 'POST');
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);
    app()->forgetInstance(CurrentPage::class);

    $identity = app(CurrentPage::class)->identity();

    expect($identity->livewireClass)->toBeNull()
        ->and($identity->pageClass())->toBeNull()
        ->and($identity->hasPage())->toBeFalse()
        ->and($identity->panelId)->toBe('admin')
        ->and($identity->guard)->toBe('web')
        ->and($identity->hasPanel())->toBeTrue();
});

it('memoises the identity per request object', function (): void {
    $user = User::create(['name' => 'Tester', 'email' => 'web@example.com']);

    $this->actingAs($user, 'web')->get('/admin')->assertOk();

    $a = app(CurrentPage::class)->identity();
    $b = app(CurrentPage::class)->identity();

    expect($a)->toBe($b)
        ->and($a->livewireClass)->toBe(Dashboard::class);

    $this->get('/admin/reports')->assertOk();

    $c = app(CurrentPage::class)->identity();

    expect($c)->not->toBe($a)
        ->and($c->livewireClass)->toBe(Reports::class);
});

it('offers forgetHelpMemo() to drop both request memos', function (): void {
    $this->usesPanel('admin');

    $a = app(CurrentPage::class)->identity();
    $resolver = app(PageHelpResolver::class);

    forgetHelpMemo();

    expect(app(CurrentPage::class)->identity())->not->toBe($a)
        ->and(app(PageHelpResolver::class))->not->toBe($resolver);
});
