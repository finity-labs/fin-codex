<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use FinityLabs\FinCodex\Editor\OutdatedTranslations;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use FinityLabs\LinCodex\Sources\SlugPath;
use Illuminate\Database\Eloquent\Builder;

/**
 * The article list: one row per database article, sorted by slug so a section
 * and its children read together like a table of contents.
 *
 * Every derived column carries `->state()`. The suite runs under
 * `Model::shouldBeStrict()`, and a column without it would fall back to
 * `getStateFromRecord()`, which `data_get()`s an attribute the query never
 * selected and throws.
 *
 * Two things are read once per table build and closed over rather than looked
 * up per row: the configured languages (one settings query per call) and the
 * `OutdatedTranslations` service (which memoises the same read per instance).
 * The translations themselves come from `with('translations')`, so the flags
 * column costs no query at all. The file slugs are the one per-row lookup;
 * `FilesystemSource` is a singleton that memoises its scan by path
 * fingerprint, and asking it per row is what lets a test point the source at
 * a different docs tree between two renders.
 *
 * No delete action and no bulk actions: deleting an article has consequences
 * the admin must see first, so it lives on the edit page behind 05-07's
 * confirmation modal.
 */
final class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        $languages = TranslationTabs::languages();
        $default = $languages['default'];
        $verdicts = app(OutdatedTranslations::class);

        /** @var array<string, string> $localeOptions */
        $localeOptions = array_column($languages['languages'], 'display', 'code');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('translations'))
            ->defaultSort('slug')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->columns([
                ViewColumn::make('slug')
                    ->label(__('fin-codex::fin-codex.editor.columns.slug'))
                    ->view('fin-codex::editor.slug-column')
                    ->state(fn (Article $record): array => self::pathParts($record, $default))
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $where): Builder => $where
                            ->where('slug', 'like', "%{$search}%")
                            ->orWhereHas(
                                'translations',
                                fn (Builder $translations): Builder => $translations->where('title', 'like', "%{$search}%"),
                            ),
                    )),
                TextColumn::make('source')
                    ->label(__('fin-codex::fin-codex.editor.columns.source'))
                    ->state(fn (Article $record): string => isset(self::fileSlugs()[$record->slug]) ? 'both' : 'database')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("fin-codex::fin-codex.editor.source.{$state}"))
                    ->color(fn (string $state): string => $state === 'both' ? 'info' : 'gray'),
                IconColumn::make('is_published')
                    ->label(__('fin-codex::fin-codex.editor.columns.published'))
                    ->boolean(),
                TextColumn::make('visibility')
                    ->label(__('fin-codex::fin-codex.editor.columns.visibility'))
                    ->badge()
                    ->formatStateUsing(fn (Visibility $state): string => $state->label()),
                TextColumn::make('format')
                    ->label(__('fin-codex::fin-codex.editor.columns.format'))
                    ->badge()
                    ->formatStateUsing(fn (ArticleFormat $state): string => $state->label()),
                ViewColumn::make('languages')
                    ->label(__('fin-codex::fin-codex.editor.columns.languages'))
                    ->view('fin-codex::editor.languages-column')
                    ->state(fn (Article $record): array => self::flags($record, $verdicts, $languages['languages']))
                    ->toggleable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label(__('fin-codex::fin-codex.editor.filters.published')),
                SelectFilter::make('visibility')
                    ->label(__('fin-codex::fin-codex.editor.filters.visibility'))
                    ->options(fn (): array => collect(Visibility::cases())
                        ->mapWithKeys(fn (Visibility $visibility): array => [$visibility->value => $visibility->label()])
                        ->all()),
                SelectFilter::make('format')
                    ->label(__('fin-codex::fin-codex.editor.filters.format'))
                    ->options(fn (): array => collect(ArticleFormat::cases())
                        ->mapWithKeys(fn (ArticleFormat $format): array => [$format->value => $format->label()])
                        ->all()),
                SelectFilter::make('source')
                    ->label(__('fin-codex::fin-codex.editor.filters.source'))
                    ->options(fn (): array => [
                        'database' => __('fin-codex::fin-codex.editor.source.database'),
                        'both' => __('fin-codex::fin-codex.editor.source.both'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $slugs = array_keys(self::fileSlugs());

                        return match ($data['value'] ?? null) {
                            'both' => $query->whereIn('slug', $slugs),
                            'database' => $query->whereNotIn('slug', $slugs),
                            default => $query,
                        };
                    }),
                SelectFilter::make('missing')
                    ->label(__('fin-codex::fin-codex.editor.filters.missing'))
                    ->options($localeOptions)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $verdicts->scopeMissing($query, (string) $data['value'])
                        : $query),
                SelectFilter::make('outdated')
                    ->label(__('fin-codex::fin-codex.editor.filters.outdated'))
                    ->options($localeOptions)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $verdicts->scopeOutdated($query, (string) $data['value'])
                        : $query),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * The slug split for the identity column: the parent path (null at the top
     * level) and the default-language title, falling back to a humanised last
     * segment when the article has no translation in that language yet.
     *
     * @return array{slug: string, parent: string|null, title: string}
     */
    private static function pathParts(Article $record, string $defaultLocale): array
    {
        $title = $record->translations->firstWhere('locale', $defaultLocale)?->title;

        return [
            'slug' => $record->slug,
            'parent' => SlugPath::parentOf($record->slug),
            'title' => blank($title)
                ? SlugPath::humanise(SlugPath::lastSegment($record->slug))
                : $title,
        ];
    }

    /**
     * One prepared flag per configured language, so the view stays a loop.
     *
     * @param  list<array{code: string, display: string, 'flag-icon': string}>  $languages
     *
     * @return list<array{code: string, flag: string, state: string, tooltip: string}>
     */
    private static function flags(Article $record, OutdatedTranslations $verdicts, array $languages): array
    {
        $states = $verdicts->verdicts($record);

        return array_map(static function (array $language) use ($states): array {
            $state = $states[$language['code']] ?? OutdatedTranslations::MISSING;

            return [
                'code' => $language['code'],
                'flag' => TranslationTabs::flag($language['flag-icon']),
                'state' => $state,
                'tooltip' => $language['display'].': '.__("fin-codex::fin-codex.editor.state.{$state}"),
            ];
        }, $languages);
    }

    /**
     * The slugs the file source knows, as a lookup set.
     *
     * @return array<string, true>
     */
    private static function fileSlugs(): array
    {
        return array_fill_keys(array_keys(app(FilesystemSource::class)->all()), true);
    }
}
