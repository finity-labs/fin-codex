<?php

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\CoverageRow;
use FinityLabs\FinCodex\Enums\NavigationGroup;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\StaffHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/*
 * COV-01 and COV-03 on a rendered page: every panel that carries the plugin
 * gets a coverage page, it lists one row per screen with the gap at the top,
 * both filters are independent, and the sidebar number is the number of
 * uncovered rows the page opens with.
 *
 * The page is registered by FinCodexPlugin::register() beside the settings
 * page: admin and staff name real fixture subclasses through coveragePage()
 * (what a host does), portal keeps the built-in class, and the plain panel,
 * which carries no plugin, gets nothing.
 *
 * Helpers are prefixed finCodexPage* because finCodexCoverage* is taken by
 * CoverageReportTest and Pest helpers are global.
 */

/** A fixture user signed in on the given panel guard. */
function finCodexPageUser(string $guard = 'web'): User
{
    $user = User::create(['name' => 'Admin', 'email' => 'admin@example.com']);

    test()->actingAs($user, $guard);

    return $user;
}

/** A public article with one English translation and, optionally, one context. */
function finCodexPageArticle(string $slug, ?ContextType $type = null, ?string $key = null, ?string $panelId = null): Article
{
    $factory = Article::factory()->public()
        ->withTranslation('en', ['title' => Str::headline($slug), 'body' => 'About '.$slug.'.']);

    if ($type !== null && $key !== null) {
        $factory = $factory->withContext($type, $key, $panelId, 0);
    }

    return $factory->create(['slug' => $slug]);
}

/**
 * A content source that cannot be read at all — the only way to reach
 * CoverageReport's rescue and therefore the only honest way to ask for a
 * report with no rows in it. Deleting the settings group does NOT do it:
 * lin-codex's DefaultLocale already falls back to config('app.locale'), which
 * 07-01 measured.
 */
function finCodexPageBrokenSource(): ContentSource
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
 * -----------------------------------------------------------------------
 * Registration, routing, navigation and the badge.
 * -----------------------------------------------------------------------
 */

it('registers the override on admin and staff and the built-in page on portal, nothing on plain', function (): void {
    expect(array_values(Filament::getPanel('admin')->getPages()))
        ->toContain(AdminHelpCoverage::class)
        ->not->toContain(HelpCoverage::class)
        ->and(array_values(Filament::getPanel('staff')->getPages()))
        ->toContain(StaffHelpCoverage::class)
        ->not->toContain(HelpCoverage::class)
        ->and(array_values(Filament::getPanel('portal')->getPages()))
        ->toContain(HelpCoverage::class)
        ->and(array_values(Filament::getPanel('plain')->getPages()))
        ->not->toContain(HelpCoverage::class)
        ->not->toContain(AdminHelpCoverage::class)
        ->not->toContain(StaffHelpCoverage::class);
});

it('answers on its own route for a signed-in admin', function (): void {
    finCodexPageUser();

    $url = route('filament.admin.pages.codex-coverage');

    expect($url)->toContain('/admin/codex-coverage');

    $this->get($url)->assertOk();
});

it('files two slots after the article resource on every panel that sets a sort', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(AdminHelpCoverage::getNavigationGroup())->toBe('Help')
        ->and(AdminHelpCoverage::getNavigationSort())->toBe(91);

    Filament::setCurrentPanel(Filament::getPanel('staff'));

    expect(StaffHelpCoverage::getNavigationGroup())->toBe('Support')
        ->and(StaffHelpCoverage::getNavigationSort())->toBe(6);

    Filament::setCurrentPanel(Filament::getPanel('portal'));

    expect(HelpCoverage::getNavigationGroup())->toBe(NavigationGroup::Help)
        ->and(HelpCoverage::getNavigationSort())->toBe(2);
});

