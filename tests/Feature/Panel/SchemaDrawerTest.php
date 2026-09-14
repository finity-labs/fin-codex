<?php

use Filament\Auth\Pages\Login;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Livewire\HelpDrawer;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The panel's drawer is the core component with three Filament schemas for
 * its header, content and footer. These rows drive it the way the browser
 * does — open, switch tab, type a search, clear it, go back — and read the
 * markers the schemas put on Filament's own components, so the presentation
 * is proven to follow the core's state on every transition. The core's
 * behaviour itself is lin-codex's to prove.
 */

function finCodexSchemaDrawer(string $pageClass = Dashboard::class): Testable
{
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Users guide', 'excerpt' => 'All about users.', 'body' => "## Adding\n\nText about **users**.\n\n## Removing\n\nMore.\n\n## Renaming\n\nAnd more."])
        ->withContext(ContextType::PageClass, Dashboard::class, 'admin')
        ->create(['slug' => 'users']);
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Roles', 'body' => 'Roles body.'])
        ->create(['slug' => 'users/roles']);

    test()->usesPanel('admin', User::create(['name' => 'Tester', 'email' => 'drawer@example.com']));

    return Livewire::test(HelpDrawer::class, ['pageClass' => $pageClass, 'panelId' => 'admin', 'guard' => 'web']);
}

/** The Alpine persistence key Filament renders for one collapsible section id. */
function finCodexDrawerPersistKey(string $id): string
{
    return 'section-${'.Js::from($id).' ?? $el.id}-isCollapsed';
}

it('lists the page\'s articles as Filament link actions with their excerpts, under a search field and tabs', function (): void {
    $html = finCodexSchemaDrawer()->html();

    expect($html)->toContain('fi-tabs')
        ->toContain('data-fin-codex-drawer-tab="page"')
        ->toContain('data-fin-codex-drawer-tab="tree"')
        ->toContain('fi-input')
        ->toContain('data-codex-focus')
        ->toContain('data-codex-page-article="users"')
        ->toContain('All about users.')
        ->toContain('data-fin-codex-drawer-help-center')
        ->toContain(__('lin-codex::lin-codex.ui.open_help_center'))
        ->toContain('x-data="codexDrawer(')
        ->not->toContain('data-fin-codex-drawer-back');
});

it('shows the article with its table of contents, and a back button, once opened', function (): void {
    $drawer = finCodexSchemaDrawer()->call('open');
    $html = $drawer->html();

    expect($drawer->get('view'))->toBe('article')
        ->and($drawer->get('tab'))->toBe('page')
        ->and($html)->toContain('codex-article__body')
        ->toContain('<strong>users</strong>')
        ->toContain(__('lin-codex::lin-codex.ui.on_this_page'))
        ->toContain('href="#renaming"')
        ->toContain('data-fin-codex-drawer-back')
        ->toContain("mountAction('close'");
});

it('follows the tab strip into the tree and nests an article\'s children inside its own section', function (): void {
    $drawer = finCodexSchemaDrawer()->call('open')->set('tab', 'tree');
    $html = $drawer->html();

    $parent = strpos($html, 'data-codex-tree-node="users"');
    $heading = strpos($html, 'id="fin-codex-drawer-users-heading"');
    $content = strpos($html, 'id="fin-codex-drawer-users-content"');
    $child = strpos($html, 'data-codex-tree-node="users/roles"');

    // The indented wrapper this row used to read is gone. An article that has
    // children is a section of its own now, headed by the action that shows it
    // — so the label still opens the article and the chevron beside it folds
    // the children away, which it could not do while the label was a bare link.
    expect($drawer->get('view'))->toBe('tree')
        ->and($drawer->get('tab'))->toBe('tree')
        ->and($heading)->toBeInt()
        ->and($content)->toBeInt()
        ->and(substr($html, $heading, $content - $heading))
        ->toContain("mountAction('open-users')")
        ->toContain('aria-controls="fin-codex-drawer-users-content"')
        ->and($parent)->toBeLessThan($content)
        ->and($content)->toBeLessThan($child);
});

