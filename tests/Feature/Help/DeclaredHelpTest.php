<?php

use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Coverage\RouteCoverage;
use FinityLabs\LinCodex\Coverage\RouteCoverageRow;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;

/*
 * HELP-02's spec: fin-codex adds contexts, the core orders, merges,
 * de-duplicates and filters; every expectation here is the core's outcome
 * on a real panel page. The fixture declarations come from Plan 04-01
 * (admin UserResource: users, user-roles; staff UserResource: staff-users,
 * users; EditUser: editing-users; Reports: reports). One panel per test
 * method for page requests (see PanelsTest); articles are seeded before the
 * first request so no memo needs dropping.
 */

/**
 * Every declared slug plus three stored ones: user-roles is declared and
 * stored (it must collapse to its declared position), stored-admin is a
 * panel-scoped stored context on the resource, panelless the same context
 * without a panel.
 */
function finCodexDeclaredSeed(): void
{
    $article = static fn (string $slug, string $title): Article => Article::factory()->public()
        ->withTranslation('en', ['title' => $title, 'body' => 'About '.strtolower($title).'.'])
        ->create(['slug' => $slug]);

    $article('users', 'Users');
    Article::factory()->public()->withTranslation('en', ['title' => 'User Roles', 'body' => 'About user roles.'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin', 0)->create(['slug' => 'user-roles']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Stored Admin', 'body' => 'About stored admin.'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin', 1)->create(['slug' => 'stored-admin']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Panelless', 'body' => 'About panelless.'])
        ->withContext(ContextType::PageClass, UserResource::class, null, 0)->create(['slug' => 'panelless']);
    $article('editing-users', 'Editing Users');
    $article('staff-users', 'Staff Users');
    $article('reports', 'Reports');
}

/**
 * The drawer's page list in render order.
 *
 * @return list<string>
 */
function finCodexDeclaredOrder(string $html): array
{
    preg_match_all('/data-codex-page-article="([^"]+)"/', $html, $matches);

    return $matches[1];
}

function finCodexDeclaredUser(string $guard): User
{
    return User::create(['name' => ucfirst($guard).' user', 'email' => $guard.'@example.com']);
}

/**
 * @param  list<array<string, mixed>>  $data
 *
 * @return list<string>
 */
function finCodexDeclaredApiSlugs(array $data): array
{
    return array_values(array_map(static fn (array $entry): string => (string) $entry['slug'], $data));
}

function finCodexDeclaredApiQuery(string $route, string $path): string
{
    return '/codex/api/context?'.http_build_query([
        'class' => UserResource::class,
        'panel' => 'admin',
        'route' => $route,
        'path' => $path,
    ]);
}

/**
 * @return array<string, RouteCoverageRow>
 */
function finCodexDeclaredCoverage(): array
{
    $rows = [];

    foreach (app(RouteCoverage::class)->report() as $row) {
        $rows[$row->name] = $row;
    }

    return $rows;
}

/*
 * panelless is absent on purpose (research Pitfall 3): a declaration in a
 * panel makes the core's panel pass non-empty, and the panel-less pass runs
 * only when the panel pass finds nothing. That is the core's documented
 * fallback rule, not a fin-codex choice.
 */
it('lists declared articles first in declared order and collapses a declared-and-stored article to its declared position', function (string $path): void {
    finCodexDeclaredSeed();

    $html = $this->actingAs(finCodexDeclaredUser('web'), 'web')->get($path)->assertOk()->getContent();

    expect(finCodexDeclaredOrder($html))->toBe(['users', 'user-roles', 'stored-admin'])
        ->and($html)->toMatch('/codex-help-button__badge[^>]*>3</')
        ->toContain('data-codex-page-count="3"')
        ->not->toContain('data-codex-page-article="panelless"')
        ->not->toContain('data-codex-page-article="editing-users"')
        ->not->toContain('data-codex-page-article="staff-users"');
})->with([
    'list page' => ['/admin/users'],
    'create page' => ['/admin/users/create'],
]);

/*
 * The core sorts class before route whatever the sort order (research
 * Pitfall 2), so the page-level route: declaration refines the resource's
 * list rather than leading it.
 */
it('adds a resource-page declaration after the resource-level entries on that page only', function (): void {
    finCodexDeclaredSeed();
    $user = finCodexDeclaredUser('web');

    $html = $this->actingAs($user, 'web')->get('/admin/users/'.$user->id.'/edit')->assertOk()->getContent();

    expect(finCodexDeclaredOrder($html))->toBe(['users', 'user-roles', 'stored-admin', 'editing-users'])
        ->and($html)->toMatch('/codex-help-button__badge[^>]*>4</')
        ->toContain('data-codex-page-count="4"')
        ->not->toContain('data-codex-page-article="panelless"');
});

it('answers differently per panel', function (): void {
    finCodexDeclaredSeed();

    $html = $this->actingAs(finCodexDeclaredUser('staff'), 'staff')->get('/staff/users')->assertOk()->getContent();

    expect(finCodexDeclaredOrder($html))->toBe(['staff-users', 'users'])
        ->and($html)->toMatch('/codex-help-button__badge[^>]*>2</')
        ->toContain('data-codex-page-count="2"')
        ->not->toContain('data-codex-page-article="user-roles"')
        ->not->toContain('data-codex-page-article="stored-admin"')
        ->not->toContain('data-codex-page-article="panelless"');
});

