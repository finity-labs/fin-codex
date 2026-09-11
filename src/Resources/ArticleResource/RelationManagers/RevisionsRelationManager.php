<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\RestoreRevisionAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\RevisionPreviewAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinSupport\Panel\Concerns\ResolvesPanelUser;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Revisions\RevisionManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The article's history: one flat table of every revision in every language,
 * newest first, with a locale filter for narrowing it to one.
 *
 * Not grouped by language and not one table per tab. The last change to the
 * article is the top row whichever language it was written in, which is the
 * question an admin opening the history actually has.
 *
 * The manager stays lazy (CanBeLazy::$isLazy is true and this class does not
 * override it). The edit page already renders four sidebar sections, a
 * Markdown editor per language tab and a 324-icon select; the history is
 * worth one extra round trip. `protected static bool $isLazy = false;` is the
 * one-line escape hatch for a host that wants it eager.
 *
 * No getBadge(): a count badge costs one extra query on every page render and
 * the number is right there in the table.
 */
final class RevisionsRelationManager extends RelationManager
{
    /** 06-02's restore action attributes its write to the panel user. */
    use ResolvesPanelUser;

    protected static string $relationship = 'revisions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('fin-codex::fin-codex.revisions.title');
    }

    /**
     * The whole manager disappears while revisions are off — tab, table and
     * all. RevisionManager::enabled() answers false for an unseeded settings
     * group too, so a fresh install shows no history tab either.
     *
     * One settings query per call and no memo on purpose: the switch can be
     * flipped between two renders, and a memo would make the gate lie.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(RevisionManager::class)->enabled();
    }

    /**
     * The panel user, for the restore action's attribution. Public because an
     * action closure holds the manager as $livewire and reaches it from
     * outside the class, the way EditArticle::userId() serves its header
     * actions.
     */
    public function userId(): int|string|null
    {
        return $this->panelUserId();
    }

    public function table(Table $table): Table
    {
        $languages = TranslationTabs::languages();

        /** @var array<string, string> $flags */
        $flags = array_column($languages['languages'], 'flag-icon', 'code');

        /** @var array<string, string> $localeOptions */
        $localeOptions = array_column($languages['languages'], 'display', 'code');

        return $table
            // Mandatory, not an optimisation: Builder::hydrate() arms
            // preventsLazyLoading only when the query returned more than one
            // row, so the author column throws on the second revision and
            // passes on the first.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading(__('fin-codex::fin-codex.revisions.empty'))
            ->emptyStateDescription(__('fin-codex::fin-codex.revisions.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('fin-codex::fin-codex.revisions.columns.time'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('locale')
                    ->label(__('fin-codex::fin-codex.revisions.columns.locale'))
                    ->badge()
                    ->color('gray')
                    ->state(fn (ArticleRevision $record): string => trim(
                        TranslationTabs::flag($flags[$record->locale] ?? $record->locale).' '.$record->locale,
                    ))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('locale', $direction)),
                TextColumn::make('author')
                    ->label(__('fin-codex::fin-codex.revisions.columns.author'))
                    ->state(fn (ArticleRevision $record): string => self::authorName($record)),
                TextColumn::make('reason')
                    ->label(__('fin-codex::fin-codex.revisions.columns.reason'))
                    ->badge()
                    ->state(fn (ArticleRevision $record): string => $record->reason->label()),
                TextColumn::make('title')
                    ->label(__('fin-codex::fin-codex.revisions.columns.title'))
                    ->limit(60),
            ])
            ->filters([
                SelectFilter::make('locale')
                    ->native(false)->preload()->searchable(false)
                    ->label(__('fin-codex::fin-codex.revisions.columns.locale'))
                    ->options($localeOptions),
            ])
            ->recordActions([
                RevisionPreviewAction::make(),
                RestoreRevisionAction::make(),
            ]);
    }

    /**
     * The author's name, or the unknown-author string for a revision written
     * by nobody (an import, a console command) and for one whose user row has
     * since been deleted.
     *
     * The relation is typed Model|null on the core model — the host's user
     * class is config — so the name is read with data_get() and narrowed
     * rather than as a property PHPStan cannot see.
     */
    private static function authorName(ArticleRevision $record): string
    {
        $name = data_get($record->user, 'name');

        return is_string($name) && $name !== ''
            ? $name
            : (string) __('fin-codex::fin-codex.revisions.no_author');
    }
}
