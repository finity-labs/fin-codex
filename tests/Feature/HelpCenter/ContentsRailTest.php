<?php

use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Js;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * CENTER-02: the Contents tab — the whole tree the viewer may read, arranged by
 * the reader and remembered, with the article they are on marked and on screen.
 *
 * The rows read the page's own data-fin-codex-help-* markers rather than
 * Filament's class names, the way tests/Feature/Panel/SchemaDrawerTest.php reads
 * data-fin-codex-drawer-tab; Filament's classes are Filament's to rename.
 *
 * Two of these are about state the SERVER cannot see. A group's open state lives
 * in the browser under an Alpine $persist key, so the rows assert the key
 * Filament renders (derived from the section id, which is derived from the node
 * slug and nothing else — a key that moved between renders would lose what the
 * reader arranged). And because localStorage beats a server-rendered
 * collapsed(false), a deep-linked article's ancestors are forced open with
 * Filament's own expand-section window event instead; the dispatcher has to
 * render AFTER the sections, because Alpine initialises in document order.
 *
 * Helpers are file-local and finCodexHelpTree*-prefixed. Pest "global" helpers
 * only exist for the files a run loads, so a single-file run of this file cannot
 * see a sibling test file's functions.
 */

/**
 * A fixture user signed in on the web guard, created once per test: several rows
 * mount the page more than once and a second User::create() with the same
 * address would trip the unique index rather than prove anything.
 */
function finCodexHelpTreeUser(string $email = 'tree@example.com'): User
{
    $user = User::firstOrCreate(['email' => $email], ['name' => 'Reader']);

    test()->actingAs($user, 'web');

    return $user;
}

/**
 * A tree with one of everything the rail has to draw:
 *
 * - account (article) → account/signing-in (article) → .../two-factor (article),
 *   so an article's own children nest beneath it;
 * - guides (a folder GROUP, because no "guides" article exists) holding
 *   guides/deep (article) and guides/advanced (a second, deeper group) →
 *   guides/advanced/tuning (article);
 * - draft, unpublished, which the viewer may not read.
 */
function finCodexHelpTreeSeed(): void
{
    foreach ([
        'account' => 'Account',
        'account/signing-in' => 'Signing in',
        'account/signing-in/two-factor' => 'Two factor',
        'guides/deep' => 'Deep dive',
        'guides/advanced/tuning' => 'Tuning',
    ] as $slug => $title) {
        Article::factory()->public()->published()
            ->withTranslation('en', ['title' => $title, 'body' => 'Body of '.$title.'.'])
            ->create(['slug' => $slug]);
    }

    Article::factory()->unpublished()
        ->withTranslation('en', ['title' => 'Draft', 'body' => 'Not yet.'])
        ->create(['slug' => 'draft']);

    forgetHelpMemo();
}

/** The page mounted on the admin panel, on the landing unless a slug is given. */
function finCodexHelpTreePage(?string $slug = null): Testable
{
    test()->usesPanel('admin', finCodexHelpTreeUser());
    forgetHelpMemo();

    return Livewire::test(HelpCenter::class, $slug === null ? [] : [HelpCenter::SLUG_PARAMETER => $slug]);
}

/**
 * The opening <section ...> tag of one tree group: the element carrying Alpine's
 * isCollapsed state.
 *
 * The group's marker sits on Filament's outer wrapper and the x-data on the
 * <section> inside it, so this walks from the marker to the next section tag.
 */
function finCodexHelpTreeSection(string $html, string $slug): string
{
    $marker = strpos($html, 'data-fin-codex-help-node="'.$slug.'"');

    if ($marker === false) {
        test()->fail("No tree node for {$slug} in the rail.");
    }

    $open = (int) strpos($html, '<section', $marker);

    return substr($html, $open, (int) strpos($html, '>', $open) - $open + 1);
}

/** The Alpine persistence key Filament renders for one collapsible section id. */
function finCodexHelpTreePersistKey(string $id): string
{
    return 'section-${'.Js::from($id).' ?? $el.id}-isCollapsed';
}

/*
 * -----------------------------------------------------------------------
 * The whole tree.
 * -----------------------------------------------------------------------
 */

