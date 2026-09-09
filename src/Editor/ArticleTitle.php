<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sources\SlugPath;

/**
 * The title the editor shows for an article, wherever it names one: the
 * article list, the related-articles options, the coverage page's attach
 * dialog. The panel's current language first, the default language when
 * that translation is missing, and the last slug segment humanised when
 * there is no title at all.
 *
 * This is the editor's rule, not the reader's: the drawer goes through
 * lin-codex's LocaleResolver and the reading fallback setting, which decide
 * what a reader is shown. An admin browsing the list wants the title in the
 * language of the panel, and the default language is only ever a stand-in.
 */
final class ArticleTitle
{
    public static function of(ArticleData $article): string
    {
        foreach (self::locales() as $locale) {
            $title = $article->translation($locale)?->title;

            if (filled($title)) {
                return (string) $title;
            }
        }

        return SlugPath::humanise(SlugPath::lastSegment($article->slug));
    }

    /**
     * The same rule over a model whose translations are loaded.
     */
    public static function ofModel(Article $record): string
    {
        foreach (self::locales() as $locale) {
            $title = $record->translations->firstWhere('locale', $locale)?->title;

            if (filled($title)) {
                return (string) $title;
            }
        }

        return SlugPath::humanise(SlugPath::lastSegment($record->slug));
    }

    /**
     * The panel language, then the default language, once each.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_values(array_unique([app()->getLocale(), TranslationTabs::languages()['default']]));
    }
}
