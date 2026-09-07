<?php

use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\HtmlToMarkdown;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\RevisionReason;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;

/*
 * A section rename cascades to every descendant inside the writer's
 * transaction (fresh read per row, top-down, so the core's relinkChildren()
 * cannot leave a grandchild orphaned), and convertToMarkdown() leaves an
 * HTML article editable as Markdown with exactly one Html revision per
 * translation.
 */

const FIN_CODEX_CONVERT_EN = '<h2>Hi</h2><p>Some <strong>bold</strong> text.</p><table><tr><th>a</th></tr><tr><td>x</td></tr></table>';
const FIN_CODEX_CONVERT_DE = '<h2>Hallo</h2>';

function finCodexRenameWriter(): ArticleWriter
{
    return app(ArticleWriter::class);
}

function finCodexRenameUser(): User
{
    return User::create(['name' => 'Editor', 'email' => 'editor@example.com']);
}

/**
 * users (public) > users/roles > users/roles/admins, plus users-guide, an
 * unrelated slug that shares the prefix. Returned as retrieved rows, the
 * way an edit page hands them to the writer: the factory's own instance
 * keeps wasRecentlyCreated, and lin-codex's saved hook relinks children on
 * every save of such an instance, touching their updated_at.
 *
 * @return array{users: Article, roles: Article, admins: Article, guide: Article}
 */
function finCodexRenameSeed(): array
{
    $users = Article::factory()->public()->withTranslation('en', ['title' => 'Users', 'body' => 'Users body'])->create(['slug' => 'users']);
    $roles = Article::factory()->public()->withTranslation('en', ['title' => 'Roles', 'body' => 'Roles body'])->create(['slug' => 'users/roles']);
    $admins = Article::factory()->public()->withTranslation('en', ['title' => 'Admins', 'body' => 'Admins body'])->create(['slug' => 'users/roles/admins']);
    $guide = Article::factory()->public()->withTranslation('en', ['title' => 'Guide', 'body' => 'Guide body'])->create(['slug' => 'users-guide']);

    return ['users' => $users->fresh(), 'roles' => $roles->fresh(), 'admins' => $admins->fresh(), 'guide' => $guide->fresh()];
}

/**
 * The update shape built from the stored row, so a test changes one thing.
 *
 * @param  array<string, mixed>  $overrides
 *
 * @return array<string, mixed>
 */
function finCodexRenameData(Article $article, array $overrides = []): array
{
    $article = $article->fresh();
    $translations = [];

    foreach (ArticleTranslation::query()->where('article_id', $article->id)->orderBy('locale')->get() as $translation) {
        $translations[$translation->locale] = ['title' => $translation->title, 'excerpt' => $translation->excerpt, 'body' => $translation->body];
    }

    $contexts = ArticleContext::query()->where('article_id', $article->id)->orderBy('sort_order')->get()
        ->map(fn (ArticleContext $context): array => ['panel_id' => $context->panel_id ?? '*', 'type' => $context->type->key(), 'key' => $context->key])
        ->all();

    return array_replace_recursive([
        'slug' => $article->slug,
        'icon' => $article->icon,
        'sort_order' => $article->sort_order,
        'format' => $article->format,
        'visibility' => $article->visibility,
        'is_published' => $article->is_published,
        'keywords' => $article->keywords ?? [],
        'related' => $article->related ?? [],
        'translations' => $translations,
        'contexts' => $contexts,
    ], $overrides);
}

function finCodexRenameHtmlArticle(): Article
{
    return Article::factory()->html()->public()
        ->withTranslation('en', ['title' => 'Hi', 'body' => FIN_CODEX_CONVERT_EN])
        ->withTranslation('de', ['title' => 'Hallo', 'body' => FIN_CODEX_CONVERT_DE])
        ->create(['slug' => 'intro']);
}

