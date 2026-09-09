<?php

use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\SourceWarnings;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\SourceWarningKind;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * WARN-01 on the two screens that show it. The fixture declarations of Plan
 * 04-01 name articles that do not exist, so DeclaredContextsSource reports a
 * standing set of InvalidSlug warnings and a bare render already has
 * something to say; seeding every declared slug is how a row reaches zero.
 *
 * Every number here is read back from SourceWarnings rather than written as a
 * literal, so a fixture gaining or losing a declaration cannot make a row
 * vacuous.
 *
 * Helpers are prefixed finCodexSurface* because finCodexWarning* belongs to
 * SourceWarningsTest and finCodexPage* to HelpCoverageTest; Pest helpers are
 * global and all three files load in a full run.
 */

/** Every slug the fixture panels declare in code — seeding all five silences the source. */
function finCodexSurfaceSilence(): void
{
    foreach (['users', 'user-roles', 'staff-users', 'editing-users', 'reports'] as $slug) {
        Article::factory()->public()
            ->withTranslation('en', ['title' => Str::headline($slug), 'body' => 'About '.$slug.'.'])
            ->create(['slug' => $slug]);
    }

    forgetHelpMemo();
}

function finCodexSurfaceUser(string $guard = 'web'): User
{
    $user = User::create(['name' => 'Warned', 'email' => 'warned@example.com']);

    test()->actingAs($user, $guard);

    return $user;
}

/** The heading the section is carrying right now, straight off the service. */
function finCodexSurfaceHeading(): string
{
    $count = app(SourceWarnings::class)->count();

    return (string) trans_choice('fin-codex::fin-codex.warnings.heading', $count, ['count' => $count]);
}

/**
 * The rendered `<section>` the warnings live in: from the `<section` tag that
 * opens it to the marker its view emits inside it.
 *
 * Sliced rather than matched on a class name because the page carries other
 * sections and other amber things — both navigation badges are `warning` —
 * and an assertion on the whole page would pass on any of them.
 */
function finCodexSurfaceSection(string $html): string
{
    $marker = strpos($html, 'data-fin-codex-warnings');
    expect($marker)->not->toBeFalse();

    $open = strrpos(substr($html, 0, (int) $marker), '<section');
    expect($open)->not->toBeFalse();

    return substr($html, (int) $open, (int) $marker - (int) $open);
}

/*
 * -----------------------------------------------------------------------
 * On both pages, on both tabs.
 * -----------------------------------------------------------------------
 */

it('shows the warnings above the article list', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $count = app(SourceWarnings::class)->count();
    $html = Livewire::test(ListArticles::class)->html();

    expect($count)->toBeGreaterThan(0)
        ->and($html)->toContain('data-fin-codex-warnings="'.$count.'"')
        ->and($html)->toContain(finCodexSurfaceHeading())
        ->and($html)->toContain(__('fin-codex::fin-codex.warnings.description'));
});

it('shows the same warnings above the coverage table', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $count = app(SourceWarnings::class)->count();
    $html = Livewire::test(AdminHelpCoverage::class)->html();

    expect($count)->toBeGreaterThan(0)
        ->and($html)->toContain('data-fin-codex-warnings="'.$count.'"')
        ->and($html)->toContain(finCodexSurfaceHeading());
});

it('stays above the tab strip, so both tabs of the article list show it', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $count = app(SourceWarnings::class)->count();

    $articles = Livewire::test(ListArticles::class);
    $files = Livewire::test(ListArticles::class)->set('activeTab', 'files');

    expect($articles->html())->toContain('data-fin-codex-warnings="'.$count.'"')
        ->and($files->html())->toContain('data-fin-codex-warnings="'.$count.'"');
});

/*
 * -----------------------------------------------------------------------
 * Collapsed and amber: a filesystem source with twenty malformed files must
 * not push the table it sits above off the screen.
 * -----------------------------------------------------------------------
 */

it('renders collapsed, with the amber icon and nothing else amber about it', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $section = finCodexSurfaceSection(Livewire::test(AdminHelpCoverage::class)->html());

    expect($section)->toContain('fi-collapsible')
        ->and($section)->toContain('isCollapsed: true')
        ->and($section)->toContain('aria-expanded="false"')
        ->and($section)->toContain('x-cloak')
        ->and($section)->toContain('fi-color-warning');
});

/*
 * -----------------------------------------------------------------------
 * Grouped under the core's own kind names.
 * -----------------------------------------------------------------------
 */

it('groups the findings under one heading per kind with a count, never one heading per finding', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $key = SourceWarningKind::InvalidSlug->key();
    $group = collect(app(SourceWarnings::class)->grouped())->firstWhere('key', $key);
    $html = Livewire::test(AdminHelpCoverage::class)->html();

    expect($group)->not->toBeNull()
        ->and($group['count'])->toBeGreaterThan(1)
        ->and($html)->toContain('data-fin-codex-warning-kind="'.$key.'"')
        ->and($html)->toContain('data-fin-codex-warning-count="'.$key.':'.$group['count'].'"')
        ->and(substr_count($html, 'data-fin-codex-warning-kind="'.$key.'"'))->toBe(1);
});