it('renders every node the viewer may read, a section per group and a link per article', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage()->html();

    expect($html)->toContain('data-fin-codex-help-node="account"')
        ->toContain('data-fin-codex-help-node="account/signing-in"')
        ->toContain('data-fin-codex-help-node="account/signing-in/two-factor"')
        ->toContain('data-fin-codex-help-node="guides"')
        ->toContain('data-fin-codex-help-node="guides/advanced"')
        ->toContain('data-fin-codex-help-node="guides/advanced/tuning"')
        ->toContain('data-fin-codex-help-node="guides/deep"')
        // The folder groups carry no article, so they are section headings with
        // the core's derived labels, never links.
        ->toContain('Guides')
        ->toContain('Advanced')
        // And what the viewer may not read is not in the tree at all.
        ->not->toContain('data-fin-codex-help-node="draft"')
        ->not->toContain('Draft');
});

it('nests an article\'s own children beneath it, indented by the class 15-02 shipped', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage()->html();

    $parent = strpos($html, 'data-fin-codex-help-node="account"');
    $indent = strpos($html, 'fin-codex-help__children');
    $child = strpos($html, 'data-fin-codex-help-node="account/signing-in"');

    expect($indent)->toBeInt()
        ->and($parent)->toBeLessThan($indent)
        ->and($indent)->toBeLessThan($child);
});

it('points every tree entry at the page\'s own route', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage()->html();

    foreach (['account', 'account/signing-in', 'guides/deep', 'guides/advanced/tuning'] as $slug) {
        expect($html)->toContain('href="'.HelpCenter::getUrl([HelpCenter::SLUG_PARAMETER => $slug]).'"');
    }

    // Real anchors throughout: no Livewire action mounting anywhere in the rail.
    expect($html)->not->toContain("mountAction('open-");
});

/*
 * -----------------------------------------------------------------------
 * What the browser remembers.
 * -----------------------------------------------------------------------
 */

it('derives each group\'s persistence key from its slug and nothing else', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage()->html();

    // Per section, so one group's state never moves another's, and stable
    // across the re-mount every article link causes.
    expect(finCodexHelpTreeSection($html, 'guides'))
        ->toContain(finCodexHelpTreePersistKey('fin-codex-help-guides'))
        ->and(finCodexHelpTreeSection($html, 'guides/advanced'))
        ->toContain(finCodexHelpTreePersistKey('fin-codex-help-guides-advanced'));
});

it('opens the top level on a first visit and leaves deeper groups closed', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage()->html();

    // The server-rendered value is $persist's initial, so this is the
    // first-visit default only: real article titles are visible at once without
    // a deep tree exploding.
    expect(finCodexHelpTreeSection($html, 'guides'))->toContain('$persist(false)')
        ->and(finCodexHelpTreeSection($html, 'guides/advanced'))->toContain('$persist(true)');
});

/*
 * -----------------------------------------------------------------------
 * Arriving at an article.
 * -----------------------------------------------------------------------
 */

it('marks and colours the entry for the article being read', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage('guides/advanced/tuning')->html();

    $marker = strpos($html, 'data-fin-codex-help-active="true"');

    expect($marker)->toBeInt();

    $entry = substr($html, (int) strrpos(substr($html, 0, $marker), '<a'), 400);

    expect($entry)->toContain('data-fin-codex-help-node="guides/advanced/tuning"')
        ->toContain('fi-color-primary');
});

it('forces every ancestor group open and scrolls the entry into view, after the tree', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage('guides/advanced/tuning')->html();

    $sections = strpos($html, 'data-fin-codex-help-node="guides/advanced"');
    $dispatcher = strpos($html, 'data-fin-codex-help-expand=');

    expect($dispatcher)->toBeInt()
        // Alpine initialises in document order, so a dispatcher placed before
        // the sections would fire into the void.
        ->and($sections)->toBeLessThan($dispatcher)
        ->and($html)->toContain('data-fin-codex-help-expand="fin-codex-help-guides fin-codex-help-guides-advanced"')
        ->toContain('expand-section')
        ->toContain('scrollIntoView');
});

it('dispatches nothing where there is no ancestor group to open', function (): void {
    finCodexHelpTreeSeed();

    // The landing is on no article at all, and account/signing-in's only
    // ancestor is an ARTICLE — a link, not a collapsible section — so neither
    // page renders an inert block.
    expect(finCodexHelpTreePage()->html())->not->toContain('data-fin-codex-help-expand')
        ->and(finCodexHelpTreePage('account/signing-in')->html())->not->toContain('data-fin-codex-help-expand');
});
