<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Livewire\HelpDrawer;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
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

function finCodexSchemaDrawer(): Testable
{
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Users guide', 'excerpt' => 'All about users.', 'body' => "## Adding\n\nText about **users**.\n\n## Removing\n\nMore.\n\n## Renaming\n\nAnd more."])
        ->withContext(ContextType::PageClass, Dashboard::class, 'admin')
        ->create(['slug' => 'users']);
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Roles', 'body' => 'Roles body.'])
        ->create(['slug' => 'users/roles']);

    test()->usesPanel('admin', User::create(['name' => 'Tester', 'email' => 'drawer@example.com']));

    return Livewire::test(HelpDrawer::class, ['pageClass' => Dashboard::class, 'panelId' => 'admin', 'guard' => 'web']);
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
