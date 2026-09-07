<?php

use Filament\Pages\Dashboard;
use Filament\Schemas\Schema;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Livewire\HelpDrawer;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * EDIT-10 through the pages: renaming a section and deleting an article.
 *
 * Both are the same problem seen from two sides — lin-codex derives the tree
 * from the slug, so a rename has to carry the children with it and a delete
 * leaves them behind under a folder group that hides nothing. The model half
 * is proven in RenameAndConvertTest and DeleteConsequencesTest; these rows
 * prove the admin sees it and can act on it: the modal lists what goes where
 * and the keep-hidden checkbox, on by default, is what keeps an authenticated
 * parent's public children away from guests.
 */

function finCodexEditorUser(string $name = 'Editor'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * users (authenticated, two media)
 *   > users/roles          (public, published, body "Roles body")
 *     > users/roles/admins (public, published)
 * users-guide              (public, published, not a descendant)
 *
 * @return array{parent: Article, child: Article, grandchild: Article, sibling: Article}
 */
function finCodexEditorSeed(): array
{
    $parent = Article::factory()->authenticated()->published()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'Users body'])
        ->withMedia(2)
        ->create(['slug' => 'users']);

    $child = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Roles', 'body' => 'Roles body'])
        ->create(['slug' => 'users/roles']);

    $grandchild = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Admins', 'body' => 'Admins body'])
        ->create(['slug' => 'users/roles/admins']);

    $sibling = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Users guide', 'body' => 'Guide body'])
        ->create(['slug' => 'users-guide']);

    return [
        'parent' => $parent->fresh(),
        'child' => $child->fresh(),
        'grandchild' => $grandchild->fresh(),
        'sibling' => $sibling->fresh(),
    ];
}

/** The rendered delete modal of an edit page, form and all. */
function finCodexEditorDeleteModal(Testable $component): string
{
    return $component->mountAction('delete')->getMountedActionModalHtml();
}

/** Open one slug in the drawer of the admin panel as whoever is signed in. */
function finCodexEditorDrawer(): Testable
{
    forgetHelpMemo();

    return Livewire::test(HelpDrawer::class, ['pageClass' => Dashboard::class, 'panelId' => 'admin', 'guard' => 'web']);
}

it('renames a section through the edit form and keeps children attached', function (): void {
    $user = finCodexEditorUser();
    $this->usesPanel('admin', $user);

    ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild, 'sibling' => $sibling] = finCodexEditorSeed();

    Livewire::test(EditArticle::class, ['record' => $parent->getRouteKey()])
        ->fillForm(['slug' => 'accounts'])
        ->call('save')
        ->assertHasNoFormErrors();

    $accounts = Article::query()->where('slug', 'accounts')->firstOrFail();
    $roles = Article::query()->where('slug', 'accounts/roles')->firstOrFail();
    $admins = Article::query()->where('slug', 'accounts/roles/admins')->firstOrFail();

    expect($accounts->id)->toBe($parent->id)
        ->and($roles->id)->toBe($child->id)
        ->and($roles->parent_id)->toBe($accounts->id)
        ->and($admins->id)->toBe($grandchild->id)
        ->and($admins->parent_id)->toBe($roles->id)
        ->and($sibling->fresh()->slug)->toBe('users-guide')
        ->and(Article::query()->where('slug', 'like', 'users/%')->count())->toBe(0);

    finCodexEditorDrawer()
        ->call('open', 'accounts/roles')
        ->assertSee('Roles body');
});

it('lists descendants, where they land, media and the guest note in the delete modal', function (): void {
    $this->usesPanel('admin', finCodexEditorUser());

    ['parent' => $parent] = finCodexEditorSeed();

    $component = Livewire::test(EditArticle::class, ['record' => $parent->getRouteKey()]);
    $modal = finCodexEditorDeleteModal($component);

    expect($modal)
        ->toContain(__('fin-codex::fin-codex.editor.delete.children'))
        ->toContain('data-fin-codex-child="users/roles"')
        ->toContain(__('fin-codex::fin-codex.editor.delete.moves_to', ['group' => 'users']))
        ->toContain('data-fin-codex-child="users/roles/admins"')
        ->toContain(__('fin-codex::fin-codex.editor.delete.stays', ['parent' => 'users/roles']))
        ->toContain(__('fin-codex::fin-codex.editor.delete.media'))
        ->toContain('data-fin-codex-exposes')
        ->toContain(__('fin-codex::fin-codex.editor.delete.exposes'))
        ->toContain(__('fin-codex::fin-codex.editor.delete.keep_hidden'))
        ->not->toContain(__('fin-codex::fin-codex.editor.delete.none'));

    foreach (Media::query()->where('article_id', $parent->id)->get() as $media) {
        expect($modal)
            ->toContain('data-fin-codex-media="'.$media->id.'"')
            ->toContain($media->name);
    }

    $component->assertActionDataSet(['keep_hidden' => true]);
});