it('renames a section and every descendant keeps a consistent slug and parent', function (): void {
    ['users' => $users, 'roles' => $roles, 'admins' => $admins, 'guide' => $guide] = finCodexRenameSeed();
    $guideUpdatedAt = $guide->fresh()->updated_at;
    $this->travel(5)->seconds();

    finCodexRenameWriter()->update($users, finCodexRenameData($users, ['slug' => 'accounts']), finCodexRenameUser()->id);

    $accounts = Article::query()->where('slug', 'accounts')->firstOrFail();
    $accountsRoles = Article::query()->where('slug', 'accounts/roles')->firstOrFail();
    $accountsAdmins = Article::query()->where('slug', 'accounts/roles/admins')->firstOrFail();
    $guide = $guide->fresh();

    expect($accounts->id)->toBe($users->id)
        ->and($accounts->parent_id)->toBeNull()
        ->and($accountsRoles->id)->toBe($roles->id)
        ->and($accountsRoles->parent_id)->toBe($users->id)
        ->and($accountsAdmins->id)->toBe($admins->id)
        ->and($accountsAdmins->parent_id)->toBe($roles->id)
        ->and(Article::query()->whereIn('slug', ['users', 'users/roles', 'users/roles/admins'])->count())->toBe(0)
        ->and($guide->slug)->toBe('users-guide')
        ->and($guide->parent_id)->toBeNull()
        ->and($guide->updated_at->equalTo($guideUpdatedAt))->toBeTrue();
});

it('renames a nested section and its subtree only', function (): void {
    ['users' => $users, 'roles' => $roles, 'admins' => $admins, 'guide' => $guide] = finCodexRenameSeed();
    $usersUpdatedAt = $users->fresh()->updated_at;
    $guideUpdatedAt = $guide->fresh()->updated_at;
    $this->travel(5)->seconds();

    finCodexRenameWriter()->update($roles, finCodexRenameData($roles, ['slug' => 'users/permissions']), finCodexRenameUser()->id);

    $permissions = Article::query()->where('slug', 'users/permissions')->firstOrFail();
    $permissionsAdmins = Article::query()->where('slug', 'users/permissions/admins')->firstOrFail();

    expect($permissions->id)->toBe($roles->id)
        ->and($permissions->parent_id)->toBe($users->id)
        ->and($permissionsAdmins->id)->toBe($admins->id)
        ->and($permissionsAdmins->parent_id)->toBe($roles->id)
        ->and(Article::query()->whereIn('slug', ['users/roles', 'users/roles/admins'])->count())->toBe(0)
        ->and($users->fresh()->slug)->toBe('users')
        ->and($users->fresh()->updated_at->equalTo($usersUpdatedAt))->toBeTrue()
        ->and($guide->fresh()->slug)->toBe('users-guide')
        ->and($guide->fresh()->updated_at->equalTo($guideUpdatedAt))->toBeTrue();
});

it('writes no revisions for a rename and keeps search_text', function (): void {
    enableRevisions(true);
    ['users' => $users] = finCodexRenameSeed();
    $before = ArticleTranslation::query()->orderBy('id')->pluck('search_text', 'id')->all();

    finCodexRenameWriter()->update($users, finCodexRenameData($users, ['slug' => 'accounts']), finCodexRenameUser()->id);

    expect($before)->toHaveCount(4)
        ->and(array_filter($before, fn (?string $text): bool => $text === null))->toBe([])
        ->and(ArticleRevision::query()->count())->toBe(0)
        ->and(ArticleTranslation::query()->orderBy('id')->pluck('search_text', 'id')->all())->toBe($before);
});

