<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Models\Article;

/**
 * The one way out of a read-only HTML article.
 *
 * An imported HTML body is not editable in the panel (see TranslationTabs),
 * so this action is what makes such an article writable again: every
 * translation is converted in one transaction through ArticleWriter, which
 * records one revision per translation carrying the original HTML and the
 * panel user, so nothing is lost and the article can be read back as it was.
 * The confirmation is there because the conversion is not reversible from
 * the form — undoing it means restoring a revision.
 *
 * The page then redirects to itself instead of refilling the form. The
 * schema was built while the record was still Html, so its body components
 * are the disabled textareas; a fillForm() would put Markdown into them and
 * leave the article looking read-only until the next full page load. A
 * redirect rebuilds the schema from the converted record, and the
 * notification survives it because Notification::send() goes through the
 * session.
 *
 * The format check and the ability check compose: Filament ANDs every reason an
 * action can be hidden, so a Markdown article keeps no convert button however
 * permissive the policy is, and an HTML one loses it the moment the policy says
 * no. The Closure form of authorize() is mandatory here too — the string form
 * would work by accident on this action, because its record IS the article, and
 * would then be copied to one where it is not.
 */
final class ConvertToMarkdownAction
{
    public static function make(): Action
    {
        return Action::make('convert')
            ->authorize(static fn (?Article $record): bool => $record !== null && ArticleAbility::allows('convert', $record))
            ->label(__('fin-codex::fin-codex.editor.convert.label'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('warning')
            ->visible(static fn (?Article $record): bool => $record?->format === ArticleFormat::Html)
            ->requiresConfirmation()
            ->modalHeading(__('fin-codex::fin-codex.editor.convert.heading'))
            ->modalDescription(__('fin-codex::fin-codex.editor.convert.description'))
            ->action(static function (Article $record, EditArticle $livewire): void {
                app(ArticleWriter::class)->convertToMarkdown($record, $livewire->userId());

                Notification::make()
                    ->success()
                    ->title(__('fin-codex::fin-codex.editor.convert.done'))
                    ->send();

                $livewire->redirect($livewire->getResourceUrl('edit'));
            });
    }
}
