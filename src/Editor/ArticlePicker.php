<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\LinCodex\Contracts\ContentSource;

/**
 * The rows behind the article pickers: the related-articles field on the
 * form and the coverage page's attach dialog. Both pick from the content
 * source, keyed by slug, because that is what the two fields store — a
 * related list refers to file articles as readily as to stored ones.
 *
 * The title follows ArticleTitle's rule, so the picker names an article the
 * way the article list does.
 */
final class ArticlePicker
{
    public function __construct(private readonly ContentSource $source) {}

    /**
     * Every article, in slug order, minus the one being edited — an article
     * is never related to itself — and, for a picker that has to write a
     * context, minus the file articles that have no row to hang one on.
     *
     * @return list<array{key: string, title: string, slug: string, languages: list<string>, source: string, published: bool}>
     */
    public function rows(?string $except = null, bool $storedOnly = false): array
    {
        $rows = [];

        foreach ($this->source->all() as $article) {
            if ($article->slug === $except) {
                continue;
            }

            if ($storedOnly && $article->id === null) {
                continue;
            }

            $rows[] = [
                'key' => $article->slug,
                'title' => ArticleTitle::of($article),
                'slug' => $article->slug,
                'languages' => $article->locales(),
                'source' => $article->id === null ? 'file' : 'database',
                'published' => $article->published,
            ];
        }

        return $rows;
    }
}