it('reads its navigation label, title and badge tooltip from the lang files and follows the locale', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $englishLabel = (string) __('fin-codex::fin-codex.coverage.navigation');
    $englishTitle = (string) __('fin-codex::fin-codex.coverage.title');

    expect(AdminHelpCoverage::getNavigationLabel())->toBe($englishLabel)
        ->and((new AdminHelpCoverage)->getTitle())->toBe($englishTitle)
        ->and(AdminHelpCoverage::getNavigationBadgeTooltip())->toBe((string) __('fin-codex::fin-codex.coverage.badge_tooltip'));

    app()->setLocale('de');

    $germanLabel = (string) __('fin-codex::fin-codex.coverage.navigation');
    $germanTitle = (string) __('fin-codex::fin-codex.coverage.title');

    expect(AdminHelpCoverage::getNavigationLabel())->toBe($germanLabel)
        ->not->toBe($englishLabel)
        ->and((new AdminHelpCoverage)->getTitle())->toBe($germanTitle)
        ->not->toBe($englishTitle);
});

it('carries this panel\'s uncovered count on the navigation item', function (): void {
    // One covered admin screen, so the badge is not trivially the whole report.
    finCodexPageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    forgetHelpMemo();

    $uncovered = app(CoverageReport::class)->uncovered('admin');

    expect($uncovered)->toBeGreaterThan(0)
        ->and(AdminHelpCoverage::getNavigationBadge())->toBe((string) $uncovered)
        ->and(AdminHelpCoverage::getNavigationBadgeColor())->toBe('warning');
});

it('shows no badge at all when nothing is uncovered', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(AdminHelpCoverage::getNavigationBadge())->not->toBeNull();

    // forgetHelpMemo() first: it forgets the ContentSource instance itself and
    // would undo the swap the other way round.
    forgetHelpMemo();
    app()->instance(ContentSource::class, finCodexPageBrokenSource());

    expect(app(CoverageReport::class)->uncovered('admin'))->toBe(0)
        ->and(AdminHelpCoverage::getNavigationBadge())->toBeNull();
});

it('mounts on the panel it was registered on', function (): void {
    $this->usesPanel('admin', finCodexPageUser());

    Livewire::test(AdminHelpCoverage::class)->assertOk();
});

/*
 * -----------------------------------------------------------------------
 * The table: one row per screen, the gap first, searchable and paginated.
 * -----------------------------------------------------------------------
 */

/** The mounted admin coverage page with every row on one page. */
function finCodexPageTable(): Testable
{
    return Livewire::test(AdminHelpCoverage::class)->set('tableRecordsPerPage', 'all');
}

/**
 * The record keys the page is showing, in the order it shows them.
 *
 * Read off the component's own records rather than through Filament's count
 * assertion: that one goes through getAllTableRecordsCount(), which calls
 * count() on a null query for a table with no query behind it.
 *
 * @return list<string>
 */
function finCodexPageKeys(Testable $page): array
{
    $records = $page->instance()->getTableRecords();

    return array_values(array_map(strval(...), array_keys($records->getCollection()->all())));
}

/** The rows the page is showing, in order, as the plain arrays they are. */
function finCodexPageRecords(Testable $page): Collection
{
    return $page->instance()->getTableRecords()->getCollection();
}

/** How many screens the report itself puts on one panel — an equality against the service, never a number. */
function finCodexPagePanelCount(?string $panelId): int
{
    return count(array_filter(
        app(CoverageReport::class)->rows(),
        fn (CoverageRow $row): bool => $row->panelId === $panelId,
    ));
}

/** The report's own key for one screen — never hard-coded, so a fixture change cannot make a row vacuous. */
function finCodexPageRowKey(?string $panelId, ?string $helpClass): string
{
    foreach (app(CoverageReport::class)->rows() as $row) {
        if ($row->panelId === $panelId && $row->helpClass === $helpClass) {
            return $row->key;
        }
    }

    throw new RuntimeException('No coverage row for '.($panelId ?? 'no panel').' / '.($helpClass ?? 'no class'));
}

it('shows one row per screen, with a resource\'s three routes folded into one', function (): void {
    $this->usesPanel('admin', finCodexPageUser());

    $page = finCodexPageTable();
    $keys = finCodexPageKeys($page);
    $usersKey = finCodexPageRowKey('admin', UserResource::class);

    $userRoutes = array_values(array_filter(
        $keys,
        fn (string $key): bool => str_starts_with($key, 'filament.admin.resources.users.'),
    ));

    expect($keys)->toContain($usersKey)
        ->and($keys)->toContain(finCodexPageRowKey('admin', Dashboard::class))
        ->and($userRoutes)->toBe([$usersKey])
        // The page opens on this panel, so the total is this panel's screens.
        ->and($page->instance()->getTableRecords()->total())->toBe(finCodexPagePanelCount('admin'));
});

