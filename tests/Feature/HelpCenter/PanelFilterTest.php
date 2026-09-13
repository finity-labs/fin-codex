<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Scope\ContextPanels;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\ViewAllPanelsArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Auth\ArticleGate;
use FinityLabs\LinCodex\Auth\Viewer;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * SCOPE-04 on the page: the panel filter above the tab strip, for the one viewer
 * a host policy lets read every panel's help.
 *
 * The filter is not a second copy of the panel rule. It asks the gate itself
 * ("what would a reader standing in panel X see?") through the preview seam
 * 15-01 added, so what the filter shows and what that panel really shows cannot
 * drift apart. The rows below therefore read the RAIL — the tree and the hits —
 * and one row reads the middle column, because the article already open is
 * deliberately outside the filter.
 *
 * Two things make these rows easy to write wrong:
 *
 *  - The middle column renders the top level of the tree on the landing and the
 *    rail renders the whole of it, so a whole-page toContain answers about
 *    whichever column comes first. Every row slices the column it is about.
 *  - The no-leak row must NOT call forgetHelpMemo() after the render: that
 *    helper drops the PanelScopeGate singleton, and a fresh gate has no preview
 *    to leak, so the row would pass over a preview() that never restored.
 *
 * Helpers are file-local and finCodexHelpFilter*-prefixed. Pest "global" helpers
 * only exist for the files a run loads, so a single-file run of this file cannot
 * see the finCodexHelpCenter*, finCodexHelpColumn*, finCodexHelpTree* or
 * finCodexHelpSearch* functions of its siblings.
 */

/**
 * A fixture user created once per test: several rows mount the page more than
 * once and a second User::create() with the same address would trip the unique
 * index rather than prove anything.
 */
function finCodexHelpFilterUser(string $email = 'filter@example.com'): User
{
    return User::firstOrCreate(['email' => $email], ['name' => 'Support']);
}

/** One published, public article, optionally carrying one stored context. */
function finCodexHelpFilterArticle(string $slug, string $title, ?ContextType $type = null, string $key = '', ?string $panelId = null): Article
{
    $factory = Article::factory()->public()->published()->withTranslation('en', [
        'title' => $title,
        'body' => 'Everything the '.$slug.' screen documents.',
    ]);

    if ($type !== null) {
        $factory = $factory->withContext($type, $key, $panelId);
    }

    return $factory->create(['slug' => $slug]);
}

/**
 * Four articles, one per answer the filter can give:
 *
 * - intro, carrying no context at all, read from every panel and in the
 *   outside bucket too;
 * - admin-guide, pinned to admin by an explicit prefix;
 * - staff-guide, pinned to staff by one (the class itself is registered on both
 *   fixture panels, so only the prefix decides);
 * - shop-guide, on a plain Laravel route no panel serves, which is what the
 *   outside bucket is for — and which no named panel's own rule restricts.
 *
 * Every body carries the word "documents", so one search reaches all four and
 * the hits move with the filter exactly as the tree does.
 */
function finCodexHelpFilterSeed(): void
{
    finCodexHelpFilterArticle('intro', 'Intro guide');
    finCodexHelpFilterArticle('admin-guide', 'Admin guide', ContextType::Route, 'filament.admin.pages.dashboard', 'admin');
    finCodexHelpFilterArticle('staff-guide', 'Staff guide', ContextType::PageClass, Dashboard::class, 'staff');
    finCodexHelpFilterArticle('shop-guide', 'Shop guide', ContextType::Route, 'shop.index');

    forgetHelpMemo();
}

/**
 * The page mounted on one panel, by default for a viewer the host policy lets
 * read every panel.
 *
 * The policy override has to come AFTER usesPanel(): booting the plugin
 * registers the shipped policy again, which grants viewAllPanels to nobody.
 */
function finCodexHelpFilterPage(?string $slug = null, string $panel = 'admin', bool $granted = true): Testable
{
    test()->usesPanel($panel, finCodexHelpFilterUser());

    if ($granted) {
        Gate::policy(Article::class, ViewAllPanelsArticlePolicy::class);
    }

    forgetHelpMemo();

    return Livewire::test(HelpCenter::class, $slug === null ? [] : [HelpCenter::SLUG_PARAMETER => $slug]);
}

/**
 * The filter component off the rendered page, so a row can read what the select
 * really carries rather than guess at Filament's markup for it.
 */
function finCodexHelpFilterSelect(Testable $page): Select
{
    $select = $page->instance()->getSchema('content')?->getComponentByStatePath('panelFilter');

    if (! $select instanceof Select) {
        test()->fail('The page rendered no panel filter select.');
    }

    return $select;
}

/**
 * The left rail of a rendered page, and nothing else: the grid order is rail,
 * article, headings, so the rail is what lies before the article column's own
 * class.
 */
