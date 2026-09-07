<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use FinityLabs\LinCodex\Sources\SlugPath;
use Illuminate\Database\Eloquent\Builder;

/**
 * What deleting an article will do, computed before the delete so the
 * confirmation modal can say it in words.
 *
 * The core does the rest through the schema: translations, contexts and
 * revisions cascade with the row, the direct children get parent_id null
 * and the media rows keep their files with article_id null. No descendant
 * is deleted and no slug is rewritten, so lin-codex's slug-derived tree
 * keeps listing the orphans under a folder group named after the missing
 * segment.
 *
 * That group is the catch. ArticleGate walks the ancestor slugs and a
 * folder group hides nothing, so a published, public descendant of an
 * authenticated article becomes readable by guests the moment the
 * authenticated article is gone. exposesPublicChildren is that warning,
 * and exposedDescendants() the exact list ArticleWriter::delete() flips to
 * Authenticated when the panel user asks it to (EDIT-10).
 *
 * The LIKE prefix needs no escaping: slugs are slash-separated kebab-case
 * segments, so "users/%" cannot catch "users-guide".
 */
final readonly class DeleteSummary
{
    /**
     * @param  list<array{slug: string, direct: bool}>  $descendants  every row with slug LIKE "{slug}/%", ordered by slug; direct = its parent is this article (one segment deeper)
     * @param  list<Media>  $media  rows with article_id = this article
     * @param  bool  $exposesPublicChildren  the article is Authenticated and at least one descendant is Public and published
     */
    public function __construct(
        public array $descendants,
        public array $media,
        public bool $exposesPublicChildren,
    ) {}

    public static function for(Article $article): self
    {
        $descendants = array_values(
            self::descendants($article)
                ->get(['slug'])
                ->map(fn (Article $row): array => [
                    'slug' => $row->slug,
                    'direct' => SlugPath::parentOf($row->slug) === $article->slug,
                ])
                ->all()
        );

        $media = array_values(
            Media::query()->where('article_id', $article->id)->orderBy('id')->get()->all()
        );

        return new self($descendants, $media, self::exposedDescendants($article) !== []);
    }

    /**
     * The descendants exposesPublicChildren counts: published and public,
     * below an article only signed-in readers may see. Fresh rows, ready to
     * be flipped and saved.
     *
     * @return list<Article>
     */
    public static function exposedDescendants(Article $article): array
    {
        if ($article->visibility !== Visibility::Authenticated) {
            return [];
        }

        return array_values(
            self::descendants($article)
                ->where('visibility', Visibility::Public)
                ->where('is_published', true)
                ->get()
                ->all()
        );
    }

    /**
     * Nothing else points at this article: the delete is a one-row affair.
     */
    public function isEmpty(): bool
    {
        return $this->descendants === [] && $this->media === [];
    }

    /**
     * @return Builder<Article>
     */
    private static function descendants(Article $article): Builder
    {
        return Article::query()
            ->where('slug', 'like', $article->slug.'/%')
            ->orderBy('slug');
    }
}
