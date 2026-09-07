<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Help\Declaration;
use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\CreateUser;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\EditUser;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\ListUsers;
use FinityLabs\LinCodex\Data\ContextData;

/*
 * HELP-01 over the fixture panels: the scanner walks every registered panel
 * once, asks each HasHelp resource, resource page and custom page for that
 * panel, and turns the answers into panel-scoped synthetic contexts in the
 * SORT_BASE band. No panel is booted here: the registry is complete once the
 * panel providers have registered, and the scan asks nothing of the request.
 */

function finCodexScan(): DeclaredContexts
{
    return app(DeclaredContexts::class);
}

/**
 * @param  list<ContextData>  $contexts
 *
 * @return list<string>
 */
function finCodexContextStrings(array $contexts): array
{
    return array_map(static fn (ContextData $context): string => $context->toString(), $contexts);
}

/**
 * @param  list<ContextData>  $contexts
 *
 * @return list<int>
 */
function finCodexSortOrders(array $contexts): array
{
    return array_map(static fn (ContextData $context): int => $context->sortOrder, $contexts);
}

/**
 * @return list<Declaration>
 */
function finCodexDeclarationsOf(string $class, string $panelId): array
{
    return array_values(array_filter(
        finCodexScan()->declarations(),
        static fn (Declaration $declaration): bool => $declaration->class === $class && $declaration->panelId === $panelId,
    ));
}

it('emits a class context on the resource and one route context per resource page for a resource-level declaration', function (): void {
    $declarations = finCodexDeclarationsOf(UserResource::class, 'admin');
    $keys = [
        'admin:class:'.UserResource::class,
        'admin:route:filament.admin.resources.users.index',
        'admin:route:filament.admin.resources.users.create',
        'admin:route:filament.admin.resources.users.edit',
    ];

    expect($declarations)->toHaveCount(2)
        ->and($declarations[0]->slug)->toBe('users')
        ->and($declarations[0]->position)->toBe(0)
        ->and(finCodexContextStrings($declarations[0]->contexts))->toBe($keys)
        ->and(finCodexSortOrders($declarations[0]->contexts))->toBe([-1_000_000, -1_000_000, -1_000_000, -1_000_000])
        ->and(array_map(static fn (ContextData $context): ?string => $context->panelId, $declarations[0]->contexts))->toBe(['admin', 'admin', 'admin', 'admin'])
        ->and($declarations[1]->slug)->toBe('user-roles')
        ->and($declarations[1]->position)->toBe(1)
        ->and(finCodexContextStrings($declarations[1]->contexts))->toBe($keys)
        ->and(finCodexSortOrders($declarations[1]->contexts))->toBe([-999_999, -999_999, -999_999, -999_999]);
});

it('asks a class once per panel and lets it answer differently', function (): void {
    $staff = finCodexDeclarationsOf(UserResource::class, 'staff');
    $keys = [
        'staff:class:'.UserResource::class,
        'staff:route:filament.staff.resources.users.index',
        'staff:route:filament.staff.resources.users.create',
        'staff:route:filament.staff.resources.users.edit',
    ];

    expect($staff)->toHaveCount(2)
        ->and($staff[0]->slug)->toBe('staff-users')
        ->and($staff[0]->position)->toBe(0)
        ->and(finCodexContextStrings($staff[0]->contexts))->toBe($keys)
        ->and(finCodexSortOrders($staff[0]->contexts))->toBe([-1_000_000, -1_000_000, -1_000_000, -1_000_000])
        ->and($staff[1]->slug)->toBe('users')
        ->and($staff[1]->position)->toBe(1)
        ->and(finCodexSortOrders($staff[1]->contexts))->toBe([-999_999, -999_999, -999_999, -999_999])
        ->and(array_map(static fn (Declaration $declaration): string => $declaration->slug, $staff))->not->toContain('user-roles')
        ->and(array_map(static fn (Declaration $declaration): string => $declaration->slug, finCodexDeclarationsOf(UserResource::class, 'admin')))->not->toContain('staff-users');
});

