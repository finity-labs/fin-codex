<?php

use Filament\Facades\Filament;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\MediaRelationManager;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * AUTH-01's acceptance test: a bare install, one shipped policy, strict
 * authorization on.
 *
 * Both pages are rendered, and the edit page is the point. Filament's strict
 * mode THROWS rather than denies when a model has no policy, and the media
 * relation manager inherits a canViewForRecord() that asks about the Media
 * model — a LogicException that RelationManager does not catch, because it
 * only catches AuthorizationException. A test that stopped at the list page
 * would have been green while the editor was a 500.
 *
 * fin-codex still ships no MediaPolicy. Media is only ever edited through its
 * article, so the article's update ability is the whole answer and a host has
 * exactly one policy to override.
 */

function finCodexStrictUser(string $email = 'strict@example.com'): User
{
    return User::create(['name' => 'Strict', 'email' => $email]);
}

/** The admin panel, current, serving, signed in and strict. */
function finCodexStrictPanel(User $user): void
{
    test()->usesPanel('admin', $user);

    Filament::getPanel('admin')->strictAuthorization();
}

function finCodexStrictArticle(string $slug = 'users'): Article
{
    return Article::factory()->create(['slug' => $slug]);
}

/** One media row on the fake disk, so the tab has something to show. */
function finCodexStrictMedia(Article $article): Media
{
    Storage::fake('media', ['url' => '/media']);
    config()->set('lin-codex.media.disk', 'media');

    $media = Media::factory()->create([
        'disk' => 'media',
        'path' => 'codex/shot.png',
        'mime_type' => 'image/png',
        'size' => 2_048,
        'article_id' => $article->id,
    ]);

    Storage::disk('media')->put($media->path, 'binary');

    return $media;
}

it('renders the article list under strict authorization', function (): void {
    $user = finCodexStrictUser();
    finCodexStrictPanel($user);

    finCodexStrictArticle();

    Livewire::test(ListArticles::class)->assertOk();
});

it('renders the edit page under strict authorization', function (): void {
    $user = finCodexStrictUser();
    finCodexStrictPanel($user);

    $article = finCodexStrictArticle();
    finCodexStrictMedia($article);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->assertOk();
});

it('keeps the media tab on the strict edit page', function (): void {
    $user = finCodexStrictUser();
    finCodexStrictPanel($user);

    $article = finCodexStrictArticle();
    finCodexStrictMedia($article);

    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);
    $instance = $page->instance();

    expect($instance)->toBeInstanceOf(EditArticle::class)
        ->and(array_keys($instance->getCachedRelationManagers()))->toContain('media');
});

it('offers the media delete under strict authorization', function (): void {
    $user = finCodexStrictUser();
    finCodexStrictPanel($user);

    $article = finCodexStrictArticle();
    $media = finCodexStrictMedia($article);

    Livewire::test(MediaRelationManager::class, [
        'ownerRecord' => $article,
        'pageClass' => EditArticle::class,
    ])
        ->assertOk()
        ->assertTableActionVisible('delete', $media);
});

it('answers the media tab with the owner article update ability', function (): void {
    $user = finCodexStrictUser();
    finCodexStrictPanel($user);

    $article = finCodexStrictArticle();

    expect(MediaRelationManager::canViewForRecord($article, EditArticle::class))->toBeTrue();

    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    expect(MediaRelationManager::canViewForRecord($article, EditArticle::class))->toBeFalse();
});

it('hides the media delete while the owner article is closed', function (): void {
    $user = finCodexStrictUser();
    finCodexStrictPanel($user);

    $article = finCodexStrictArticle();
    $media = finCodexStrictMedia($article);

    $manager = Livewire::test(MediaRelationManager::class, [
        'ownerRecord' => $article,
        'pageClass' => EditArticle::class,
    ]);

    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    $manager->assertTableActionHidden('delete', $media);
});
