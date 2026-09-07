<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The translation state of one article, in the three words the list column
 * paints: present, missing, outdated.
 *
 * - **missing** — the language has no row at all, or the row it has carries a
 *   blank title or a blank body. The writer refuses to save an incomplete
 *   tab, so a blank row can only come from an import or a direct write; it
 *   still counts as untranslated rather than as a translation.
 * - **outdated** — the row's `updated_at` is *strictly* before the default
 *   language's `updated_at`. Timestamps are second-precision, so two saves in
 *   the same request are never outdated against each other.
 * - **present** — everything else. The default language is never outdated: it
 *   is what the others are compared against.
 *
 * Known edge, accepted for 0.1: a keywords or format change on the article
 * runs `Article::reindexTranslations()`, which saves every translation to the
 * same second and therefore clears every outdated badge on that article even
 * though no text changed. Detecting a real content change needs a stored
 * content hash per translation; that is deferred.
 *
 * No verdict is memoised: `verdicts()` reads the eager-loaded relation the
 * list query already fetched (`with('translations')`), so it costs
 * O(languages) array lookups per row and no query at all.
 *
 * The *configured languages* are memoised per instance, because they are
 * not: `app(CodexSettings::class)` is not a shared binding in a package
 * install, so every `TranslationTabs::languages()` call is one `select ...
 * from settings` (measured). One instance therefore has to serve a whole
 * table render — `ArticlesTable::configure()` resolves the service once and
 * closes over it — or the flags column would issue a settings query per row.
 * A new instance (a new request, a new `app()` call) reads settings again, so
 * a settings change is never served stale.
 */
final class OutdatedTranslations
{
    public const PRESENT = 'present';

    public const MISSING = 'missing';

    public const OUTDATED = 'outdated';

    /** @var array{languages: list<array{code: string, display: string, 'flag-icon': string}>, default: string}|null */
    private ?array $languages = null;

    /**
     * One verdict per configured language, in settings order.
     *
     * The relation is used when it is loaded and loaded once when it is not —
     * a single-model lazy load, which strict models allow.
     *
     * @return array<string, string> locale => PRESENT|MISSING|OUTDATED
     */
    public function verdicts(Article $article): array
    {
        $languages = $this->languages();
        $default = $languages['default'];

        $rows = ($article->relationLoaded('translations')
            ? $article->translations
            : $article->translations()->get())->keyBy('locale');

        $defaultRow = $rows->get($default);
        $defaultAt = $defaultRow instanceof ArticleTranslation ? $defaultRow->updated_at : null;

        $verdicts = [];

        foreach ($languages['languages'] as $language) {
            $code = $language['code'];
            $row = $rows->get($code);

            if (! $row instanceof ArticleTranslation || blank($row->title) || blank($row->body)) {
                $verdicts[$code] = self::MISSING;

                continue;
            }

            $isOutdated = $code !== $default
                && $defaultAt !== null
                && $row->updated_at !== null
                && $row->updated_at->lt($defaultAt);

            $verdicts[$code] = $isOutdated ? self::OUTDATED : self::PRESENT;
        }

        return $verdicts;
    }

    /**
     * Articles with no translation in $locale at all.
     *
     * @param  Builder<Article>  $query
     *
     * @return Builder<Article>
     */
    public function scopeMissing(Builder $query, string $locale): Builder
    {
        return $query->whereDoesntHave(
            'translations',
            fn (Builder $translations): Builder => $translations->where('locale', $locale),
        );
    }

    /**
     * Articles whose $locale translation is older than the default one.
     *
     * The default locale can never be outdated, so asking for it returns an
     * empty set rather than every article.
     *
     * Table names come from the models (`getTable()` reads
     * `lin-codex.table_names.*`), never from a literal `codex_*` string.
     *
     * @param  Builder<Article>  $query
     *
     * @return Builder<Article>
     */
    public function scopeOutdated(Builder $query, string $locale): Builder
    {
        $default = $this->languages()['default'];

        if ($locale === $default) {
            return $query->whereRaw('1 = 0');
        }

        $translations = (new ArticleTranslation)->getTable();
        $articles = (new Article)->getTable();

        return $query->whereExists(
            fn (QueryBuilder $defaultRow): QueryBuilder => $defaultRow
                ->from($translations.' as d')
                ->whereColumn('d.article_id', $articles.'.id')
                ->where('d.locale', $default)
                ->whereExists(
                    fn (QueryBuilder $otherRow): QueryBuilder => $otherRow
                        ->from($translations.' as o')
                        ->whereColumn('o.article_id', 'd.article_id')
                        ->where('o.locale', $locale)
                        ->whereColumn('o.updated_at', '<', 'd.updated_at'),
                ),
        );
    }

    /**
     * The configured languages, read once per instance.
     *
     * @return array{languages: list<array{code: string, display: string, 'flag-icon': string}>, default: string}
     */
    private function languages(): array
    {
        return $this->languages ??= TranslationTabs::languages();
    }
}
