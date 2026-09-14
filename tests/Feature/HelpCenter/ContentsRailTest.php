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

/**
 * The header of one tree section: everything between the node's marker and the
 * opening of its content container, which is where the heading link and the
 * collapse button both live.
 */
function finCodexHelpTreeHeader(string $html, string $slug): string
{
    $marker = strpos($html, 'data-fin-codex-help-node="'.$slug.'"');

    if ($marker === false) {
        test()->fail("No tree node for {$slug} in the rail.");
    }

    $content = strpos($html, 'fi-section-content-ctn', $marker);

    if ($content === false) {
        test()->fail("The tree node for {$slug} is not a section.");
    }

    return substr($html, $marker, $content - $marker);
}

/**
 * The anchor one tree entry is rendered as, from its own tag up to the marker
 * that names it.
 */
function finCodexHelpTreeEntry(string $html, string $slug): string
{
    $marker = strpos($html, 'data-fin-codex-help-node="'.$slug.'"');

    if ($marker === false) {
        test()->fail("No tree node for {$slug} in the rail.");
    }

    $open = strrpos(substr($html, 0, $marker), '<a');

    if ($open === false) {
        test()->fail("The tree entry for {$slug} is not a link.");
    }

    return substr($html, $open, (int) strpos($html, '>', $marker) - $open + 1);
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

it('nests an article\'s own children inside its section rather than beside it', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage()->html();

    $parent = strpos($html, 'data-fin-codex-help-node="account"');
    $content = strpos($html, 'id="fin-codex-help-account-content"');
    $child = strpos($html, 'data-fin-codex-help-node="account/signing-in"');
    $grandchild = strpos($html, 'data-fin-codex-help-node="account/signing-in/two-factor"');
    $next = strpos($html, 'data-fin-codex-help-node="guides"');

    // 15-02's indented wrapper and the class it carried are gone. The children
    // sit in the section's own content container now, which is the thing the
    // chevron folds away — an indent has nothing to fold.
    expect($content)->toBeInt()
        ->and($parent)->toBeLessThan($content)
        ->and($content)->toBeLessThan($child)
        ->and($child)->toBeLessThan($grandchild)
        // And the whole subtree closes before the next top-level node opens.
        ->and($grandchild)->toBeLessThan($next);
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

it('marks the entry for the article being read as the current page, in a colour its siblings do not carry', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage('guides/advanced/tuning')->html();

    $marker = strpos($html, 'data-fin-codex-help-active="true"');

    expect($marker)->toBeInt();

    $entry = substr($html, (int) strrpos(substr($html, 0, $marker), '<a'), 400);

    // This row used to prove the highlight with fi-color-primary alone, which
    // Filament puts on every link action that carries no explicit colour — so
    // it could not fail while the entry looked exactly like its siblings. What
    // a reader and a screen reader actually perceive is a difference, so both
    // halves below are differential: the count proves the current-page marker
    // is unique in the page, and the sibling proves there is a contrast.
    //
    // A sibling carries no colour class at all: gray is a link's own default in
    // Filament, so asking for it drops the class rather than adding one. The
    // absence is the assertion — every entry here used to carry the primary
    // class, this one now does not, and only the current entry still does.
    expect($entry)->toContain('data-fin-codex-help-node="guides/advanced/tuning"')
        ->toContain('aria-current="page"')
        ->toContain('fi-color-primary')
        ->and(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and(finCodexHelpTreeEntry($html, 'guides/deep'))->not->toContain('fi-color');
});

it('renders an article that has children as a collapsible section headed by its own link', function (): void {
    finCodexHelpTreeSeed();

    // Read from an article page rather than the landing: the landing's middle
    // column lists the top level as links too, and a second link to account
    // would make the count at the bottom of this row say nothing.
    $url = HelpCenter::getUrl([HelpCenter::SLUG_PARAMETER => 'account']);
    $html = finCodexHelpTreePage('guides/deep')->html();
    $header = finCodexHelpTreeHeader($html, 'account');

    expect(finCodexHelpTreeSection($html, 'account'))
        ->toContain(finCodexHelpTreePersistKey('fin-codex-help-account'))
        // The heading IS the link: the label opens the article, and the chevron
        // beside it is a real disclosure button for the children.
        ->and($header)->toContain('fi-section-header-heading')
        ->toContain('href="'.$url.'"')
        ->toContain('aria-expanded')
        ->toContain('aria-controls="fin-codex-help-account-content"')
        // Filament's own header toggle sits on the element around the heading,
        // so without this guard one click on the label would both open the
        // article and flip the section shut, and remember it that way.
        ->toContain('x-on:click.stop=""')
        // One entry per article: the heading link is the only way in, so there
        // is no repeated overview entry among the children.
        ->and(substr_count($html, 'href="'.$url.'"'))->toBe(1);
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

it('opens both ancestors of an article nested two deep under an article', function (): void {
    finCodexHelpTreeSeed();

    $html = finCodexHelpTreePage('account/signing-in/two-factor')->html();

    // Neither ancestor is a folder group, and the deeper of the two comes up
    // closed on a first visit — so without this the reader would arrive at an
    // article whose entry in the rail is hidden inside two shut sections.
    expect($html)->toContain('data-fin-codex-help-expand="fin-codex-help-account fin-codex-help-account-signing-in"');
});

it('dispatches nothing on the landing, where the reader is on no article at all', function (): void {
    finCodexHelpTreeSeed();

    // Nothing to arrive at, so nothing to open and no inert block rendered.
    expect(finCodexHelpTreePage()->html())->not->toContain('data-fin-codex-help-expand');
});

it('opens the ancestor of an article whose parent is itself an article', function (): void {
    finCodexHelpTreeSeed();

    // This row used to assert the opposite and read it as intended behaviour:
    // account is an article, and an article was a link with nothing to open. It
    // is a collapsible section now, so the entry the reader arrived at would be
    // folded away inside it unless arriving unfolds it.
    expect(finCodexHelpTreePage('account/signing-in')->html())
        ->toContain('data-fin-codex-help-expand="fin-codex-help-account"');
});