it('reads each line with the core sentence and the path beside it', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $line = app(SourceWarnings::class)->grouped()[0]['lines'][0];
    $html = Livewire::test(AdminHelpCoverage::class)->html();

    expect($html)->toContain(e($line['message']))
        ->and($line['path'])->not->toBeNull()
        ->and($html)->toContain(e((string) $line['path']));
});

it('names the kinds with the core labels, so fin-codex declares none of its own', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $english = SourceWarningKind::InvalidSlug->label();

    expect(Livewire::test(AdminHelpCoverage::class)->html())->toContain($english);

    app()->setLocale('de');

    $german = SourceWarningKind::InvalidSlug->label();
    $html = Livewire::test(AdminHelpCoverage::class)->html();

    expect($german)->not->toBe($english)
        ->and($html)->toContain($german)
        ->and($html)->not->toContain($english);
});

/*
 * -----------------------------------------------------------------------
 * Nothing at all when there is nothing.
 * -----------------------------------------------------------------------
 */

it('renders nothing on either page when the sources are happy', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $noisy = finCodexSurfaceHeading();

    finCodexSurfaceSilence();

    $list = Livewire::test(ListArticles::class)->html();
    $coverage = Livewire::test(AdminHelpCoverage::class)->html();

    expect(app(SourceWarnings::class)->count())->toBe(0)
        ->and($list)->not->toContain('data-fin-codex-warnings')
        ->and($list)->not->toContain($noisy)
        ->and($list)->not->toContain(__('fin-codex::fin-codex.warnings.description'))
        ->and($coverage)->not->toContain('data-fin-codex-warnings')
        ->and($coverage)->not->toContain($noisy)
        ->and($coverage)->not->toContain(__('fin-codex::fin-codex.warnings.description'));
});

/*
 * -----------------------------------------------------------------------
 * The count on the Help articles navigation item — and the uncovered count
 * staying where it is. One number per navigation item, each meaning one
 * thing.
 * -----------------------------------------------------------------------
 */

/** The sidebar list item whose link points at $path, out of a rendered panel page. */
function finCodexSurfaceNavItem(string $html, string $path): string
{
    preg_match_all('/<li[^>]*class="fi-sidebar-item[^"]*"[^>]*>.*?<\/li>/s', $html, $matches);

    $items = array_values(array_filter(
        $matches[0],
        static fn (string $item): bool => str_contains($item, 'href="http://localhost'.$path.'"'),
    ));

    expect($items)->toHaveCount(1);

    return $items[0];
}

/** The number Filament printed in that item's badge, or null when it printed none. */
function finCodexSurfaceNavBadge(string $item): ?string
{
    return preg_match('/fi-badge-label">\s*([^<\s][^<]*?)\s*</', $item, $matches) === 1
        ? $matches[1]
        : null;
}

it('counts the content warnings on the Help articles navigation item', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $count = app(SourceWarnings::class)->count();

    expect($count)->toBeGreaterThan(0)
        ->and(AdminHelpArticleResource::getNavigationBadge())->toBe((string) $count)
        ->and(AdminHelpArticleResource::getNavigationBadgeColor())->toBe('warning');
});

it('hides the Help articles badge once the sources are happy', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    expect(AdminHelpArticleResource::getNavigationBadge())->not->toBeNull();

    finCodexSurfaceSilence();

    expect(app(SourceWarnings::class)->count())->toBe(0)
        ->and(AdminHelpArticleResource::getNavigationBadge())->toBeNull();
});

it('gives a host subclass the same badge, because it lives on the package resource', function (): void {
    $this->usesPanel('staff', finCodexSurfaceUser('staff'));

    $count = app(SourceWarnings::class)->count();

    expect($count)->toBeGreaterThan(0)
        ->and(StaffHelpArticleResource::getNavigationBadge())->toBe((string) $count)
        ->and(StaffHelpArticleResource::getNavigationBadgeColor())->toBe('warning');
});

it('keeps the warnings count and the uncovered count apart', function (): void {
    $this->usesPanel('admin', finCodexSurfaceUser());

    $warnings = app(SourceWarnings::class)->count();
    $uncovered = app(CoverageReport::class)->uncovered('admin');

    expect($warnings)->toBeGreaterThan(0)
        ->and($uncovered)->toBeGreaterThan(0)
        ->and($warnings)->not->toBe($uncovered)
        ->and(AdminHelpArticleResource::getNavigationBadge())->toBe((string) $warnings)
        ->and(AdminHelpCoverage::getNavigationBadge())->toBe((string) $uncovered);
});

it('shows both numbers in the sidebar of a rendered panel page', function (): void {
    finCodexSurfaceUser();

    $html = (string) $this->get('/admin/codex-articles')->assertOk()->getContent();

    $warnings = app(SourceWarnings::class)->count();
    $uncovered = app(CoverageReport::class)->uncovered('admin');

    expect(finCodexSurfaceNavBadge(finCodexSurfaceNavItem($html, '/admin/codex-articles')))->toBe((string) $warnings)
        ->and(finCodexSurfaceNavBadge(finCodexSurfaceNavItem($html, '/admin/codex-coverage')))->toBe((string) $uncovered)
        ->and($warnings)->not->toBe($uncovered);
});
