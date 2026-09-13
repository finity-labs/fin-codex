<?php

use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelRegistry;
use FinityLabs\FinCodex\Scope\ContextPanels;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\ListUsers;
use FinityLabs\LinCodex\Data\ContextData;
use FinityLabs\LinCodex\Enums\ContextType;

/*
 * SCOPE-02's resolution rules over the fixture registry: admin /admin, staff
 * /staff, portal /portal and plain /plain, registered in that order, with
 * UserResource and the Reports page in admin and staff and Dashboard in all
 * four. No panel is booted here — the registry is complete once the panel
 * providers have registered and the resolver asks nothing of the request.
 */

/** The shared resolver, as every consumer resolves it. */
function finCodexPanels(): ContextPanels
{
    return app(ContextPanels::class);
}

/**
 * Register a panel after the providers have booted. The facade's own
 * registerPanel() is not usable here: it defers the registration into a
 * container resolving() callback for PanelRegistry, and that singleton is
 * long resolved by the time a test runs, so the call would be a silent
 * no-op. The registry takes a late panel directly.
 */
function finCodexRegisterPanel(Panel $panel): void
{
    app(PanelRegistry::class)->register($panel);
}

it('answers the panel that owns a filament route name', function (): void {
    $panels = finCodexPanels();

    expect($panels->forRoute('filament.admin.pages.dashboard'))->toBe('admin')
        ->and($panels->forRoute('filament.staff.resources.users.index'))->toBe('staff');
});

it('answers null for a route name no panel owns', function (): void {
    expect(finCodexPanels()->forRoute('shop.index'))->toBeNull();
});

it('does not let a panel id that merely prefixes another claim its routes', function (): void {
    // The trailing dot is what keeps `admin` off `filament.adminx.*`.
    expect(finCodexPanels()->forRoute('filament.adminx.foo'))->toBeNull();
});

it('credits a page class to every panel that registers it', function (): void {
    expect(finCodexPanels()->forClass(Dashboard::class))
        ->toHaveCount(4)
        ->toContain('admin', 'staff', 'portal', 'plain');
});

it('credits a resource to the panels that register it, in registry order', function (): void {
    expect(finCodexPanels()->forClass(UserResource::class))->toBe(['admin', 'staff']);
});

it('credits a resource page to the panels that register its resource', function (): void {
    expect(finCodexPanels()->forClass(ListUsers::class))->toBe(['admin', 'staff']);
});

it('ignores a leading backslash on a class key', function (): void {
    expect(finCodexPanels()->forClass('\\'.UserResource::class))->toBe(['admin', 'staff']);
});

it('answers the empty list for a class no panel registers', function (): void {
    expect(finCodexPanels()->forClass('App\\Nowhere'))->toBe([]);
});

it('claims a url pattern by the panel path prefix', function (): void {
    $panels = finCodexPanels();

    expect($panels->forUrl('/admin/users'))->toBe('admin')
        ->and($panels->forUrl('/admin'))->toBe('admin')
        ->and($panels->forUrl('admin/users/'))->toBe('admin')
        ->and($panels->forUrl('/staff/x/y'))->toBe('staff');
});

it('answers null for a url pattern no panel path claims', function (): void {
    $panels = finCodexPanels();

    // The prefix has to end at a segment boundary, so /adminx is not admin's.
    expect($panels->forUrl('/shop'))->toBeNull()
        ->and($panels->forUrl('/adminx/foo'))->toBeNull();
});

it('answers null for a url pattern whose first segment is a wildcard', function (): void {
    $panels = finCodexPanels();

    expect($panels->forUrl('/*/users'))->toBeNull()
        ->and($panels->forUrl('/**'))->toBeNull();
});

it('lets a root-path panel claim only what no other panel claims', function (): void {
    $panels = finCodexPanels();

    expect($panels->forUrl('/shop'))->toBeNull();

    finCodexRegisterPanel(Panel::make()->id('root')->path(''));

    // The path list is memoised like the class index, so the answer only
    // changes once the memo is dropped.
    expect($panels->forUrl('/shop'))->toBeNull();

    $panels->forget();

    expect($panels->forUrl('/shop'))->toBe('root')
        ->and($panels->forUrl('/admin/users'))->toBe('admin');
});

it('builds the class index once and rebuilds it after forget', function (): void {
    $panels = finCodexPanels();

    expect($panels->forClass(Dashboard::class))->toHaveCount(4);

    finCodexRegisterPanel(Panel::make()->id('extra')->path('extra')->pages([Dashboard::class]));

    expect($panels->forClass(Dashboard::class))->toHaveCount(4);

    $panels->forget();

    expect($panels->forClass(Dashboard::class))
        ->toHaveCount(5)
        ->toContain('extra');
});

it('lets an explicit panel prefix win over resolution', function (): void {
    $context = new ContextData(ContextType::Route, 'shop.index', 'staff');

    expect(finCodexPanels()->forContext($context))->toBe(['staff']);
});

/*
 * The next two rows pin the question this class answers: which panel does a
 * screen file under, and which screens belong to none. The coverage report
 * reads those answers and the Help Center's panel filter will offer them, so
 * they are unchanged by 14-05 — what changed there is that the panel scope no
 * longer puts the question at all for a context carrying no panel of its own.
 * A panel-less context does not restrict a reader; it still files under
 * whatever its key resolves into, which is what these rows hold.
 */
it('resolves a context without a panel prefix by its type', function (): void {
    $panels = finCodexPanels();

    expect($panels->forContext(ContextData::fromString('route:filament.admin.pages.dashboard')))->toBe(['admin'])
        ->and($panels->forContext(ContextData::fromString('url:/staff/reports')))->toBe(['staff'])
        ->and($panels->forContext(ContextData::fromString('class:'.Dashboard::class)))->toHaveCount(4);
});

it('answers the outside-panels bucket with the empty list', function (): void {
    $panels = finCodexPanels();

    expect($panels->forContext(ContextData::fromString('route:shop.index')))->toBe([])
        ->and($panels->forContext(ContextData::fromString('class:App\\Nowhere')))->toBe([])
        ->and($panels->forContext(ContextData::fromString('url:/**')))->toBe([]);
});

it('names the outside-panels bucket', function (): void {
    expect(ContextPanels::OUTSIDE_PANELS)->toBe('__outside');
});
