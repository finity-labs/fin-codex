<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\CoverageRow;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\CreateUser;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\EditUser;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\ListUsers;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Coverage\RouteCoverage;
use FinityLabs\LinCodex\Coverage\RouteCoverageRow;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Spatie\LaravelSettings\Models\SettingsProperty;

/*
 * COV-01's row grain and COV-03's number, on the real fixture route table.
 * Four panels register real routes here, so the collapse is proven against
 * routes Filament actually built rather than against a hand-made list.
 */

/**
 * The report's rows keyed by CoverageRow::key.
 *
 * @return array<string, CoverageRow>
 */
function finCodexCoverageByKey(): array
{
    $rows = [];

    foreach (app(CoverageReport::class)->rows() as $row) {
        $rows[$row->key] = $row;
    }

    return $rows;
}

/**
 * Every row of one panel with one help identity — a list, so "exactly one"
 * is assertable rather than assumed.
 *
 * @return list<CoverageRow>
 */
function finCodexCoverageRowsFor(?string $panelId, ?string $helpClass): array
{
    return array_values(array_filter(
        app(CoverageReport::class)->rows(),
        static fn (CoverageRow $row): bool => $row->panelId === $panelId && $row->helpClass === $helpClass,
    ));
}

/**
 * A named GET route with no Filament page behind it. RouteCoverage reads the
 * router's collection live, so this must be registered before the first
 * rows() call of a test.
 */
function finCodexCoverageShopRoute(): void
{
    Route::get('/shop', fn (): string => '')->name('shop.index')->middleware('web');
}

it('answers the help identity of a page class exactly as PageIdentity does', function (?string $pageClass, ?string $expected): void {
    expect(CoverageReport::helpClass($pageClass))->toBe($expected);
})->with([
    'resource list page' => [ListUsers::class, UserResource::class],
    'resource create page' => [CreateUser::class, UserResource::class],
    'resource edit page' => [EditUser::class, UserResource::class],
    'custom Filament page' => [Reports::class, Reports::class],
    'host settings page' => [AdminHelpSettings::class, AdminHelpSettings::class],
    'plain controller' => ['App\Http\Controllers\ThingController', null],
    'closure route' => [null, null],
]);

/*
 * A Filament resource registers each of its pages as its own invokable
 * controller, so RouteCoverage reports three routes for one screen. The row
 * folds them back onto the resource, which is the identity the drawer is
 * handed on all three pages.
 */
it('collapses the three routes of a resource into one row per panel', function (): void {
    $admin = finCodexCoverageRowsFor('admin', UserResource::class);
    $staff = finCodexCoverageRowsFor('staff', UserResource::class);

    expect($admin)->toHaveCount(1)
        ->and($staff)->toHaveCount(1)
        ->and($admin[0]->routeCount())->toBe(3)
        ->and($admin[0]->routeNames())->toBe([
            'filament.admin.resources.users.create',
            'filament.admin.resources.users.edit',
            'filament.admin.resources.users.index',
        ])
        ->and($staff[0]->routeCount())->toBe(3)
        ->and($staff[0]->routeNames())->toBe([
            'filament.staff.resources.users.create',
            'filament.staff.resources.users.edit',
            'filament.staff.resources.users.index',
        ]);
});

/*
 * The key is an identifier, not a display value: it is the first route name
 * in report order (the report is sorted by name, so "create" precedes
 * "edit" and "index"), and it is what Filament copies onto __key and what a
 * row action is mounted with.
 */
it('keys a collapsed row by the first route name in report order', function (): void {
    $admin = finCodexCoverageRowsFor('admin', UserResource::class);

    expect($admin[0]->key)->toBe('filament.admin.resources.users.create')
        ->and($admin[0]->key)->toBe($admin[0]->routes[0]->name);
});

it('keeps a custom Filament page as a single row', function (): void {
    $rows = finCodexCoverageByKey();

    expect($rows)->toHaveKey('filament.admin.pages.reports')
        ->and($rows['filament.admin.pages.reports']->helpClass)->toBe(Reports::class)
        ->and($rows['filament.admin.pages.reports']->panelId)->toBe('admin')
        ->and($rows['filament.admin.pages.reports']->routeCount())->toBe(1)
        ->and($rows['filament.admin.pages.dashboard']->helpClass)->toBe(Dashboard::class)
        ->and($rows['filament.admin.pages.dashboard']->routeCount())->toBe(1);
});

