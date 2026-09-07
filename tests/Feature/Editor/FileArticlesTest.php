<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Editor\FileArticleAdopter;
use FinityLabs\FinCodex\Resources\ArticleResource\Livewire\FileArticlesTable;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Livewire\HelpDrawer;
use FinityLabs\LinCodex\Models\Article;
use Livewire\Livewire;

/*
 * EDIT-02 through the page the admin actually opens: the "From files" tab.
 *
 * Every row points the file source at tests/Fixtures/docs (intro, users,
 * users/roles, the last one with a German translation) and makes a panel
 * current before touching a Livewire component, because both the tabs and
 * the nested table are built by the panel's resource.
 *
 * The tab swap is asserted on the switching request itself (research
 * Pitfall 3): a ListRecords table is built before the tab update lands, so
 * the proof that matters is that set('activeTab', 'files') already renders
 * the nested component.
 */

function finCodexFilesUser(string $name = 'Filer'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

it('renders the nested files table on the switching request and hides it again', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    Livewire::test(ListArticles::class)
        ->assertSee(__('fin-codex::fin-codex.editor.tabs.articles'))
        ->assertSee(__('fin-codex::fin-codex.editor.tabs.files'))
        ->assertDontSeeLivewire(FileArticlesTable::class)
        ->set('activeTab', 'files')
        ->assertSeeLivewire(FileArticlesTable::class)
        ->set('activeTab', 'articles')
        ->assertDontSeeLivewire(FileArticlesTable::class);

    // ?tab=files deep links through ListRecords' #[Url(as: 'tab')] property.
    $html = $this->get('/admin/help-articles?tab=files')->assertOk()->getContent();

    expect($html)->toContain('users/roles')
        ->toContain(__('fin-codex::fin-codex.editor.files.import'));
});

it('lists file-only slugs with title, languages and path, sorted by slug', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    // intro now has a database row, so it is no longer a file-only article.
    app(FileArticleAdopter::class)->adopt('intro', $user->id);
    forgetHelpMemo();

    $component = Livewire::test(FileArticlesTable::class)
        ->assertCanSeeTableRecords(['users', 'users/roles'], inOrder: true)
        ->assertCanNotSeeTableRecords(['intro']);

    $records = $component->instance()->getTableRecords();

    expect($records->keys()->all())->toBe(['users', 'users/roles'])
        ->and($records['users/roles']['title'])->toBe('Roles')
        // The file source reports the locales it scanned, sorted.
        ->and($records['users/roles']['locales'])->toBe(['de', 'en'])
        ->and($records['users/roles']['path'])->toBe('en/users/roles.md')
        // The title of a section comes from its index.md.
        ->and($records['users']['title'])->toBe('Users')
        ->and($records['users']['locales'])->toBe(['en']);

    $html = $component->html();

    // One badge per language: users has en, users/roles has de and en. The
    // paths are shown relative to the configured docs path, the same string
    // the import stores in source_path.
    expect($html)->toContain('Roles')
        ->toContain('en/users/roles.md')
        ->toContain('en/users/index.md')
        ->toMatch('/fi-badge[^>]*>\s*de\s*</')
        ->not->toContain(dirname(__DIR__, 2).'/Fixtures/docs')
        ->and(substr_count($html, 'fi-badge'))->toBe(3);

    // The tab badge counts what is left to import.
    expect(Livewire::test(ListArticles::class)->html())
        ->toMatch('/fi-badge-label[^>]*>\s*2\s*</');
});

it('searches by slug and by title', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    Livewire::test(FileArticlesTable::class)
        ->searchTable('rol')
        ->assertCanSeeTableRecords(['users/roles'])
        ->assertCanNotSeeTableRecords(['intro'])
        // "Introduction" is intro's title; its slug does not contain it.
        ->searchTable('Introduction')
        ->assertCanSeeTableRecords(['intro'])
        ->assertCanNotSeeTableRecords(['users', 'users/roles']);
});

it('imports and redirects to the edit page with the notification', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    $component = Livewire::test(FileArticlesTable::class)
        ->callTableAction('import', 'users/roles')
        ->assertHasNoTableActionErrors();

    $article = Article::query()->where('slug', 'users/roles')->firstOrFail();

    $component
        ->assertRedirect(AdminHelpArticleResource::getUrl('edit', ['record' => $article], panel: 'admin'))
        ->assertNotified(__('fin-codex::fin-codex.editor.imported.title'));

    expect($article->created_by)->toBe($user->id)
        ->and($article->source_path)->toBe('en/users/roles.md');
});