it('emits a route context on the page itself for a resource-page declaration', function (): void {
    $admin = finCodexDeclarationsOf(EditUser::class, 'admin');
    $staff = finCodexDeclarationsOf(EditUser::class, 'staff');

    expect($admin)->toHaveCount(1)
        ->and($admin[0]->slug)->toBe('editing-users')
        ->and(finCodexContextStrings($admin[0]->contexts))->toBe(['admin:route:filament.admin.resources.users.edit'])
        ->and($staff)->toHaveCount(1)
        ->and(finCodexContextStrings($staff[0]->contexts))->toBe(['staff:route:filament.staff.resources.users.edit'])
        ->and(implode(' ', [...finCodexContextStrings($admin[0]->contexts), ...finCodexContextStrings($staff[0]->contexts)]))->not->toContain('class:');
});

it('emits a class context on a custom page declaration', function (): void {
    $admin = finCodexDeclarationsOf(Reports::class, 'admin');
    $staff = finCodexDeclarationsOf(Reports::class, 'staff');

    expect($admin)->toHaveCount(1)
        ->and($admin[0]->slug)->toBe('reports')
        ->and(finCodexContextStrings($admin[0]->contexts))->toBe(['admin:class:'.Reports::class])
        ->and(finCodexSortOrders($admin[0]->contexts))->toBe([-1_000_000])
        ->and($staff)->toHaveCount(1)
        ->and(finCodexContextStrings($staff[0]->contexts))->toBe(['staff:class:'.Reports::class])
        ->and(finCodexSortOrders($staff[0]->contexts))->toBe([-1_000_000]);
});

it('skips classes without HasHelp and panels without declarations', function (): void {
    $declarations = finCodexScan()->declarations();
    $classes = array_map(static fn (Declaration $declaration): string => $declaration->class, $declarations);
    $panels = array_map(static fn (Declaration $declaration): string => $declaration->panelId, $declarations);

    expect($classes)->not->toContain(Dashboard::class)
        ->not->toContain(ListUsers::class)
        ->not->toContain(CreateUser::class)
        ->and($panels)->not->toContain('portal')
        ->not->toContain('plain')
        ->and($declarations)->toHaveCount(8)
        ->and(array_map(static fn (Declaration $declaration): string => $declaration->panelId.' '.$declaration->class.' '.$declaration->slug, $declarations))->toBe([
            'admin '.UserResource::class.' users',
            'admin '.UserResource::class.' user-roles',
            'admin '.EditUser::class.' editing-users',
            'admin '.Reports::class.' reports',
            'staff '.UserResource::class.' staff-users',
            'staff '.UserResource::class.' users',
            'staff '.EditUser::class.' editing-users',
            'staff '.Reports::class.' reports',
        ]);
});

it('groups every synthetic context by slug across panels', function (): void {
    $bySlug = finCodexScan()->contextsBySlug();
    $keys = array_keys($bySlug);
    sort($keys);

    expect($keys)->toBe(['editing-users', 'reports', 'staff-users', 'user-roles', 'users'])
        ->and(finCodexContextStrings($bySlug['users']))->toBe([
            'admin:class:'.UserResource::class,
            'admin:route:filament.admin.resources.users.index',
            'admin:route:filament.admin.resources.users.create',
            'admin:route:filament.admin.resources.users.edit',
            'staff:class:'.UserResource::class,
            'staff:route:filament.staff.resources.users.index',
            'staff:route:filament.staff.resources.users.create',
            'staff:route:filament.staff.resources.users.edit',
        ])
        ->and(finCodexSortOrders($bySlug['users']))->toBe([-1_000_000, -1_000_000, -1_000_000, -1_000_000, -999_999, -999_999, -999_999, -999_999])
        ->and(finCodexContextStrings($bySlug['reports']))->toBe(['admin:class:'.Reports::class, 'staff:class:'.Reports::class]);
});

it('memoises the scan until forget() is called', function (): void {
    $scan = finCodexScan();
    $first = $scan->declarations();
    $second = $scan->declarations();

    $scan->forget();
    $third = $scan->declarations();

    expect($second)->toBe($first)
        ->and($third)->toEqual($first)
        ->and($third[0])->not->toBe($first[0]);
});