it('keeps a route with no Filament page behind it as its own row', function (): void {
    finCodexCoverageShopRoute();

    $rows = finCodexCoverageByKey();

    expect($rows)->toHaveKey('shop.index')
        ->and($rows['shop.index']->helpClass)->toBeNull()
        ->and($rows['shop.index']->panelId)->toBeNull()
        ->and($rows['shop.index']->routeCount())->toBe(1)
        ->and($rows['shop.index']->routes[0]->uri)->toBe('/shop');
});

it('reads every row with a human label rather than a route name', function (): void {
    finCodexCoverageShopRoute();

    $labels = app(ContextPicker::class)->classKeys(null);
    $rows = finCodexCoverageByKey();
    $admin = finCodexCoverageRowsFor('admin', UserResource::class);

    expect($admin[0]->label)->toBe($labels[UserResource::class])
        ->and($admin[0]->label)->not->toContain('filament.')
        ->and($rows['filament.admin.pages.reports']->label)->toBe($labels[Reports::class])
        ->and($rows['shop.index']->label)->not->toBe('')
        ->and($rows['shop.index']->label)->not->toContain('filament.');

    foreach ($rows as $row) {
        expect($row->label)->not->toBe('');
    }
});

it('offers every panel present in the report plus the outside-panels bucket as filter options', function (): void {
    finCodexCoverageShopRoute();

    $options = app(CoverageReport::class)->panelOptions();

    expect(array_keys($options))->toContain('admin', 'staff', 'portal', 'plain', CoverageReport::OUTSIDE_PANELS)
        ->and($options[CoverageReport::OUTSIDE_PANELS])->not->toBe('');
});

/*
 * The view must not lose a route the core reported: every reported name
 * belongs to exactly one row.
 */
it('places every route of the core report in exactly one row', function (): void {
    finCodexCoverageShopRoute();

    $reported = array_map(static fn (RouteCoverageRow $row): string => $row->name, app(RouteCoverage::class)->report());
    $placed = [];

    foreach (app(CoverageReport::class)->rows() as $row) {
        $placed = [...$placed, ...$row->routeNames()];
    }

    sort($reported);
    sort($placed);

    expect($placed)->toBe($reported)
        ->and($placed)->toBe(array_values(array_unique($placed)))
        ->and($reported)->toContain('shop.index');
});

it('files the package pages under each panel\'s own resource override', function (): void {
    // Admin registers AdminHelpArticleResource and staff StaffHelpArticleResource;
    // the pages are the package's own and answer getResource() with the
    // CURRENT panel's class, so the report asks each route's panel instead.
    // That is also the class PageIdentity hands the drawer on those pages.
    $this->usesPanel('admin');

    $admin = finCodexCoverageRowsFor('admin', AdminHelpArticleResource::class);
    $staff = finCodexCoverageRowsFor('staff', StaffHelpArticleResource::class);

    expect($admin)->toHaveCount(1)
        ->and($admin[0]->routeCount())->toBe(3)
        ->and($admin[0]->key)->toBe('filament.admin.resources.help-articles.create')
        ->and($staff)->toHaveCount(1)
        ->and($staff[0]->routeCount())->toBe(3)
        ->and(finCodexCoverageRowsFor('admin', ArticleResource::class))->toBe([])
        ->and(finCodexCoverageRowsFor('staff', AdminHelpArticleResource::class))->toBe([]);
});

/*
 * -----------------------------------------------------------------------
 * The covered verdict, the flags and the shared memo.
 * -----------------------------------------------------------------------
 */

/** A public article with one English translation and, optionally, one context. */
function finCodexCoverageArticle(string $slug, ?ContextType $type = null, ?string $key = null, ?string $panelId = null): Article
{
    $factory = Article::factory()->public()
        ->withTranslation('en', ['title' => Str::headline($slug), 'body' => 'About '.$slug.'.']);

    if ($type !== null && $key !== null) {
        $factory = $factory->withContext($type, $key, $panelId, 0);
    }

    return $factory->create(['slug' => $slug]);
}

