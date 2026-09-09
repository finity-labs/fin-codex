<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Editor\MediaRecorder;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\MediaRelationManager;
use FinityLabs\LinCodex\Models\Article;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The Media tab's way in for documents — a PDF, an office file, plain text
 * — which the body editor's drop zone, an image affair, cannot take. The
 * file lands where images do, through MediaRecorder, attributed to this
 * article and the panel user; the body editor's "Insert file" picker then
 * links it from any article.
 *
 * The accepted types and the size ceiling are the plugin's documentTypes()
 * and documentMaxSize(). Filament validates both before the action runs;
 * storeFiles(false) keeps the upload temporary so the recorder is the one
 * that writes it, to the dated directory, with the row.
 *
 * Note for later: a document on the public disk is reachable by anyone
 * holding its URL, exactly like an image is. Authenticated delivery — an
 * "authenticated users only" flag on this upload, a private disk, and a
 * view/download route that asks the article gate — is EXT-09 in the
 * planning notes, deliberately not built yet.
 */
final class UploadMediaAction
{
    public static function make(): Action
    {
        $uploads = FinCodexPlugin::documentUploads();

        return Action::make('upload')
            ->label(__('fin-codex::fin-codex.media.upload.label'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('fin-codex::fin-codex.media.upload.heading'))
            ->modalSubmitActionLabel(__('fin-codex::fin-codex.media.upload.label'))
            ->modalWidth('lg')
            ->authorize(static function (MediaRelationManager $livewire): bool {
                $owner = $livewire->getOwnerRecord();

                return $owner instanceof Article && ArticleAbility::allows('update', $owner);
            })
            ->schema([
                FileUpload::make('file')
                    ->label(__('fin-codex::fin-codex.media.upload.file'))
                    ->acceptedFileTypes($uploads['types'])
                    ->maxSize($uploads['maxSize'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(static function (array $data, MediaRelationManager $livewire): void {
                $file = $data['file'] ?? null;
                $owner = $livewire->getOwnerRecord();

                if (! $file instanceof TemporaryUploadedFile || ! $owner instanceof Article) {
                    return;
                }

                $userId = Filament::auth()->id();

                app(MediaRecorder::class)->store($file, $owner, is_numeric($userId) ? (int) $userId : null);

                Notification::make()
                    ->success()
                    ->title(__('fin-codex::fin-codex.media.upload.done'))
                    ->send();
            });
    }
}
