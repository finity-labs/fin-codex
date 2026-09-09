<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Carbon\CarbonInterface;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * Two doors lead here. The body editor's drop zone, which Filament validates
 * against `fileAttachmentsAcceptedFileTypes()` before
 * `saveUploadedFileAttachmentUsing()` is ever called, so it only ever hands
 * over one of the five raster image types — an SVG is refused there,
 * silently and on purpose: it is a script container, not a screenshot. And
 * the Media tab's upload action, validated against the plugin's document
 * types, for the PDFs and office files an article links to.
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
        $directory = $this->directory();
        $path = $file->storeAs($directory, $this->storedName($file, $disk, $directory), $disk);

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
     * The name the file is stored under: its own, not a hash, so the URL an
     * article links and the name a browser saves it as both read like the
     * upload. Slugified, because the URL lands inside Markdown, where a
     * space ends the link; the extension is the upload's, lower-cased. A
     * second file of the same name in the same directory gets -2, -3.
     */
    private function storedName(TemporaryUploadedFile $file, string $disk, string $directory): string
    {
        $original = $file->getClientOriginalName();
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME));
        $base = $base === '' ? 'file' : $base;
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $extension = $extension === '' ? strtolower((string) $file->guessExtension()) : $extension;
        $suffix = $extension === '' ? '' : '.'.$extension;

        $storage = Storage::disk($disk);
        $name = $base.$suffix;

        for ($attempt = 2; $storage->exists($directory.'/'.$name); $attempt++) {
            $name = $base.'-'.$attempt.$suffix;
        }

        return $name;
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

    /**
     * The directory uploads land in: `lin-codex.media.directory` with its
     * date placeholders expanded — `{Y}`, `{m}` and `{d}` become the
     * upload's year, month and day, so a busy site's images spread over
     * `codex/2026/09` rather than one flat folder. A row keeps the path it
     * was stored under, so changing the setting moves nothing.
     */
    public function directory(?CarbonInterface $at = null): string
    {
        $directory = config('lin-codex.media.directory', 'codex');
        $directory = (is_string($directory) && $directory !== '') ? $directory : 'codex';
        $at ??= Carbon::now();

        return trim(strtr($directory, [
            '{Y}' => $at->format('Y'),
            '{m}' => $at->format('m'),
            '{d}' => $at->format('d'),
        ]), '/');
    }
}