function finCodexHelpFilterRailHtml(string $html): string
{
    $start = strpos($html, 'fin-codex-help__rail');

    if ($start === false) {
        test()->fail('The page rendered no rail.');
    }

    $end = strpos($html, 'fin-codex-help__article', $start);

    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
}

/**
 * The slugs the core admits for one viewer, sorted: the source's map through
 * ArticleGate::filter(), which is every read path's shared answer and therefore
 * what a leaked preview would show up in.
 *
 * @return list<string>
 */
function finCodexHelpFilterSeen(Viewer $viewer): array
{
    $seen = array_keys(app(ArticleGate::class)->filter(app(ContentSource::class)->all(), $viewer));

    sort($seen);

    return $seen;
}

/** The middle column of a rendered page, between its own class and the headings column's. */
function finCodexHelpFilterArticleHtml(string $html): string
{
    $start = strpos($html, 'fin-codex-help__article');

    if ($start === false) {
        test()->fail('The page rendered no article column.');
    }

    $end = strpos($html, 'fin-codex-help__toc', $start);

    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
}

/*
 * -----------------------------------------------------------------------
 * Who sees the filter at all.
 * -----------------------------------------------------------------------
 */

it('shows no filter at all to a viewer the host policy grants nothing', function (): void {
    finCodexHelpFilterSeed();

    $html = finCodexHelpFilterPage(granted: false)->html();

    // Not merely disabled and not an empty select: nothing, anywhere on the
    // page. A reader who cannot cross panels is never told the option exists.
    expect($html)->not->toContain('data-fin-codex-help-panel-filter')
        ->and($html)->not->toContain((string) __('fin-codex::fin-codex.help_center.all_panels'));
});

it('shows the filter above the search field and the tab strip to a viewer granted viewAllPanels', function (): void {
    finCodexHelpFilterSeed();

    $rail = finCodexHelpFilterRailHtml(finCodexHelpFilterPage()->html());

    $filter = strpos($rail, 'data-fin-codex-help-panel-filter="true"');
    $field = strpos($rail, 'data-fin-codex-help-search="true"');
    $strip = strpos($rail, 'data-fin-codex-help-tab="contents"');

    expect($filter)->toBeInt()
        ->and($field)->toBeInt()
        ->and($strip)->toBeInt()
        // Top of the rail: it reads as the scope everything below it runs in.
        ->and($filter)->toBeLessThan($field)
        ->and($field)->toBeLessThan($strip)
        ->and($rail)->toContain((string) __('fin-codex::fin-codex.help_center.panel_filter'));
});

/*
 * -----------------------------------------------------------------------
 * The options, and where they come from.
 * -----------------------------------------------------------------------
 */

it('offers the coverage report\'s own panel list with all panels last', function (): void {
    finCodexHelpFilterSeed();

    $options = finCodexHelpFilterSelect(finCodexHelpFilterPage())->getOptions();

    // One list for the filter and the coverage page, so the two screens can
    // never disagree about which panel a screen files under — and one entry
    // of this page's own for "every panel at once".
    expect($options)->toBe(
        app(CoverageReport::class)->panelOptions()
        + [HelpCenter::ALL_PANELS => (string) __('fin-codex::fin-codex.help_center.all_panels')],
    )
        ->and(array_keys($options))->toContain('admin', 'staff', ContextPanels::OUTSIDE_PANELS)
        ->and(array_key_last($options))->toBe(HelpCenter::ALL_PANELS)
        ->and(HelpCenter::ALL_PANELS)->not->toBe(ContextPanels::OUTSIDE_PANELS);
});

it('keeps the placeholder off and re-renders on a choice without a submit', function (): void {
    finCodexHelpFilterSeed();

    $select = finCodexHelpFilterSelect(finCodexHelpFilterPage());

    // Live, because choosing a panel IS the action; and no placeholder to
    // select, because "no scope" is not one of the answers.
    expect($select->isLive())->toBeTrue()
        ->and($select->canSelectPlaceholder())->toBeFalse();
});

/*
 * -----------------------------------------------------------------------
 * Where it starts, every time.
 * -----------------------------------------------------------------------
 */

it('arrives on the current panel, on the admin panel and on the staff panel alike', function (): void {
    finCodexHelpFilterSeed();

    finCodexHelpFilterPage()->assertSet('panelFilter', 'admin');

    expect(Filament::getCurrentPanel()?->getId())->toBe('admin');
});

it('arrives on the staff panel\'s own id behind the staff guard', function (): void {
    finCodexHelpFilterSeed();

    finCodexHelpFilterPage(panel: 'staff')->assertSet('panelFilter', 'staff');
});

it('resets to the current panel on the next visit, persisting nothing', function (): void {
    finCodexHelpFilterSeed();

    finCodexHelpFilterPage()->set('panelFilter', 'staff')->assertSet('panelFilter', 'staff');

    // A second visit in the same session: a super admin must never be quietly
    // looking at another panel's help a week later.
    Livewire::test(HelpCenter::class)->assertSet('panelFilter', 'admin');
});