it('reads like a checklist: the screen\'s name, its panel and its article', function (): void {
    finCodexPageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    $this->usesPanel('admin', finCodexPageUser());
    forgetHelpMemo();

    $users = finCodexPageRecords(finCodexPageTable())[finCodexPageRowKey('admin', UserResource::class)];

    expect($users['label'])->not->toContain('filament.')
        ->and($users['covered'])->toBeTrue()
        ->and($users['slug'])->toBe('users-guide');

    finCodexPageTable()
        ->assertSee($users['label'])
        ->assertSee('users-guide')
        // The uncovered rows carry the placeholder in the article column.
        ->assertSee('—');
});

it('opens with the screens that have no article at the top', function (): void {
    finCodexPageArticle('dashboard-guide', ContextType::PageClass, Dashboard::class, 'admin');

    $this->usesPanel('admin', finCodexPageUser());
    forgetHelpMemo();

    $records = finCodexPageRecords(finCodexPageTable());
    $covered = $records->pluck('covered')->values()->all();

    expect($records->first()['covered'])->toBeFalse()
        ->and($records->last()['covered'])->toBeTrue()
        // No uncovered row ever appears after a covered one.
        ->and($covered)->toBe(collect($covered)->sort()->values()->all())
        // Inside the uncovered block the order is the label's.
        ->and($labels = $records->reject(fn (array $row): bool => $row['covered'])->pluck('label')->values()->all())
        ->toBe(collect($labels)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
});

it('searches the screen name and the route name, and says so when nothing matches', function (): void {
    $this->usesPanel('admin', finCodexPageUser());

    $keys = finCodexPageKeys(finCodexPageTable()->searchTable('Users'));

    expect($keys)->toContain(finCodexPageRowKey('admin', UserResource::class))
        ->and($keys)->not->toContain(finCodexPageRowKey('admin', Dashboard::class));

    $miss = finCodexPageTable()->searchTable('zzzz-no-such-screen');

    expect(finCodexPageKeys($miss))->toBe([]);

    $miss->assertSee((string) __('fin-codex::fin-codex.coverage.empty'));
});

it('re-orders on a header click, which drops the uncovered-first opening order', function (): void {
    finCodexPageArticle('dashboard-guide', ContextType::PageClass, Dashboard::class, 'admin');

    $this->usesPanel('admin', finCodexPageUser());
    forgetHelpMemo();

    $dashboardKey = finCodexPageRowKey('admin', Dashboard::class);
    $opening = finCodexPageKeys(finCodexPageTable());
    $gap = finCodexPageRecords(finCodexPageTable())->reject(fn (array $row): bool => $row['covered'])->count();

    // The covered dashboard opens at the head of the covered block, under
    // every screen that still needs an article.
    $openingIndex = array_search($dashboardKey, $opening, true);

    expect($openingIndex)->toBe($gap);

    $page = finCodexPageTable()->sortTable('label');
    $labels = finCodexPageRecords($page)->pluck('label')->values()->all();

    // A header click orders by that column alone, so the covered row moves up
    // to where its name puts it.
    expect($labels)->toBe(collect($labels)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all())
        ->and(array_search($dashboardKey, finCodexPageKeys($page), true))->toBeLessThan($openingIndex);
});

it('paginates instead of dumping every screen on one page', function (): void {
    $this->usesPanel('admin', finCodexPageUser());

    $total = finCodexPagePanelCount('admin');

    $page = Livewire::test(AdminHelpCoverage::class)->set('tableRecordsPerPage', 5);
    $records = $page->instance()->getTableRecords();

    expect($records)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($total)->toBeGreaterThan(5)
        ->and($records->total())->toBe($total)
        ->and($records->count())->toBe(5);

    $first = finCodexPageKeys($page);
    $second = finCodexPageKeys($page->call('setPage', 2));

    // preserve_keys is what keeps the second slice keyed by route name too.
    expect($second)->toHaveCount($total - 5)
        ->and(array_intersect($first, $second))->toBe([])
        ->and(array_merge($first, $second))->toBe(finCodexPageKeys(finCodexPageTable()));
});

/*
 * -----------------------------------------------------------------------
 * The two filters, and the badge that agrees with the page.
 * -----------------------------------------------------------------------
 */

/** A named GET route with no Filament page behind it — a row outside every panel. */
function finCodexPageShopRoute(): void
{
    Route::get('/shop', fn (): string => '')->name('shop.index')->middleware('web');
}

it('opens on this panel\'s own screens with no filter touched', function (): void {
    $this->usesPanel('admin', finCodexPageUser());

    $page = finCodexPageTable();
    $keys = finCodexPageKeys($page);

    expect($page->instance()->getTableFilterState('panel')['value'])->toBe('admin')
        ->and(finCodexPageRecords($page)->pluck('panel')->unique()->values()->all())->toBe(['admin']);

    $page->assertCanSeeTableRecords([finCodexPageRowKey('admin', UserResource::class)])
        ->assertCanNotSeeTableRecords([finCodexPageRowKey('staff', UserResource::class)]);

    expect($keys)->not->toContain('filament.exports.download');
});

it('widens to another panel and to the application\'s own web routes', function (): void {
    finCodexPageShopRoute();

    $this->usesPanel('admin', finCodexPageUser());
    forgetHelpMemo();

    $wide = finCodexPageKeys(finCodexPageTable()->filterTable('panel', null));

    expect($wide)->toContain(finCodexPageRowKey('admin', UserResource::class))
        ->toContain(finCodexPageRowKey('staff', UserResource::class))
        ->toContain('shop.index');

    $outside = finCodexPageRecords(finCodexPageTable()->filterTable('panel', CoverageReport::OUTSIDE_PANELS));

    expect($outside->keys()->all())->toContain('shop.index')
        // Every row of that view belongs to no panel — which is what the
        // option means, not "no filament.* route": the core's export and
        // import download routes sit outside every panel too.
        ->and($outside->pluck('panel')->unique()->values()->all())->toBe([null])
        ->and($outside->keys()->all())->not->toContain(finCodexPageRowKey('admin', UserResource::class));
});

it('filters the covered state independently of the panel', function (): void {
    finCodexPageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    $this->usesPanel('admin', finCodexPageUser());
    forgetHelpMemo();

    $adminUsers = finCodexPageRowKey('admin', UserResource::class);

    $uncovered = finCodexPageRecords(finCodexPageTable()->filterTable('covered', false));

    expect($uncovered->pluck('covered')->unique()->values()->all())->toBe([false])
        ->and($uncovered->pluck('panel')->unique()->values()->all())->toBe(['admin'])
        ->and($uncovered->keys()->all())->not->toContain($adminUsers);

    $covered = finCodexPageRecords(finCodexPageTable()->filterTable('covered', true));

    expect($covered->keys()->all())->toBe([$adminUsers]);

    // Both filters at once, on a panel the page did not open on.
    $staff = finCodexPageRecords(
        finCodexPageTable()->filterTable('panel', 'staff')->filterTable('covered', false),
    );

    expect($staff)->not->toBeEmpty()
        ->and($staff->pluck('panel')->unique()->values()->all())->toBe(['staff'])
        ->and($staff->pluck('covered')->unique()->values()->all())->toBe([false]);
});

it('shows both verdicts again when the covered filter is cleared', function (): void {
    finCodexPageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    $this->usesPanel('admin', finCodexPageUser());
    forgetHelpMemo();

    // false is a filter, null is no filter — the difference blank() makes.
    $page = finCodexPageTable()->filterTable('covered', false);

    expect(finCodexPageRecords($page)->pluck('covered')->unique()->values()->all())->toBe([false]);

    $page->filterTable('covered', null);

    expect(finCodexPageRecords($page)->pluck('covered')->unique()->sort()->values()->all())->toBe([false, true]);
});

it('shows exactly as many uncovered rows as the sidebar promises', function (): void {
    finCodexPageArticle('users-guide', ContextType::PageClass, UserResource::class, 'admin');

    $this->usesPanel('admin', finCodexPageUser());
    forgetHelpMemo();

    $onThePage = finCodexPageRecords(finCodexPageTable())
        ->reject(fn (array $row): bool => $row['covered'])
        ->count();

    expect($onThePage)->toBeGreaterThan(0)
        ->and(AdminHelpCoverage::getNavigationBadge())->toBe((string) $onThePage);
});
