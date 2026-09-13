<?php

use Filament\Auth\Pages\Login;
use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Livewire\HelpDrawer;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;
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

it('follows the tab strip into the tree and renders nested articles beneath their parent', function (): void {
    $drawer = finCodexSchemaDrawer()->call('open')->set('tab', 'tree');
    $html = $drawer->html();

    expect($drawer->get('view'))->toBe('tree')
        ->and($drawer->get('tab'))->toBe('tree')
        ->and($html)->toContain('data-codex-tree-node="users"')
        ->toContain('data-codex-tree-node="users/roles"')
        ->toContain('fin-codex-drawer__children');
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
 * The page class comes from the locked memo the core captures at mount, not
 * from the current route: a Livewire update request has no page, and a footer
 * that read the route would flip its own visibility between the first render
 * and the next update.
 */
it('points the footer link at the panel\'s own Help Center page', function (): void {
    $html = finCodexSchemaDrawer()->html();

    expect($html)->toContain('data-fin-codex-drawer-help-center')
        ->toContain('href="http://localhost/admin/help"');
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
