<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * Where an image dropped into the Markdown editor lands, and who is on the
 * hook for it.
 *
 * The disk and the directory are the core's — `lin-codex.media.disk` and
 * `lin-codex.media.directory` — read on every upload rather than captured at
 * boot, so a host that changes them is honoured without a restart. fin-codex
 * writes no config of its own here; it only reads the core's (Pitfall 9).
 *
 * Filament validates the upload against `fileAttachmentsAcceptedFileTypes()`
 * *before* `saveUploadedFileAttachmentUsing()` is ever called, so `store()`
 * never sees anything but one of the five accepted image types. An SVG is
 * refused there, silently and on purpose: it is a script container, not a
 * screenshot.
 *
 * The row is written for every upload, with the panel user in `uploaded_by`.
 * `article_id` follows the page: on edit the record is injected into the
 * save closure and the row is linked immediately; on create there is no
 * record yet, so the row is an orphan until `afterCreate()` calls
 * `linkOrphans()`.
 *
 * Nothing here deletes. An upload the admin removed from the body again
 * stays on disk until the media manager clears it, and that manager refuses
 * while Editor\MediaReferences still finds this URL in any body. The guard is
 * ours, not the core's: lin-codex's Reading\MediaReferences answers a
 * different question — which file-backed article owns a path under the media
 * route prefix — and a database upload's disk URL never matches it.
 */
final class MediaRecorder
{
    /**
     * Store one upload and record it, returning the stored path.
     *
     * Filament turns the path into `Storage::disk($disk)->url($path)`, which
     * is the plain disk URL the core expects to find in a body
     * ("/storage/codex/<hash>.png"). Visibility is set the way Filament sets
     * it — rescued, because a disk that has no concept of per-object
     * visibility must not fail an upload that already succeeded.
     */
    public function store(TemporaryUploadedFile $file, ?Article $article, ?int $userId): string
    {
        $disk = $this->disk();
        $path = $file->store($this->directory(), $disk);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("The help image could not be stored on the [{$disk}] disk.");
        }

        rescue(fn () => Storage::disk($disk)->setVisibility($path, 'public'), report: false);

        Media::create([
            'disk' => $disk,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $userId,
            'article_id' => $article?->id,
        ]);

        return $path;
    }

    /**
     * Give the article id to every orphan row whose URL appears in one of
     * the article's bodies, and return how many were linked.
     *
     * Rows that already belong to an article are never touched, even when
     * this article references them: an image is attributed to whoever
     * uploaded it, and a second article quoting it does not take it over. A
     * row no body mentions stays an orphan.
     *
     * The bodies come from a fresh query rather than the relation, because
     * the caller has just saved through ArticleWriter and strict models
     * refuse an unloaded relation. Only the media rows' own columns are
     * read, so the collection needs no eager loading either.
     */
    public function linkOrphans(Article $article): int
    {
        $bodies = array_filter(
            $article->translations()->get()->pluck('body')->all(),
            static fn (mixed $body): bool => is_string($body) && $body !== '',
        );

        if ($bodies === []) {
            return 0;
        }

        $linked = 0;

        foreach (Media::query()->whereNull('article_id')->get() as $media) {
            $url = $this->url($media);

            if ($url === null) {
                continue;
            }

            foreach ($bodies as $body) {
                if (! str_contains($body, $url)) {
                    continue;
                }

                $media->fill(['article_id' => $article->id])->save();
                $linked++;

                break;
            }
        }

        return $linked;
    }

    /**
     * The public URL of one stored file, or null when its disk cannot build
     * one: a row left behind by a disk that has since been removed from the
     * host's config must not turn every article save into a 500.
     */
    private function url(Media $media): ?string
    {
        /** @var string|null $url */
        $url = rescue(fn (): string => Storage::disk($media->disk)->url($media->path), null, report: false);

        return ($url === null || $url === '') ? null : $url;
    }

    private function disk(): string
    {
        $disk = config('lin-codex.media.disk', 'public');

        return (is_string($disk) && $disk !== '') ? $disk : 'public';
    }

    private function directory(): string
    {
        $directory = config('lin-codex.media.directory', 'codex');

        return (is_string($directory) && $directory !== '') ? $directory : 'codex';
    }
}