it('gives every drawer tree section its own persisted id, under a prefix the page cannot collide with', function (): void {
    // A folder group beside the article-rooted section. The groups carried no
    // id at all, so Alpine fell back to an empty element id and every group in
    // the drawer remembered its open state under one shared key.
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Tools', 'body' => 'Tools body.'])
        ->create(['slug' => 'library/tools']);

    $html = finCodexSchemaDrawer()->call('open')->set('tab', 'tree')->html();

    expect($html)->toContain(finCodexDrawerPersistKey('fin-codex-drawer-users'))
        ->toContain(finCodexDrawerPersistKey('fin-codex-drawer-library'))
        // The drawer is mounted on the Help Center page as well, so borrowing
        // that page's ids would mean duplicate DOM ids, one persistence key
        // shared between the two trees, and the page's arrival dispatcher
        // opening the drawer's sections behind the overlay.
        ->not->toContain('fin-codex-help-')
        // The article the drawer is showing is the current page in its own
        // tree too, the way it is in the page's rail.
        ->toContain('aria-current="page"');
});

it('renders search hits while a query is typed and goes back when it is cleared', function (): void {
    $drawer = finCodexSchemaDrawer()->call('open')->set('query', 'roles');

    expect($drawer->get('view'))->toBe('search')
        ->and($drawer->html())->toContain('data-codex-hit="users/roles"')
        ->toContain('Roles');

    $drawer->set('query', '');

    expect($drawer->get('view'))->toBe('article')
        ->and($drawer->html())->toContain('codex-article__body');
});

it('returns to the page tab and the article when the tab strip says so', function (): void {
    $drawer = finCodexSchemaDrawer()->call('open')->set('tab', 'tree')->set('tab', 'page');

    expect($drawer->get('view'))->toBe('article')
        ->and($drawer->get('tab'))->toBe('page')
        ->and($drawer->html())->toContain('codex-article__body');
});

/*
 * PLACE-02 and PLACE-03 in the footer. "Open help center" is offered only
 * where the viewer can actually walk through the door: it goes to the panel's
 * own Help Center page, it is withheld on a simple-layout page because that
 * page is a guest page and the Help Center now sits behind the panel login,
 * and it is withheld from a viewer the page's own gate refuses. The whole
 * Actions group goes, not just the Action, so no empty wrapper is left; the
 * shortcut hint stays, which is the shape the core's own drawer view has.
 *
 * The link carries the article the reader has open, so they keep their place
 * on the way to the page, and falls back to the center's root only when the
 * drawer has no article open. Two rows for the two states, because one state
 * on its own cannot tell the difference.
 *
 * The page class comes from the locked memo the core captures at mount, not
 * from the current route: a Livewire update request has no page, and a footer
 * that read the route would flip its own visibility between the first render
 * and the next update.
 */
it('points the footer link at the help center root while no article is open', function (): void {
    $html = finCodexSchemaDrawer()->html();

    expect($html)->toContain('data-fin-codex-drawer-help-center')
        ->toContain('href="http://localhost/admin/help"');
});

it('points the footer link at the article the drawer has open', function (): void {
    $html = finCodexSchemaDrawer()->call('open')->html();

    // The closing quote is what makes the negative honest: the root href is a
    // prefix of the article's, so only the full attribute can tell them apart.
    expect($html)->toContain('data-fin-codex-drawer-help-center')
        ->toContain('href="http://localhost/admin/help/users"')
        ->not->toContain('href="http://localhost/admin/help"');
});

it('withholds the footer link on a simple-layout page and keeps the shortcut hint', function (): void {
    $html = finCodexSchemaDrawer(Login::class)->html();

    expect($html)->not->toContain('data-fin-codex-drawer-help-center')
        ->not->toContain(__('lin-codex::lin-codex.ui.open_help_center'))
        ->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => 'ctrl+/']));
});

it('withholds the footer link from a viewer the page gate refuses', function (): void {
    Gate::define('page_HelpCenter', fn (): bool => false);

    $html = finCodexSchemaDrawer()->html();

    expect($html)->not->toContain('data-fin-codex-drawer-help-center')
        ->not->toContain(__('lin-codex::lin-codex.ui.open_help_center'))
        ->toContain(__('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => 'ctrl+/']));
});

it('never renders the footer link with an empty href', function (): void {
    test()->usesPanel('admin', User::create(['name' => 'Tester', 'email' => 'no-prefix@example.com']));

    // The state a tenanted panel is in before its tenant is known: the boot
    // write could compute no prefix, so the core's link builder answers null.
    config()->set('lin-codex.routes.help_center', null);

    $html = Livewire::test(HelpDrawer::class, ['pageClass' => Dashboard::class, 'panelId' => 'admin', 'guard' => 'web'])->html();

    expect($html)->not->toContain('data-fin-codex-drawer-help-center')
        ->not->toContain('href=""');
});