/*
 * -----------------------------------------------------------------------
 * What the filter actually scopes: the tree and the search, nothing else.
 * -----------------------------------------------------------------------
 */

it('moves the contents tree to the panel the filter names', function (): void {
    finCodexHelpFilterSeed();

    $page = finCodexHelpFilterPage();
    $onArrival = finCodexHelpFilterRailHtml($page->html());

    // On arrival: admin's own rule, which is what a reader standing in admin
    // sees — not the everything the grant would otherwise give.
    expect($onArrival)->toContain('data-fin-codex-help-node="admin-guide"')
        ->toContain('data-fin-codex-help-node="intro"')
        ->toContain('data-fin-codex-help-node="shop-guide"')
        ->not->toContain('data-fin-codex-help-node="staff-guide"');

    $onStaff = finCodexHelpFilterRailHtml($page->set('panelFilter', 'staff')->html());

    expect($onStaff)->toContain('data-fin-codex-help-node="staff-guide"')
        ->toContain('data-fin-codex-help-node="intro"')
        ->toContain('data-fin-codex-help-node="shop-guide"')
        ->not->toContain('data-fin-codex-help-node="admin-guide"');
});

it('gathers what no panel claims under outside panels, the general articles included', function (): void {
    finCodexHelpFilterSeed();

    $rail = finCodexHelpFilterRailHtml(
        finCodexHelpFilterPage()->set('panelFilter', ContextPanels::OUTSIDE_PANELS)->html(),
    );

    // In: the plain Laravel route no panel serves, and intro, which carries no
    // context at all and is read from everywhere. Out: both pinned articles.
    expect($rail)->toContain('data-fin-codex-help-node="shop-guide"')
        ->toContain('data-fin-codex-help-node="intro"')
        ->not->toContain('data-fin-codex-help-node="admin-guide"');

    expect($rail)->not->toContain('data-fin-codex-help-node="staff-guide"');
});

it('shows everything the grant already gives under all panels', function (): void {
    finCodexHelpFilterSeed();

    $rail = finCodexHelpFilterRailHtml(
        finCodexHelpFilterPage()->set('panelFilter', HelpCenter::ALL_PANELS)->html(),
    );

    // No preview at all for this option: the normal rule, which for this viewer
    // is already every panel's help.
    expect($rail)->toContain('data-fin-codex-help-node="admin-guide"')
        ->toContain('data-fin-codex-help-node="staff-guide"')
        ->toContain('data-fin-codex-help-node="intro"')
        ->toContain('data-fin-codex-help-node="shop-guide"');
});

it('moves the search hits with the filter too', function (): void {
    finCodexHelpFilterSeed();

    $page = finCodexHelpFilterPage()->set('query', 'documents');
    $onArrival = finCodexHelpFilterRailHtml($page->html());

    expect($onArrival)->toContain('data-fin-codex-help-hit="admin-guide"')
        ->not->toContain('data-fin-codex-help-hit="staff-guide"');

    $onStaff = finCodexHelpFilterRailHtml($page->set('panelFilter', 'staff')->html());

    // The query is untouched; only the scope it runs in moved.
    expect($onStaff)->toContain('data-fin-codex-help-hit="staff-guide"')
        ->not->toContain('data-fin-codex-help-hit="admin-guide"');
});

it('leaves the article already open readable when the filter moves away from it', function (): void {
    finCodexHelpFilterSeed();

    $page = finCodexHelpFilterPage('admin-guide')->set('panelFilter', 'staff');
    $html = $page->html();

    // The reader holds the grant and may read it anyway, so the filter must not
    // yank the page out from under them — while the rail proves the filter
    // really did move.
    expect(finCodexHelpFilterArticleHtml($html))->toContain('Admin guide')
        ->toContain('Everything the admin-guide screen documents.')
        ->and(finCodexHelpFilterRailHtml($html))->not->toContain('data-fin-codex-help-node="admin-guide"');
});

/*
 * The no-leak row, which is the easiest of these to write wrong: it must NOT
 * call forgetHelpMemo() after the render. That helper drops the PanelScopeGate
 * singleton, and a fresh gate has no preview to leak, so the row would pass over
 * a preview() that never restored anything.
 */
it('leaves the gate answering by the normal rule once the page has rendered', function (): void {
    finCodexHelpFilterSeed();

    $user = finCodexHelpFilterUser();
    finCodexHelpFilterPage()->set('panelFilter', 'staff');

    // The viewer holds the grant, so the normal rule is "everything". A leaked
    // staff preview would take admin-guide away and add staff-guide, which is
    // what the next drawer, hint or global search in this process would get.
    expect(finCodexHelpFilterSeen(Viewer::authenticated($user, 'web')))
        ->toBe(['admin-guide', 'intro', 'shop-guide', 'staff-guide']);
});
