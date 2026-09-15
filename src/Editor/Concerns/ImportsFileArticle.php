<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor\Concerns;

use Filament\Notifications\Notification;
use FinityLabs\FinCodex\Editor\FileArticleAdopter;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinSupport\Panel\Concerns\ResolvesPanelUser;
use RuntimeException;

/**
 * Import one file article with the panel user and open it in the editor: the
 * files tab and the coverage page offer the same action and share this one
 * body. The notification is persistent and sent before the redirect, because
 * Notification::send() pushes it into the session, where the edit page picks
 * it up on the next request.
 *
 * The user id and the resource class live here too, because both callers
 * resolve them the same way: the panel user, and the resource the current
 * panel registered, so a host's articleResource() override builds the URL.
 */
trait ImportsFileArticle
{
    use ResolvesPanelUser;

    private function importFileArticle(string $slug): void
    {
        try {
            $article = app(FileArticleAdopter::class)->adopt($slug, $this->userId());
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title(__('fin-codex::fin-codex.editor.imported.failed'))
                ->body($e->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->warning()
            ->persistent()
            ->title(__('fin-codex::fin-codex.editor.imported.title'))
            ->body(__('fin-codex::fin-codex.editor.imported.body', ['path' => (string) $article->source_path]))
            ->send();

        $this->redirect($this->articleResource()::getUrl('edit', ['record' => $article]));
    }

    /** @return class-string<ArticleResource> */
    private function articleResource(): string
    {
        return FinCodexPlugin::articleResourceClass();
    }

    /** The panel user's id, or null for a panel without an authenticated user. */
    private function userId(): int|string|null
    {
        return $this->panelUserId();
    }
}
