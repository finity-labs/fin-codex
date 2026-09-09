<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\MediaRelationManager;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Give me the file." The download streams through the application rather
 * than linking to the disk URL, so it works the same on a local disk and on
 * S3, and the browser saves it under the name it was uploaded with.
 *
 * A file that is gone from the disk — or a disk the host has removed from
 * config — ends in a notification, not a 500: the row is a fact about the
 * database, the file's absence is what the admin needs to hear.
 *
 * The authorize() closure mirrors DeleteMediaAction's, for the same reason:
 * without it Filament falls back to a Media policy that does not exist.
 */
final class DownloadMediaAction
{
    public static function make(): Action
    {
        return Action::make('download')
            ->label(__('fin-codex::fin-codex.media.download.label'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->iconButton()
            ->color('gray')
            ->authorize(static function (MediaRelationManager $livewire): bool {
                $owner = $livewire->getOwnerRecord();

                return $owner instanceof Article && ArticleAbility::allows('update', $owner);
            })
            ->action(static function (Media $record): ?StreamedResponse {
                $exists = rescue(fn (): bool => Storage::disk($record->disk)->exists($record->path), false, report: false);

                if ($exists !== true) {
                    Notification::make()
                        ->warning()
                        ->title(__('fin-codex::fin-codex.media.download.missing', ['disk' => $record->disk]))
                        ->send();

                    return null;
                }

                return Storage::disk($record->disk)->download($record->path, $record->name);
            });
    }
}
