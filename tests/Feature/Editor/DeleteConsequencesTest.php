<?php

use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\DeleteSummary;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Auth\ArticleGate;
use FinityLabs\LinCodex\Auth\ViewerResolver;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Models\Media;

/*
 * Deleting an article is the one editor action whose consequences reach
 * rows the author is not looking at: descendants lose their parent, media
 * lose their article, and the public children of an authenticated article
 * become readable by guests because the folder group left behind by the
 * slug hides nothing. DeleteSummary computes all three before the delete
 * and ArticleWriter::delete() can keep the children hidden (EDIT-10).
 */

function finCodexDeleteWriter(): ArticleWriter
{
    return app(ArticleWriter::class);
}

function finCodexDeleteUser(string $name = 'Author'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@codex.test']);
}

/**
 * users (authenticated, one context, one revision, two media)
 *   > users/private   (authenticated)
 *   > users/roles     (public, published, one media)
 *     > users/roles/admins (public, published)
 *
 * @return array{parent: Article, child: Article, grandchild: Article, private: Article}
 */
function finCodexDeleteSeed(): array
{
    $parent = Article::factory()->authenticated()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'Users body'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin')
        ->withRevisions(1)
        ->withMedia(2)
        ->create(['slug' => 'users']);

    $child = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Roles', 'body' => 'Roles body'])
        ->withMedia(1)
        ->create(['slug' => 'users/roles']);

    $grandchild = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Admins', 'body' => 'Admins body'])
        ->create(['slug' => 'users/roles/admins']);

    $private = Article::factory()->authenticated()
        ->withTranslation('en', ['title' => 'Private', 'body' => 'Private body'])
        ->create(['slug' => 'users/private']);

    return [
        'parent' => $parent->fresh(),
        'child' => $child->fresh(),
        'grandchild' => $grandchild->fresh(),
        'private' => $private->fresh(),
    ];
}

/**
 * The core's own verdict on one slug for one guard, read through a fresh
 * content source: the drawer, the tree, search and the API all ask this
 * gate, so it is the honest proof of what a delete exposes. forgetHelpMemo()
 * keeps the read fresh even though neither the composite source nor the
 * database source memoizes anything today.
 */
function finCodexDeleteAllows(string $slug, ?string $guard = null): bool
{
    forgetHelpMemo();

    $all = app(ContentSource::class)->all();

    return app(ArticleGate::class)->allows($all[$slug], app(ViewerResolver::class)->resolve($guard), $all);
}

it('summarises descendants, media and the guest exposure', function (): void {
    ['parent' => $parent, 'grandchild' => $grandchild] = finCodexDeleteSeed();

    $summary = DeleteSummary::for($parent);

    expect($summary->descendants)->toBe([
        ['slug' => 'users/private', 'direct' => true],
        ['slug' => 'users/roles', 'direct' => true],
        ['slug' => 'users/roles/admins', 'direct' => false],
    ])
        ->and($summary->media)->toHaveCount(2)
        ->and(array_map(fn (Media $media): ?int => $media->article_id, $summary->media))->toBe([$parent->id, $parent->id])
        ->and($summary->exposesPublicChildren)->toBeTrue()
        ->and($summary->isEmpty())->toBeFalse();

    $leaf = DeleteSummary::for($grandchild);

    expect($leaf->descendants)->toBe([])
        ->and($leaf->media)->toBe([])
        ->and($leaf->exposesPublicChildren)->toBeFalse()
        ->and($leaf->isEmpty())->toBeTrue();

    expect(array_map(fn (Article $article): string => $article->slug, DeleteSummary::exposedDescendants($parent)))
        ->toBe(['users/roles', 'users/roles/admins']);
});