it('creates no second row when the action is pressed again after the import', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    Livewire::test(FileArticlesTable::class)->callTableAction('import', 'users/roles');

    forgetHelpMemo();

    // The slug is no longer file-only, so a second press comes from a browser
    // whose table is stale. callTableAction() would fail on its own
    // assertActionVisible() first, so the raw mount call is what a stale page
    // actually sends; Filament resolves no record for the key and unmounts the
    // action, so nothing is written and nothing redirects.
    Livewire::test(FileArticlesTable::class)
        ->assertCanNotSeeTableRecords(['users/roles'])
        ->call('mountAction', 'import', [], ['table' => true, 'recordKey' => 'users/roles'])
        ->assertNoRedirect();

    expect(Article::query()->where('slug', 'users/roles')->count())->toBe(1)
        ->and(Article::query()->count())->toBe(1);
});

it('shows the shadowed-file notice on the edit page and not on a database-only article', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    $article = app(FileArticleAdopter::class)->adopt('users/roles', $user->id);
    $notice = __('fin-codex::fin-codex.editor.shadowed', ['path' => 'en/users/roles.md']);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->assertSee($notice);

    $html = $this->get('/admin/help-articles/'.$article->getRouteKey().'/edit')->assertOk()->getContent();

    expect($html)->toContain('en/users/roles.md');

    $databaseOnly = Article::factory()
        ->withTranslation('en', ['title' => 'Billing', 'body' => 'Billing body'])
        ->create(['slug' => 'billing']);

    Livewire::test(EditArticle::class, ['record' => $databaseOnly->getRouteKey()])
        ->assertDontSee(__('fin-codex::fin-codex.editor.shadowed', ['path' => 'billing']));
});

it('serves the database body through the drawer and the JSON API after the import', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    $article = app(FileArticleAdopter::class)->adopt('users/roles', $user->id);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['translations' => ['en' => ['title' => 'Roles', 'body' => 'DB body']]])
        ->call('save')
        ->assertHasNoFormErrors();

    forgetHelpMemo();

    Livewire::test(HelpDrawer::class, ['pageClass' => Dashboard::class, 'panelId' => 'admin', 'guard' => 'web'])
        ->call('open', 'users/roles')
        ->assertSee('DB body')
        ->assertDontSee('Roles are assigned per user.');

    $payload = $this->getJson('/codex/api/articles/users/roles')->assertOk()->json();

    expect($payload['data']['html'])->toContain('DB body')
        ->not->toContain('Roles are assigned per user.');

    // The article now comes from both places, and the files tab has one row
    // fewer.
    Livewire::test(ListArticles::class)->assertSee(__('fin-codex::fin-codex.editor.source.both'));

    Livewire::test(FileArticlesTable::class)
        ->assertCanSeeTableRecords(['intro', 'users'])
        ->assertCanNotSeeTableRecords(['users/roles']);
});

it('uses the staff override URL on the staff panel', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('staff', $user);

    $component = Livewire::test(FileArticlesTable::class)->callTableAction('import', 'intro');

    $article = Article::query()->where('slug', 'intro')->firstOrFail();

    $component->assertRedirect(StaffHelpArticleResource::getUrl('edit', ['record' => $article], panel: 'staff'));
});

it('notifies instead of redirecting when the import fails', function (): void {
    useFixtureDocs();
    $user = finCodexFilesUser();
    $this->usesPanel('admin', $user);

    // FileArticleAdopter is final, so the double is a container stand-in
    // rather than a Mockery mock (05-02's lesson with ArticleWriter).
    $this->instance(FileArticleAdopter::class, new class
    {
        public function adopt(string $slug, ?int $userId): Article
        {
            throw new RuntimeException('boom');
        }
    });

    Livewire::test(FileArticlesTable::class)
        ->callTableAction('import', 'users/roles')
        ->assertNotified(__('fin-codex::fin-codex.editor.imported.failed'))
        ->assertNoRedirect();

    expect(Article::query()->count())->toBe(0);
});