it('shows nothing-else-affected for a leaf without media and no checkbox', function (): void {
    $this->usesPanel('admin', finCodexEditorUser());

    ['sibling' => $sibling] = finCodexEditorSeed();

    $component = Livewire::test(EditArticle::class, ['record' => $sibling->getRouteKey()]);
    $modal = finCodexEditorDeleteModal($component);

    expect($modal)
        ->toContain(__('fin-codex::fin-codex.editor.delete.none'))
        ->not->toContain('data-fin-codex-child')
        ->not->toContain('data-fin-codex-media')
        ->not->toContain('data-fin-codex-exposes')
        ->not->toContain(__('fin-codex::fin-codex.editor.delete.keep_hidden'))
        ->not->toContain('keep_hidden');

    // An empty schema array makes Action::getSchema() null, so the modal
    // carries no form at all — not even an empty one.
    expect($component->instance()->getMountedAction()->getSchema(Schema::make($component->instance())))->toBeNull();
});

it('deletes and keeps public children hidden by default', function (): void {
    $user = finCodexEditorUser();
    $this->usesPanel('admin', $user);

    ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = finCodexEditorSeed();

    Livewire::test(EditArticle::class, ['record' => $parent->getRouteKey()])
        ->callAction('delete')
        ->assertHasNoActionErrors()
        ->assertRedirect(ArticleResource::getUrl('index', panel: 'admin'));

    expect(Article::query()->where('slug', 'users')->count())->toBe(0)
        ->and($child->fresh()->visibility)->toBe(Visibility::Authenticated)
        ->and($child->fresh()->updated_by)->toBe($user->id)
        ->and($grandchild->fresh()->visibility)->toBe(Visibility::Authenticated)
        ->and($grandchild->fresh()->updated_by)->toBe($user->id)
        ->and(Media::query()->whereNull('article_id')->count())->toBe(2);

    finCodexEditorDrawer()->call('open', 'users/roles')->assertSee('Roles body');

    auth('web')->logout();
    $this->assertGuest('web');

    finCodexEditorDrawer()
        ->call('open', 'users/roles')
        ->assertSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertDontSee('Roles body');
});

it('exposes the children when the admin unticks keep hidden', function (): void {
    $this->usesPanel('admin', finCodexEditorUser());

    ['parent' => $parent, 'child' => $child] = finCodexEditorSeed();

    Livewire::test(EditArticle::class, ['record' => $parent->getRouteKey()])
        ->callAction('delete', data: ['keep_hidden' => false])
        ->assertHasNoActionErrors();

    expect(Article::query()->where('slug', 'users')->count())->toBe(0)
        ->and($child->fresh()->visibility)->toBe(Visibility::Public);

    auth('web')->logout();
    $this->assertGuest('web');

    finCodexEditorDrawer()
        ->call('open', 'users/roles')
        ->assertSee('Roles body');
});

it('deletes a leaf with no side effects', function (): void {
    $this->usesPanel('admin', finCodexEditorUser());

    ['parent' => $parent, 'child' => $child, 'sibling' => $sibling] = finCodexEditorSeed();

    Livewire::test(EditArticle::class, ['record' => $sibling->getRouteKey()])
        ->callAction('delete')
        ->assertHasNoActionErrors()
        ->assertRedirect(ArticleResource::getUrl('index', panel: 'admin'));

    expect(Article::query()->where('slug', 'users-guide')->count())->toBe(0)
        ->and($parent->fresh()->visibility)->toBe(Visibility::Authenticated)
        ->and($child->fresh()->visibility)->toBe(Visibility::Public)
        ->and($child->fresh()->parent_id)->toBe($parent->id)
        ->and(Media::query()->where('article_id', $parent->id)->count())->toBe(2);
});

it('removes the delete action from the table and keeps it on the edit page only', function (): void {
    $this->usesPanel('admin', finCodexEditorUser());

    ['parent' => $parent] = finCodexEditorSeed();

    Livewire::test(ListArticles::class)
        ->assertTableActionDoesNotExist('delete');

    Livewire::test(EditArticle::class, ['record' => $parent->getRouteKey()])
        ->assertActionExists('delete')
        ->assertActionVisible('delete');
});