it('does nothing extra when the slug is unchanged', function (): void {
    enableRevisions(true);
    ['users' => $users, 'roles' => $roles, 'admins' => $admins] = finCodexRenameSeed();
    $rolesUpdatedAt = $roles->fresh()->updated_at;
    $adminsUpdatedAt = $admins->fresh()->updated_at;
    $this->travel(5)->seconds();

    finCodexRenameWriter()->update($users, finCodexRenameData($users, ['translations' => ['en' => ['title' => 'People']]]), finCodexRenameUser()->id);

    $revisions = ArticleRevision::query()->get();

    expect($roles->fresh()->updated_at->equalTo($rolesUpdatedAt))->toBeTrue()
        ->and($admins->fresh()->updated_at->equalTo($adminsUpdatedAt))->toBeTrue()
        ->and($roles->fresh()->parent_id)->toBe($users->id)
        ->and($revisions)->toHaveCount(1)
        ->and($revisions[0]->article_id)->toBe($users->id)
        ->and($revisions[0]->locale)->toBe('en')
        ->and($revisions[0]->title)->toBe('Users');
});

it('converts an HTML article to Markdown with one Html revision per translation', function (): void {
    enableRevisions(true);
    $user = finCodexRenameUser();
    $article = finCodexRenameHtmlArticle();

    $result = finCodexRenameWriter()->convertToMarkdown($article, $user->id);

    $en = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->firstOrFail();
    $de = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'de')->firstOrFail();
    $revisions = ArticleRevision::query()->where('article_id', $article->id)->orderBy('locale')->get();

    expect($result->format)->toBe(ArticleFormat::Markdown)
        ->and($article->fresh()->format)->toBe(ArticleFormat::Markdown)
        ->and($en->body)->toContain('## Hi')
        ->and($en->body)->toContain('**bold**')
        ->and($en->body)->toContain('| a |')
        ->and($en->body)->not->toContain('<')
        ->and($de->body)->toContain('## Hallo')
        ->and($revisions)->toHaveCount(2)
        ->and($revisions->pluck('locale')->all())->toBe(['de', 'en'])
        ->and($revisions->pluck('format')->unique()->all())->toBe([ArticleFormat::Html])
        ->and($revisions->pluck('reason')->unique()->all())->toBe([RevisionReason::Manual])
        ->and($revisions->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($revisions[1]->body)->toBe(FIN_CODEX_CONVERT_EN)
        ->and($revisions[0]->body)->toBe(FIN_CODEX_CONVERT_DE)
        ->and($en->search_text)->toContain('hi')
        ->and($en->search_text)->toContain('bold')
        ->and($en->search_text)->not->toContain('<h2>')
        ->and($en->search_text)->not->toContain('h2');
});

it('is a no-op on a Markdown article', function (): void {
    enableRevisions(true);
    $article = Article::factory()->markdown()->public()->withTranslation('en', ['title' => 'Plain', 'body' => '# Plain'])->create(['slug' => 'plain']);
    $before = ArticleTranslation::query()->where('article_id', $article->id)->firstOrFail();
    $this->travel(5)->seconds();

    $result = finCodexRenameWriter()->convertToMarkdown($article, finCodexRenameUser()->id);

    $after = ArticleTranslation::query()->where('article_id', $article->id)->firstOrFail();

    expect($result)->toBe($article)
        ->and($article->fresh()->format)->toBe(ArticleFormat::Markdown)
        ->and($after->body)->toBe('# Plain')
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and(ArticleRevision::query()->count())->toBe(0);
});

it('converts atomically', function (): void {
    enableRevisions(true);
    $user = finCodexRenameUser();
    $article = finCodexRenameHtmlArticle();

    app()->instance(HtmlToMarkdown::class, new class extends HtmlToMarkdown
    {
        private int $calls = 0;

        public function convert(string $html): string
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('boom');
            }

            return '# ok';
        }
    });

    expect(fn () => finCodexRenameWriter()->convertToMarkdown($article, $user->id))->toThrow(RuntimeException::class, 'boom');

    $bodies = ArticleTranslation::query()->where('article_id', $article->id)->orderBy('locale')->pluck('body', 'locale')->all();

    expect($article->fresh()->format)->toBe(ArticleFormat::Html)
        ->and($bodies)->toBe(['de' => FIN_CODEX_CONVERT_DE, 'en' => FIN_CODEX_CONVERT_EN])
        ->and(ArticleRevision::query()->count())->toBe(0);
});
