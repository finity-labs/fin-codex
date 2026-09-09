<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Editor\MediaReferences;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\MediaRelationManager;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;

/**
 * "Show me this image." The Media tab's thumbnail is small on purpose; a
 * click, on the thumbnail or on the row's own action, opens the file at its
 * natural size in a modal — the same answer the drawer's lightbox gives.
 *
 * Only an image whose disk can build a URL gets the action: a document has
 * no picture to show, and a row left behind by a disk the host removed
 * from config has nothing to load.
 *
 * The authorize() closure mirrors DeleteMediaAction's, for the same reason:
 * without it Filament falls back to a Media policy that does not exist,
 * which a panel with strictAuthorization() turns into an exception.
 */
final class ViewMediaAction
{
    public static function make(): Action
    {
        return Action::make('view')
            ->label(__('fin-codex::fin-codex.media.view.label'))
            ->icon(Heroicon::OutlinedMagnifyingGlassPlus)
            ->iconButton()
            ->color('gray')
            ->authorize(static function (MediaRelationManager $livewire): bool {
                $owner = $livewire->getOwnerRecord();

                return $owner instanceof Article && ArticleAbility::allows('update', $owner);
            })
            ->visible(static fn (Media $record): bool => self::isViewable($record))
            ->modalHeading(static fn (Media $record): string => $record->name)
            ->modalDescription(static fn (Media $record): string => Number::fileSize($record->size, precision: 1).' · '.$record->mime_type)
            ->modalContent(static fn (Media $record): View => view('fin-codex::editor.media-view', [
                'url' => app(MediaReferences::class)->urlFor($record),
                'name' => $record->name,
            ]))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('fin-codex::fin-codex.media.view.close'));
    }

    private static function isViewable(Media $record): bool
    {
        return str_starts_with($record->mime_type, 'image/')
            && app(MediaReferences::class)->urlFor($record) !== null;
    }
}
