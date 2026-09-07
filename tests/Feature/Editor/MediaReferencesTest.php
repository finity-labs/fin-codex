<?php

use FinityLabs\FinCodex\Editor\MediaReferences;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Support\Facades\Storage;

/*
 * MEDIA-01, first half: who still points at an uploaded file.
 *
 * The guard is driven directly — no panel, no Filament, no relation manager —
 * because the behaviour that matters is a database scan and a string compare,
 * and both are worth pinning where they run in milliseconds.
 *
 * The needle is the same string MediaRecorder writes into a body
 * (Storage::disk($disk)->url($path)), so the editor and the guard can never
 * disagree about what a reference looks like.
 */

/** The fake media disk with a URL root, plus the core config that names it. */
function finCodexRefsDisk(): void
{
    Storage::fake('media', ['url' => '/media']);
    config()->set('lin-codex.media.disk', 'media');
}

function finCodexRefsArticle(string $slug): Article
{
    return Article::factory()->create(['slug' => $slug]);
}

function finCodexRefsMedia(string $path = 'codex/shot.png', string $disk = 'media', ?Article $article = null): Media
{
    return Media::factory()->create([
        'disk' => $disk,
        'path' => $path,
        'name' => basename($path),
        'article_id' => $article?->id,
    ]);
}

function finCodexRefsBody(Article $article, string $body, string $locale = 'en'): ArticleTranslation
{
    return ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => $locale,
        'title' => mb_strtoupper($locale).' title',
        'body' => $body,
    ]);
}

/** The URL a body would carry, built the way MediaRecorder's upload does. */
function finCodexRefsUrl(Media $media): string
{
    return Storage::disk($media->disk)->url($media->path);
}

it('finds the translation body that carries the file URL', function (): void {
    finCodexRefsDisk();

    $article = finCodexRefsArticle('users');
    $media = finCodexRefsMedia(article: $article);
    finCodexRefsBody($article, 'Look: ![shot]('.finCodexRefsUrl($media).')');

    $references = app(MediaReferences::class);

    expect($references->referencesTo($media))->toBe([
        ['article_id' => $article->id, 'slug' => 'users', 'locale' => 'en'],
    ])->and($references->isReferenced($media))->toBeTrue();
});

it('scans every article, not just the one the file belongs to', function (): void {
    finCodexRefsDisk();

    $owner = finCodexRefsArticle('users');
    $quoter = finCodexRefsArticle('roles');
    $media = finCodexRefsMedia(article: $owner);

    // The owner's own body says nothing; a different article shows the image.
    finCodexRefsBody($owner, 'How users work.');
    finCodexRefsBody($quoter, 'See ![shot]('.finCodexRefsUrl($media).') for the layout.');

    expect(app(MediaReferences::class)->referencesTo($media))->toBe([
        ['article_id' => $quoter->id, 'slug' => 'roles', 'locale' => 'en'],
    ]);
});

it('lists every language of the same article separately', function (): void {
    finCodexRefsDisk();

    $article = finCodexRefsArticle('users');
    $media = finCodexRefsMedia(article: $article);
    $url = finCodexRefsUrl($media);

    finCodexRefsBody($article, 'English: ![shot]('.$url.')');
    finCodexRefsBody($article, 'Deutsch: ![shot]('.$url.')', 'de');

    $found = app(MediaReferences::class)->referencesTo($media);

    expect($found)->toHaveCount(2)
        ->and(array_column($found, 'locale'))->toBe(['en', 'de'])
        ->and(array_column($found, 'slug'))->toBe(['users', 'users']);
});

it('reports nothing for a file no body mentions', function (): void {
    finCodexRefsDisk();

    $article = finCodexRefsArticle('users');
    $media = finCodexRefsMedia(article: $article);
    finCodexRefsBody($article, 'How users work, with no pictures at all.');

    $references = app(MediaReferences::class);

    expect($references->referencesTo($media))->toBe([])
        ->and($references->isReferenced($media))->toBeFalse();
});

