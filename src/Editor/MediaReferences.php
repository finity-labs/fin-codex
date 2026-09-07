<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Who still points at an uploaded file.
 *
 * A media row's URL can be pasted into any article's body, so the scan covers
 * every translation, not just the owning article's — that is the only version
 * that actually prevents a broken image. The needle is the same string
 * MediaRecorder writes into a body (Storage::disk($disk)->url($path)), so the
 * editor and the guard can never disagree about what a reference looks like.
 *
 * lin-codex's own Reading\MediaReferences is a different job: it scans the
 * /codex/media route prefix for file-backed articles, which a database
 * upload's /storage/codex/<hash>.png URL never matches.
 *
 * Nothing is memoised. The delete action asks twice per modal open — once for
 * the content, once for the submit decision — which is the trade 05-07 already
 * accepted for DeleteSummary::for(): two cheap queries beat a stale static.
 */
final class MediaReferences
{
    /**
     * Every translation body that still contains this file's URL.
     *
     * @return list<array{article_id: int, slug: string, locale: string}>
     */
    public function referencesTo(Media $media): array
    {
        $needle = $this->needle($media);

        return ArticleTranslation::query()
            // UNESCAPED on purpose. Backslash-escaping the needle's `%` and
            // `_` matches NOTHING on SQLite, which has no default LIKE escape
            // character, while working on MySQL and PostgreSQL — the suite
            // would go green on a query that is wrong in production, or the
            // reverse. An unescaped LIKE can only over-match, and
            // str_contains() below throws the over-matches away, so the chain
            // has no false negative on any driver. MediaReferencesTest has a
            // row for each half; do not "harden" this line.
            ->where('body', 'like', '%'.$needle.'%')
            // More than one matching row arms preventsLazyLoading, and the
            // slug below is read off the relation.
            ->with('article:id,slug')
            ->get(['id', 'article_id', 'locale', 'body'])
            ->filter(fn (ArticleTranslation $row): bool => str_contains($row->body, $needle))
            ->map(function (ArticleTranslation $row): array {
                // data_get() rather than $row->article?->slug: the relation is
                // typed non-nullable on the core model, so PHPStan rejects the
                // nullsafe form outright, and a hard -> would fatal on a row
                // whose article vanished between the two queries.
                $slug = data_get($row->article, 'slug');

                return [
                    'article_id' => $row->article_id,
                    'slug' => is_string($slug) ? $slug : '',
                    'locale' => $row->locale,
                ];
            })
            ->values()
            ->all();
    }

    public function isReferenced(Media $media): bool
    {
        return $this->referencesTo($media) !== [];
    }

    /**
     * The file's public URL, or null when its disk cannot build one — a row
     * left behind by a disk the host has since removed must not turn the media
     * tab into a 500. The thumbnail column reads this too, which is why it is
     * public: one rescued resolver, not two.
     */
    public function urlFor(Media $media): ?string
    {
        /** @var string|null $url */
        $url = rescue(fn (): string => Storage::disk($media->disk)->url($media->path), null, report: false);

        return ($url === null || $url === '') ? null : $url;
    }

    /**
     * What to look for in a body. Falls back to the stored path, which is a
     * substring of every URL shape and needs no disk: "we could not build a
     * URL" must never read as "nothing references this file".
     */
    private function needle(Media $media): string
    {
        return $this->urlFor($media) ?? $media->path;
    }
}
