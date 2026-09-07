<?php

use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;

/*
 * The first test Pest runs. It exists to fail loudly when the harness drops
 * lin-codex's model hooks (migrations before the dispatcher, wrong provider
 * order): parent_id, search_text and revisions would then silently stay empty
 * while every editor test still passed.
 */

it('writes parent_id, search_text and a revision through lin-codex model hooks', function (): void {
    enableRevisions(true);

    $users = Article::factory()->create(['slug' => 'users']);
    ArticleTranslation::factory()->create(['article_id' => $users->id, 'locale' => 'en', 'title' => 'Users', 'body' => 'Users body']);

    $roles = Article::factory()->create(['slug' => 'users/roles']);
    $translation = ArticleTranslation::factory()->create(['article_id' => $roles->id, 'locale' => 'en', 'title' => 'Roles', 'body' => 'Roles body']);

    expect($roles->fresh()->parent_id)->toBe($users->id)
        ->and($translation->fresh()->search_text)->not->toBeNull()
        ->and(ArticleRevision::query()->where('article_id', $roles->id)->count())->toBe(0);

    $translation->fresh()->update(['title' => 'Roles v2']);

    expect(ArticleRevision::query()->where('article_id', $roles->id)->count())->toBe(1);
});

it('writes no revision while revisions are disabled in settings', function (): void {
    enableRevisions(false);

    $article = Article::factory()->create(['slug' => 'users']);
    $translation = ArticleTranslation::factory()->create(['article_id' => $article->id, 'locale' => 'en', 'title' => 'Users', 'body' => 'Users body']);

    $translation->fresh()->update(['title' => 'Users v2']);

    expect(ArticleRevision::query()->where('article_id', $article->id)->count())->toBe(0);
});