it('still finds a path carrying LIKE wildcards', function (): void {
    // THE GUARD RAIL. A future "hardening" refactor that escapes the needle
    // with addcslashes($needle, '%_\\') would turn this row red on SQLite,
    // which has no default LIKE escape character, while leaving MySQL and
    // PostgreSQL green — the suite would go green on a query that is wrong in
    // production, or the reverse. The needle stays UNESCAPED and str_contains()
    // throws the over-matches away; see the over-match row below.
    finCodexRefsDisk();

    $article = finCodexRefsArticle('users');
    $media = finCodexRefsMedia('codex/a_b%c.png', article: $article);
    finCodexRefsBody($article, 'Wildcards: ![odd]('.finCodexRefsUrl($media).')');

    expect(app(MediaReferences::class)->referencesTo($media))->toBe([
        ['article_id' => $article->id, 'slug' => 'users', 'locale' => 'en'],
    ]);
});

it('throws away the bodies the unescaped wildcards over-match', function (): void {
    finCodexRefsDisk();

    $article = finCodexRefsArticle('users');
    $media = finCodexRefsMedia('codex/a_b%c.png', article: $article);

    // The LIKE pattern "%/media/codex/a_b%c.png%" matches this body — `_` is
    // any character and `%` any run of them — and str_contains() has the final
    // word, so it is not reported.
    finCodexRefsBody($article, 'A different file: ![other](/media/codex/aXbYc.png)');

    $references = app(MediaReferences::class);

    expect($references->referencesTo($media))->toBe([])
        ->and($references->isReferenced($media))->toBeFalse();
});

it('falls back to the stored path when the disk can no longer build a URL', function (): void {
    finCodexRefsDisk();

    $article = finCodexRefsArticle('users');
    $media = finCodexRefsMedia('codex/gone.png', disk: 'gone', article: $article);
    finCodexRefsBody($article, 'Left over: ![gone](/media/codex/gone.png)');

    $references = app(MediaReferences::class);

    // Both halves matter: a rescued null must never read as "nothing
    // references this file".
    expect($references->urlFor($media))->toBeNull()
        ->and($references->referencesTo($media))->toBe([
            ['article_id' => $article->id, 'slug' => 'users', 'locale' => 'en'],
        ])
        ->and($references->isReferenced($media))->toBeTrue();
});

it('matches the relative URL the local driver returns and the absolute form alike', function (): void {
    finCodexRefsDisk();

    $relative = finCodexRefsArticle('users');
    $absolute = finCodexRefsArticle('roles');
    $media = finCodexRefsMedia(article: $relative);
    $url = finCodexRefsUrl($media);

    expect($url)->toBe('/media/codex/shot.png');

    finCodexRefsBody($relative, 'Relative: ![shot]('.$url.')');
    finCodexRefsBody($absolute, 'Absolute: ![shot](http://localhost'.$url.')');

    expect(array_column(app(MediaReferences::class)->referencesTo($media), 'slug'))
        ->toBe(['users', 'roles']);
});

it('reads several matching bodies without a lazy-loading violation', function (): void {
    finCodexRefsDisk();

    $owner = finCodexRefsArticle('users');
    $media = finCodexRefsMedia(article: $owner);
    $url = finCodexRefsUrl($media);

    // Three rows: Builder::hydrate() arms preventsLazyLoading only above one
    // row, so the eager load is what keeps the slug lookup legal.
    foreach (['users', 'roles', 'teams'] as $slug) {
        $article = $slug === 'users' ? $owner : finCodexRefsArticle($slug);
        finCodexRefsBody($article, 'Shared: ![shot]('.$url.')');
    }

    $found = app(MediaReferences::class)->referencesTo($media);

    expect($found)->toHaveCount(3)
        ->and(array_column($found, 'slug'))->toBe(['users', 'roles', 'teams']);
});
