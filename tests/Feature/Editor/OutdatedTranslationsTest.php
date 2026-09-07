<?php

use FinityLabs\FinCodex\Editor\OutdatedTranslations;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Support\Facades\DB;

/*
 * The translation state the list page paints on every row: one verdict per
 * configured language plus the two filter scopes behind the "missing
 * language" and "outdated language" selects.
 *
 * Timestamps are second-precision, so every row that needs an ordering
 * travels the clock between the two saves rather than hoping the test is
 * slow enough (Pitfall 6).
 */

/**
 * @param  list<string>  $codes
 */
function finCodexOutdatedUseLanguages(array $codes, string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/** An article with one complete translation in the given locale. */
function finCodexOutdatedSeed(string $slug = 'users', string $title = 'Users', string $locale = 'en'): Article
{
    $article = Article::factory()->create(['slug' => $slug]);

    finCodexOutdatedTranslate($article, $locale, $title);

    return $article;
}

function finCodexOutdatedTranslate(Article $article, string $locale, string $title): ArticleTranslation
{
    return ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => $locale,
        'title' => $title,
        'body' => $title.' body',
    ]);
}

/** Re-read the article with its translations, the way the table's query does. */
function finCodexOutdatedVerdicts(Article $article): array
{
    /** @var Article $fresh */
    $fresh = Article::query()->with('translations')->findOrFail($article->id);

    return app(OutdatedTranslations::class)->verdicts($fresh);
}

it('marks a configured language missing when no row or a blank title exists', function (): void {
    finCodexOutdatedUseLanguages(['en', 'de', 'hu']);

    $article = finCodexOutdatedSeed();

    $verdicts = finCodexOutdatedVerdicts($article);

    expect(array_keys($verdicts))->toBe(['en', 'de', 'hu'])
        ->and($verdicts)->toBe([
            'en' => OutdatedTranslations::PRESENT,
            'de' => OutdatedTranslations::MISSING,
            'hu' => OutdatedTranslations::MISSING,
        ]);

    // A blank title can only be written around the writer, which refuses one;
    // the verdict must still call it missing rather than present.
    $german = finCodexOutdatedTranslate($article, 'de', 'Benutzer');

    DB::table((new ArticleTranslation)->getTable())
        ->where('id', $german->id)
        ->update(['title' => '']);

    expect(finCodexOutdatedVerdicts($article)['de'])->toBe(OutdatedTranslations::MISSING);
});

it('marks a language outdated when the default was saved later, and never the default itself', function (): void {
    finCodexOutdatedUseLanguages(['en', 'de']);

    $article = finCodexOutdatedSeed();
    finCodexOutdatedTranslate($article, 'de', 'Benutzer');

    $this->travelTo(now()->addMinute());

    $english = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->sole();
    $english->update(['title' => 'Users v2']);

    expect(finCodexOutdatedVerdicts($article))->toBe([
        'en' => OutdatedTranslations::PRESENT,
        'de' => OutdatedTranslations::OUTDATED,
    ]);

    // The same two rows with German as the default: English is newer than the
    // default, and the default is never outdated against itself.
    finCodexOutdatedUseLanguages(['en', 'de'], 'de');

    expect(finCodexOutdatedVerdicts($article))->toBe([
        'en' => OutdatedTranslations::PRESENT,
        'de' => OutdatedTranslations::PRESENT,
    ]);
});

it('clears the verdict once the language is saved again', function (): void {
    finCodexOutdatedUseLanguages(['en', 'de']);

    $article = finCodexOutdatedSeed();
    finCodexOutdatedTranslate($article, 'de', 'Benutzer');

    $this->travelTo(now()->addMinute());

    ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->sole()
        ->update(['title' => 'Users v2']);

    expect(finCodexOutdatedVerdicts($article)['de'])->toBe(OutdatedTranslations::OUTDATED);

    $this->travelTo(now()->addMinute());

    ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'de')->sole()
        ->update(['title' => 'Benutzer v2']);

    expect(finCodexOutdatedVerdicts($article)['de'])->toBe(OutdatedTranslations::PRESENT);
});

it('reads the loaded relation without a query per language', function (): void {
    finCodexOutdatedUseLanguages(['en', 'de', 'hu']);

    $article = finCodexOutdatedSeed();
    finCodexOutdatedTranslate($article, 'de', 'Benutzer');

    $service = app(OutdatedTranslations::class);

    /** @var Article $loaded */
    $loaded = Article::query()->with('translations')->findOrFail($article->id);
    /** @var Article $bare */
    $bare = Article::query()->findOrFail($article->id);

    DB::enableQueryLog();
    DB::flushQueryLog();

    // First call: the one settings read the instance memoises, nothing else.
    $service->verdicts($loaded);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->toContain('settings');

    DB::flushQueryLog();

    // Every later row on the same instance is pure array work, three
    // languages or thirty.
    $service->verdicts($loaded);

    expect(DB::getQueryLog())->toBeEmpty();

    DB::flushQueryLog();

    // An article whose relation is not loaded costs exactly one lazy load,
    // never one query per language.
    $service->verdicts($bare);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->toContain((new ArticleTranslation)->getTable());

    DB::disableQueryLog();
});

it('filters missing and outdated locales through the scopes', function (): void {
    finCodexOutdatedUseLanguages(['en', 'de']);

    $current = finCodexOutdatedSeed('a-current', 'Current');
    finCodexOutdatedTranslate($current, 'de', 'Aktuell');

    finCodexOutdatedSeed('b-missing', 'Missing');

    $stale = finCodexOutdatedSeed('c-stale', 'Stale');
    finCodexOutdatedTranslate($stale, 'de', 'Veraltet');

    $this->travelTo(now()->addMinute());

    ArticleTranslation::query()->where('article_id', $stale->id)->where('locale', 'en')->sole()
        ->update(['title' => 'Stale v2']);

    $service = app(OutdatedTranslations::class);

    expect($service->scopeMissing(Article::query(), 'de')->pluck('slug')->all())->toBe(['b-missing'])
        ->and($service->scopeOutdated(Article::query(), 'de')->pluck('slug')->all())->toBe(['c-stale'])
        ->and($service->scopeOutdated(Article::query(), 'en')->pluck('slug')->all())->toBe([])
        ->and($service->scopeMissing(Article::query(), 'en')->pluck('slug')->all())->toBe([]);
});

it('uses the configured table names', function (): void {
    finCodexOutdatedUseLanguages(['en', 'de']);

    $sql = app(OutdatedTranslations::class)->scopeOutdated(Article::query(), 'de')->toSql();

    expect($sql)->toContain((new ArticleTranslation)->getTable())
        ->and($sql)->toContain((new Article)->getTable())
        ->and(config('lin-codex.table_names.article_translations'))->toBe('codex_article_translations');
});