/**
 * The core's own route report, keyed by route name — the control the class
 * credit is measured against.
 *
 * @return array<string, RouteCoverageRow>
 */
function finCodexCoverageReportByName(): array
{
    $rows = [];

    foreach (app(RouteCoverage::class)->report() as $row) {
        $rows[$row->name] = $row;
    }

    return $rows;
}

/** A content source that cannot be read at all, the way a broken install reads. */
function finCodexCoverageBrokenSource(): ContentSource
{
    return new class implements ContentSource
    {
        public function all(): array
        {
            throw MissingSettings::create(CodexSettings::class, ['default_locale'], 'loading');
        }

        public function findBySlug(string $slug): ?ArticleData
        {
            return null;
        }

        public function tree(): array
        {
            return [];
        }

        public function findByContext(ContextType $type, string $key, ?string $panelId = null): array
        {
            return [];
        }

        public function allForSearch(): array
        {
            return [];
        }

        public function warnings(): array
        {
            return [];
        }
    };
}

/*
 * The seam this whole class exists for, with its control in the same test.
 * lin-codex's PatternMatcher matches class: keys exactly and never walks a
 * resource's page classes, so `class:UserResource` wins no route in
 * report() — while the drawer on those three pages matches it every time.
 */
it('credits a resource-class context the core route report cannot credit', function (): void {
    finCodexCoverageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    $row = finCodexCoverageRowsFor('admin', UserResource::class)[0];
    $report = finCodexCoverageReportByName();

    expect($row->covered())->toBeTrue()
        ->and($row->slug)->toBe('users-guide')
        ->and($row->matchedBy)->toBe('admin:class:'.UserResource::class)
        ->and($report['filament.admin.resources.users.index']->covered())->toBeFalse()
        ->and($report['filament.admin.resources.users.create']->covered())->toBeFalse()
        ->and($report['filament.admin.resources.users.edit']->covered())->toBeFalse();
});

it('credits a panel-less class context on every panel that registers the resource', function (): void {
    finCodexCoverageArticle('users-guide', ContextType::PageClass, UserResource::class);

    $admin = finCodexCoverageRowsFor('admin', UserResource::class)[0];
    $staff = finCodexCoverageRowsFor('staff', UserResource::class)[0];

    expect($admin->matchedBy)->toBe('class:'.UserResource::class)
        ->and($admin->slug)->toBe('users-guide')
        ->and($staff->matchedBy)->toBe('class:'.UserResource::class)
        ->and($staff->slug)->toBe('users-guide');
});

it('leaves a panel uncovered when the class context belongs to another panel', function (): void {
    finCodexCoverageArticle('users-guide', ContextType::PageClass, UserResource::class, 'staff');

    expect(finCodexCoverageRowsFor('admin', UserResource::class)[0]->covered())->toBeFalse()
        ->and(finCodexCoverageRowsFor('staff', UserResource::class)[0]->covered())->toBeTrue();
});

it('still counts a route match when no class context claims the screen', function (): void {
    finCodexCoverageArticle('dashboard-guide', ContextType::Route, 'filament.admin.pages.dashboard');

    $row = finCodexCoverageByKey()['filament.admin.pages.dashboard'];

    expect($row->covered())->toBeTrue()
        ->and($row->matchedBy)->toBe('route:filament.admin.pages.dashboard')
        ->and($row->slug)->toBe('dashboard-guide');
});

/*
 * ContextIndex::candidates() orders class before route, so this is the same
 * article the drawer shows on the page.
 */
it('prefers the class credit over a route match on the same row', function (): void {
    finCodexCoverageArticle('users-class', ContextType::PageClass, UserResource::class, 'admin');
    finCodexCoverageArticle('users-route', ContextType::Route, 'filament.admin.resources.users.index', 'admin');

    $row = finCodexCoverageRowsFor('admin', UserResource::class)[0];

    expect($row->matchedBy)->toBe('admin:class:'.UserResource::class)
        ->and($row->slug)->toBe('users-class');
});