it('attaches a custom page declaration by class on admin', function (): void {
    finCodexDeclaredSeed();

    $html = $this->actingAs(finCodexDeclaredUser('web'), 'web')->get('/admin/reports')->assertOk()->getContent();

    expect(finCodexDeclaredOrder($html))->toBe(['reports'])
        ->and($html)->toMatch('/codex-help-button__badge[^>]*>1</')
        ->toContain('data-codex-page-count="1"');
});

it('attaches a custom page declaration by class on staff', function (): void {
    finCodexDeclaredSeed();

    $html = $this->actingAs(finCodexDeclaredUser('staff'), 'staff')->get('/staff/reports')->assertOk()->getContent();

    expect(finCodexDeclaredOrder($html))->toBe(['reports'])
        ->and($html)->toMatch('/codex-help-button__badge[^>]*>1</')
        ->toContain('data-codex-page-count="1"');
});

it('skips a declared slug that has no article', function (): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Users', 'body' => 'About users.'])->create(['slug' => 'users']);

    $html = $this->actingAs(finCodexDeclaredUser('web'), 'web')->get('/admin/users')->assertOk()->getContent();

    expect(finCodexDeclaredOrder($html))->toBe(['users'])
        ->and($html)->toMatch('/codex-help-button__badge[^>]*>1</')
        ->toContain('data-codex-page-count="1"')
        ->not->toContain('data-codex-page-article=""')
        ->not->toContain('data-codex-page-article="user-roles"');
});

it('answers the same list through the JSON API', function (): void {
    finCodexDeclaredSeed();
    $this->actingAs(finCodexDeclaredUser('web'), 'web');

    $list = $this->getJson(finCodexDeclaredApiQuery('filament.admin.resources.users.index', '/admin/users'))->assertOk()->json('data');
    $edit = $this->getJson(finCodexDeclaredApiQuery('filament.admin.resources.users.edit', '/admin/users/1/edit'))->assertOk()->json('data');

    expect(finCodexDeclaredApiSlugs($list))->toBe(['users', 'user-roles', 'stored-admin'])
        ->and($list[0]['title'])->toBe('Users')
        ->and(finCodexDeclaredApiSlugs($edit))->toBe(['users', 'user-roles', 'stored-admin', 'editing-users'])
        ->and($edit[3]['title'])->toBe('Editing Users');
});

/*
 * The index tie-breaks equal sort orders by slug, so on users.edit the
 * resource-level users (sort -1_000_000) and the page-level editing-users
 * (also -1_000_000) resolve to editing-users; matchedBy is the contract.
 * Coverage is not route-panel-aware: match() tries the panel-less pass and
 * then every panel in first-seen order, so the staff Reports route is
 * matched by the admin class: context (same class, admin indexed first).
 * The slug is the same either way.
 */
it('counts declared articles as route coverage', function (): void {
    finCodexDeclaredSeed();

    $rows = finCodexDeclaredCoverage();

    expect($rows['filament.admin.resources.users.index']->matchedBy)->toBe('admin:route:filament.admin.resources.users.index')
        ->and($rows['filament.admin.resources.users.index']->slug)->toBe('users')
        ->and($rows['filament.admin.resources.users.create']->matchedBy)->toBe('admin:route:filament.admin.resources.users.create')
        ->and($rows['filament.admin.resources.users.create']->slug)->toBe('users')
        ->and($rows['filament.admin.resources.users.edit']->matchedBy)->toBe('admin:route:filament.admin.resources.users.edit')
        ->and($rows['filament.admin.resources.users.edit']->slug)->toBe('editing-users')
        ->and($rows['filament.admin.pages.reports']->matchedBy)->toBe('admin:class:'.Reports::class)
        ->and($rows['filament.admin.pages.reports']->slug)->toBe('reports')
        ->and($rows['filament.staff.resources.users.index']->matchedBy)->toBe('staff:route:filament.staff.resources.users.index')
        ->and($rows['filament.staff.resources.users.index']->slug)->toBe('staff-users')
        ->and($rows['filament.staff.pages.reports']->matchedBy)->toBe('admin:class:'.Reports::class)
        ->and($rows['filament.staff.pages.reports']->slug)->toBe('reports')
        ->and($rows['filament.admin.resources.users.index']->covered())->toBeTrue();
});

it('does not count an unknown declared slug as coverage', function (): void {
    $rows = finCodexDeclaredCoverage();

    foreach ([
        'filament.admin.resources.users.index',
        'filament.admin.resources.users.create',
        'filament.admin.resources.users.edit',
        'filament.admin.pages.reports',
        'filament.staff.resources.users.index',
    ] as $name) {
        expect($rows[$name]->matchedBy)->toBeNull()
            ->and($rows[$name]->slug)->toBeNull()
            ->and($rows[$name]->covered())->toBeFalse();
    }
});
