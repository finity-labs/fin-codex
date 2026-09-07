<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\DeleteAction;
use Filament\Support\Exceptions\Halt;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Editor\MediaReferences;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\MediaRelationManager;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;

/**
 * Removing an uploaded file, unless something still shows it.
 *
 * The action is ALWAYS visible. A hidden delete reads as broken; a modal that
 * names every article and language still pointing at the file tells the admin
 * which texts to fix first. So a referenced file gets the list and a Close
 * button, and nothing else.
 *
 * There are two stops and both are needed. modalSubmitAction() removes the
 * button, which is the whole UI. The Halt inside using() covers everything
 * else — a stale browser, a direct call, a body that gained the URL while the
 * modal was open. Halt is caught by InteractsWithActions, so no notification
 * fires, which is the right outcome for "nothing happened".
 *
 * DeleteAction rather than a plain Action for the danger colour, the trash icon
 * and the confirmation. using() replaces only the model delete.
 *
 * The authorize() closure is required rather than decorative. Filament falls
 * back to the record's own model policy only while no authorization was set,
 * and that fallback asks about Media — which on a panel with
 * strictAuthorization() is a LogicException, not a denial, and would re-open
 * the hole MediaRelationManager::canViewForRecord() just closed. Never the
 * string form either: Filament unshifts the action's own record as the gate
 * subject, and here that record is a Media row, not the article.
 */
final class DeleteMediaAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->authorize(static function (MediaRelationManager $livewire): bool {
                $owner = $livewire->getOwnerRecord();

                return $owner instanceof Article && ArticleAbility::allows('update', $owner);
            })
            ->modalHeading(static fn (Media $record): string => (string) __(
                'fin-codex::fin-codex.media.delete.heading',
                ['name' => $record->name],
            ))
            ->modalContent(static fn (Media $record): View => view('fin-codex::editor.media-in-use', self::summary($record)))
            // false removes the button; null keeps Filament's own submit
            // action, because getModalSubmitAction() coalesces the closure's
            // result onto it (`$this->evaluate(...) ?? $action`) and would
            // TypeError on a `true`.
            ->modalSubmitAction(static fn (Media $record): ?bool => app(MediaReferences::class)->isReferenced($record) ? false : null)
            ->successNotificationTitle(__('fin-codex::fin-codex.media.delete.done'))
            ->using(static function (Media $record): bool {
                if (app(MediaReferences::class)->isReferenced($record)) {
                    throw new Halt;
                }

                // The row is the record; the file is a side effect. A file
                // that is already gone, or a disk the host has removed from
                // config, must not fail a delete the guard has approved —
                // both disk() and delete() throw for a disk with no driver.
                rescue(fn () => Storage::disk($record->disk)->delete($record->path), report: false);

                $record->delete();

                return true;
            });
    }

    /**
     * Everything the modal prints, decided here so the Blade file only loops —
     * the per-reference sentence included, because it carries placeholders.
     *
     * The guard is asked twice per modal open (once here, once for the submit
     * decision) and memoises nothing, which is the trade 05-07 accepted for
     * DeleteSummary::for(): two cheap queries beat a stale static.
     *
     * @return array{references: list<array{slug: string, locale: string, label: string}>, disk: string}
     */
    private static function summary(Media $record): array
    {
        $references = app(MediaReferences::class)->referencesTo($record);

        return [
            'references' => array_map(static fn (array $reference): array => [
                'slug' => $reference['slug'],
                'locale' => $reference['locale'],
                'label' => (string) __('fin-codex::fin-codex.media.delete.in_use_row', [
                    'slug' => $reference['slug'],
                    'locale' => $reference['locale'],
                ]),
            ], $references),
            'disk' => $record->disk,
        ];
    }
}