it('counts a declaration in code as coverage and flags the row as declared', function (): void {
    // The fixture UserResource declares `users` for admin, so the article
    // existing is all it takes; the dashboard article carries a stored
    // context instead and must not read as declared.
    finCodexCoverageArticle('users');
    finCodexCoverageArticle('dashboard-guide', ContextType::PageClass, Dashboard::class, 'admin');

    $users = finCodexCoverageRowsFor('admin', UserResource::class)[0];
    $dashboard = finCodexCoverageByKey()['filament.admin.pages.dashboard'];

    expect($users->covered())->toBeTrue()
        ->and($users->slug)->toBe('users')
        ->and($users->matchedBy)->toBe('admin:class:'.UserResource::class)
        ->and($users->isDeclared)->toBeTrue()
        ->and($dashboard->covered())->toBeTrue()
        ->and($dashboard->isDeclared)->toBeFalse();
});

it('flags an article that lives only in a file and carries no database id', function (): void {
    // tests/Fixtures/docs ships `users`, which the fixture UserResource
    // declares, so the row is covered by a file article with no row behind it.
    useFixtureDocs();

    $row = finCodexCoverageRowsFor('admin', UserResource::class)[0];

    expect($row->slug)->toBe('users')
        ->and($row->covered())->toBeTrue()
        ->and($row->isFileOnly)->toBeTrue()
        ->and($row->articleId)->toBeNull()
        ->and($row->isDeclared)->toBeTrue();
});

it('carries the database id of the article that covers a row', function (): void {
    $article = finCodexCoverageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    $row = finCodexCoverageRowsFor('admin', UserResource::class)[0];

    expect($row->articleId)->toBe($article->id)
        ->and($row->isFileOnly)->toBeFalse()
        ->and($row->isDeclared)->toBeFalse();
});

/*
 * COV-03: the badge number is the page's own count, asserted as an equality
 * between the two rather than against a number written down here.
 */
it('counts exactly the uncovered rows of the panel it is asked about', function (): void {
    finCodexCoverageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    $report = app(CoverageReport::class);
    $admin = array_filter($report->rows(), static fn (CoverageRow $row): bool => $row->panelId === 'admin' && ! $row->covered());

    expect($report->uncovered('admin'))->toBe(count($admin))
        ->and($report->uncovered('admin'))->toBeGreaterThan(0);
});

it('counts the rows that belong to no panel for a null panel', function (): void {
    finCodexCoverageShopRoute();

    $report = app(CoverageReport::class);
    $outside = array_filter($report->rows(), static fn (CoverageRow $row): bool => $row->panelId === null && ! $row->covered());

    expect($report->uncovered(null))->toBe(count($outside))
        ->and($report->uncovered(null))->toBeGreaterThan(0);
});

it('reads the source once per request and drops the memo with the other help memos', function (): void {
    $report = app(CoverageReport::class);
    $first = $report->rows();

    expect(app(CoverageReport::class))->toBe($report)
        ->and($report->rows())->toBe($first);

    finCodexCoverageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    expect($report->rows())->toBe($first)
        ->and(finCodexCoverageRowsFor('admin', UserResource::class)[0]->covered())->toBeFalse();

    forgetHelpMemo();

    expect(app(CoverageReport::class))->not->toBe($report)
        ->and(finCodexCoverageRowsFor('admin', UserResource::class)[0]->covered())->toBeTrue();
});

/*
 * DEVIATION from the plan's expectation, measured: an unseeded settings group
 * is not an exception on this path at all. lin-codex's DefaultLocale already
 * rescues MissingSettings|QueryException and falls back to app.locale, so the
 * report is built normally and simply covers nothing. What matters — the badge
 * renders on every panel page — is that nothing throws.
 */
it('still reports when the settings group has never been seeded', function (): void {
    SettingsProperty::query()->where('group', 'lin-codex')->delete();
    app()->forgetInstance(CodexSettings::class);
    forgetHelpMemo();

    $report = app(CoverageReport::class);

    expect($report->rows())->not->toBeEmpty()
        ->and($report->uncovered('admin'))->toBeGreaterThan(0);
});

it('returns an empty report instead of breaking every panel page when the source cannot be read', function (): void {
    forgetHelpMemo();
    app()->instance(ContentSource::class, finCodexCoverageBrokenSource());

    $report = app(CoverageReport::class);

    expect($report->rows())->toBe([])
        ->and($report->uncovered('admin'))->toBe(0)
        ->and($report->panelOptions())->toBe([]);
});
