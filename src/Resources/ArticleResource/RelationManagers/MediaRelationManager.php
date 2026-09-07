<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Editor\MediaReferences;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\DeleteMediaAction;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * Every file this article carries: a thumbnail, a readable size, who uploaded
 * it and when — and a delete that says no while a body still shows it.
 *
 * The relationship is Article::media(), so a row whose article was deleted
 * (article_id null) cannot appear here at all. Cleaning those up is a separate
 * job and is deferred.
 *
 * No header actions on purpose: a file uploaded here would be one nothing
 * points at. Images arrive through the Markdown editor, which writes the
 * reference into the body in the same breath.
 *
 * The preview is a ViewColumn over a rescued URL rather than Filament's own
 * image column, which calls Storage::disk() and url() unrescued and does one
 * exists() per row: one stale row on a disk the host has removed from config
 * would take the whole tab down.
 *
 * The manager stays lazy (CanBeLazy::$isLazy is true and this class does not
 * override it), like the revisions one beside it.
 */
final class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('fin-codex::fin-codex.media.title');
    }

    /**
     * The tab answers to the article's update ability, and to nothing else.
     *
     * Filament's own canViewForRecord() authorizes viewAny on the Media model.
     * On a panel with strictAuthorization() that is a LogicException rather
     * than a denial, and RelationManager only catches AuthorizationException —
     * so the edit page 500s on a bare install with a perfect ArticlePolicy.
     * Overriding without calling parent:: means Media is never looked up at
     * all, the same shape that already keeps the revisions manager safe.
     *
     * fin-codex ships no MediaPolicy on purpose. A file is only ever uploaded,
     * shown and deleted through its article, so whoever may edit the article
     * may manage its files, and a host has one policy to override instead of
     * two.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Article
            && ArticleAbility::allows('update', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        $references = app(MediaReferences::class);

        return $table
            // The uploader is read per row, and Builder::hydrate() arms
            // preventsLazyLoading above one row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('uploader'))
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading(__('fin-codex::fin-codex.media.empty'))
            ->emptyStateDescription(__('fin-codex::fin-codex.media.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedPhoto)
            ->columns([
                ViewColumn::make('preview')
                    ->label(__('fin-codex::fin-codex.media.columns.preview'))
                    ->view('fin-codex::editor.media-thumbnail')
                    ->state(fn (Media $record): array => [
                        'url' => $references->urlFor($record),
                        'isImage' => str_starts_with($record->mime_type, 'image/'),
                        'label' => $record->name,
                    ]),
                TextColumn::make('name')
                    ->label(__('fin-codex::fin-codex.media.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('size')
                    ->label(__('fin-codex::fin-codex.media.columns.size'))
                    ->state(fn (Media $record): string => Number::fileSize($record->size, precision: 1))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('size', $direction)),
                TextColumn::make('mime_type')
                    ->label(__('fin-codex::fin-codex.media.columns.type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('uploader')
                    ->label(__('fin-codex::fin-codex.media.columns.uploader'))
                    ->state(fn (Media $record): string => self::uploaderName($record)),
                TextColumn::make('created_at')
                    ->label(__('fin-codex::fin-codex.media.columns.date'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->headerActions([])
            ->recordActions([
                DeleteMediaAction::make(),
            ]);
    }

    /**
     * The uploader's name, or the unknown string for a file uploaded by nobody
     * and for one whose user row has since been deleted.
     *
     * data_get() rather than the nullsafe property: the relation points at
     * config('auth.providers.users.model') and is typed Model|null on the core
     * model, which PHPStan level 5 has no $name on.
     */
    private static function uploaderName(Media $record): string
    {
        $name = data_get($record->uploader, 'name');

        return is_string($name) && $name !== ''
            ? $name
            : (string) __('fin-codex::fin-codex.media.no_uploader');
    }
}