it('reports no exposure for a public parent or an unpublished child', function (): void {
    $public = Article::factory()->public()->withTranslation('en', ['title' => 'Guides', 'body' => 'Guides body'])->create(['slug' => 'guides']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Intro', 'body' => 'Intro body'])->create(['slug' => 'guides/intro']);

    $hidden = Article::factory()->authenticated()->withTranslation('en', ['title' => 'Ops', 'body' => 'Ops body'])->create(['slug' => 'ops']);
    Article::factory()->public()->unpublished()->withTranslation('en', ['title' => 'Runbook', 'body' => 'Runbook body'])->create(['slug' => 'ops/runbook']);

    $publicSummary = DeleteSummary::for($public->fresh());
    $hiddenSummary = DeleteSummary::for($hidden->fresh());

    expect($publicSummary->exposesPublicChildren)->toBeFalse()
        ->and(DeleteSummary::exposedDescendants($public->fresh()))->toBe([])
        ->and($publicSummary->descendants)->toBe([['slug' => 'guides/intro', 'direct' => true]])
        ->and($hiddenSummary->exposesPublicChildren)->toBeFalse()
        ->and(DeleteSummary::exposedDescendants($hidden->fresh()))->toBe([])
        ->and($hiddenSummary->descendants)->toBe([['slug' => 'ops/runbook', 'direct' => true]])
        ->and($hiddenSummary->media)->toBe([])
        ->and($hiddenSummary->isEmpty())->toBeFalse();
});

it('deletes with the core consequences and leaves the children exposed when asked to', function (): void {
    enableRevisions(true);
    ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild, 'private' => $private] = finCodexDeleteSeed();
    $user = finCodexDeleteUser();

    expect(finCodexDeleteAllows('users/roles'))->toBeFalse();

    finCodexDeleteWriter()->delete($parent, keepChildrenHidden: false, userId: $user->id);

    expect(Article::query()->where('slug', 'users')->count())->toBe(0)
        ->and(ArticleTranslation::query()->where('article_id', $parent->id)->count())->toBe(0)
        ->and(ArticleContext::query()->where('article_id', $parent->id)->count())->toBe(0)
        ->and(ArticleRevision::query()->where('article_id', $parent->id)->count())->toBe(0)
        ->and(Media::query()->count())->toBe(3)
        ->and(Media::query()->whereNull('article_id')->count())->toBe(2)
        ->and(Media::query()->where('article_id', $child->id)->count())->toBe(1)
        ->and($child->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->visibility)->toBe(Visibility::Public)
        ->and($private->fresh()->parent_id)->toBeNull()
        ->and($grandchild->fresh()->parent_id)->toBe($child->id)
        ->and($grandchild->fresh()->visibility)->toBe(Visibility::Public);

    expect(finCodexDeleteAllows('users/roles'))->toBeTrue()
        ->and(finCodexDeleteAllows('users/roles/admins'))->toBeTrue();
});

it('keeps public descendants hidden from guests when asked to', function (): void {
    enableRevisions(true);
    ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild, 'private' => $private] = finCodexDeleteSeed();
    $bob = finCodexDeleteUser('Bob');

    finCodexDeleteWriter()->delete($parent, keepChildrenHidden: true, userId: $bob->id);

    expect(Article::query()->where('slug', 'users')->count())->toBe(0)
        ->and($child->fresh()->visibility)->toBe(Visibility::Authenticated)
        ->and($child->fresh()->updated_by)->toBe($bob->id)
        ->and($child->fresh()->parent_id)->toBeNull()
        ->and($grandchild->fresh()->visibility)->toBe(Visibility::Authenticated)
        ->and($grandchild->fresh()->updated_by)->toBe($bob->id)
        ->and($grandchild->fresh()->parent_id)->toBe($child->id)
        ->and($private->fresh()->visibility)->toBe(Visibility::Authenticated)
        ->and($private->fresh()->updated_by)->toBeNull()
        ->and(ArticleRevision::query()->count())->toBe(0)
        ->and(finCodexDeleteAllows('users/roles'))->toBeFalse()
        ->and(finCodexDeleteAllows('users/roles/admins'))->toBeFalse();

    $this->actingAs($bob, 'web');

    expect(finCodexDeleteAllows('users/roles', 'web'))->toBeTrue()
        ->and(finCodexDeleteAllows('users/roles/admins', 'web'))->toBeTrue();
});

it('deletes in one transaction', function (): void {
    ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = finCodexDeleteSeed();
    $user = finCodexDeleteUser();

    Article::deleting(function (): void {
        throw new RuntimeException('boom');
    });

    expect(fn () => finCodexDeleteWriter()->delete($parent, keepChildrenHidden: true, userId: $user->id))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Article::query()->where('slug', 'users')->count())->toBe(1)
        ->and($child->fresh()->visibility)->toBe(Visibility::Public)
        ->and($child->fresh()->updated_by)->toBeNull()
        ->and($grandchild->fresh()->visibility)->toBe(Visibility::Public)
        ->and($grandchild->fresh()->updated_by)->toBeNull()
        ->and(ArticleTranslation::query()->where('article_id', $parent->id)->count())->toBe(1)
        ->and(Media::query()->whereNull('article_id')->count())->toBe(0);
});
