<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\RevisionsRelationManager;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Rendering\ArticleRenderer;
use Illuminate\Contracts\View\View;

/**
 * "What did this version actually say?"
 *
 * A revision row is a title and a timestamp; deciding whether to restore it
 * means reading the text, and reading it as raw Markdown is not reading it.
 * The body goes through lin-codex's ArticleRenderer — the same pipeline the
 * drawer, the help centre and the editor's own preview use — so a callout, a
 * steps fence or a figure looks here exactly as it will for a reader.
 *
 * No diff and no side-by-side view: the locked decision is the rendered
 * article, and a diff renderer is its own surface (deferred in 06-CONTEXT).
 *
 * Nothing is written. The slide-over reads a stored row and renders it.
 */
final class RevisionPreviewAction
{
    public static function make(): Action
    {
        return Action::make('preview')
            ->label(__('fin-codex::fin-codex.revisions.preview.label'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->iconButton()
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('fin-codex::fin-codex.revisions.preview.close'))
            ->modalHeading(static fn (ArticleRevision $record): string => (string) __(
                'fin-codex::fin-codex.revisions.preview.heading',
                [
                    'locale' => mb_strtoupper($record->locale),
                    'time' => $record->created_at?->toDayDateTimeString() ?? '',
                ],
            ))
            ->modalContent(static fn (ArticleRevision $record, RevisionsRelationManager $livewire): View => view(
                'fin-codex::editor.revision-preview',
                [
                    'html' => self::render($record, $livewire),
                    'title' => $record->title,
                    'locale' => $record->locale,
                    'hasDarkMode' => Filament::getCurrentPanel()?->hasDarkMode() ?? true,
                ],
            ));
    }

    /**
     * The revision's stored body as safe HTML, in the format it was written
     * in. A revision carries its own format, which is what makes a
     * pre-conversion HTML snapshot render as HTML long after the article
     * became Markdown. The slug only affects relative link resolution and
     * comes from the owner record.
     */
    private static function render(ArticleRevision $record, RevisionsRelationManager $livewire): string
    {
        $owner = $livewire->getOwnerRecord();

        return app(ArticleRenderer::class)->render(
            $record->body,
            $record->format,
            $record->locale,
            $owner instanceof Article ? $owner->slug : '',
        )->html;
    }
}
