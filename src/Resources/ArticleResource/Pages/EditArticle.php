<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\MediaRecorder;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Panel\Concerns\ResolvesPanelUser;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\ConvertToMarkdownAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\DeleteArticleAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\PreviewAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\ContextsRepeater;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing an article. Every save goes to ArticleWriter, so the article, its
 * language tabs and its contexts are written in one transaction under the
 * panel user's attribution and the revision carries that user's id.
 *
 * The header carries the preview slide-over, the convert action an HTML
 * article needs before it can be edited, and the delete action behind the
 * modal that lists what a delete takes with it — a bare DeleteAction would
 * hide those consequences, which is why the table has none. The subheading is
 * the standing notice for an article that shadows a file.
 */
final class EditArticle extends EditRecord
{
    use ResolvesPanelUser;

    protected static string $resource = ArticleResource::class;

    /**
     * The panel's resource, override included: Filament resolves the form,
     * the table, the query, the relation managers, the URLs and the action
     * authorization through this, so a host subclass named through
     * FinCodexPlugin::articleResource() takes effect on the built-in pages.
     *
     * @return class-string<ArticleResource>
     */
    public static function getResource(): string
    {
        return FinCodexPlugin::articleResourceClass();
    }

    /** The language tab the admin is looking at; Tabs::livewireProperty() writes it. */
    public ?string $activeLocale = null;

    /**
     * A standing notice under the page title while a file of the same slug
     * exists: the composite source lets the database row hide it whole, so
     * the file is dead weight until the article is deleted. Asked of the
     * file source, never the composite, which would always answer with this
     * very article.
     *
     * Narrower than the parent's string|Htmlable|null on purpose: the notice
     * is plain text, and Filament escapes a string subheading.
     */
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof Article || app(FilesystemSource::class)->findBySlug($record->slug) === null) {
            return null;
        }

        return __('fin-codex::fin-codex.editor.shadowed', ['path' => (string) ($record->source_path ?? $record->slug)]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Article $record */
        return app(ArticleWriter::class)->update($record, $data, $this->userId());
    }

    /**
     * The form state the writer speaks: enums as their backing values, the
     * json columns as arrays, one entry per stored translation keyed by
     * locale and the stored contexts in sort order. Both are read with a
     * fresh query rather than the relation, so strict models never see an
     * unloaded relation. Contexts declared in code are not here: they have
     * no row to fill from and are listed read-only beside the repeater.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Article) {
            return $data;
        }

        $data['format'] = $record->format->value;
        $data['visibility'] = $record->visibility->value;
        $data['keywords'] = $record->keywords ?? [];
        $data['related'] = $record->related ?? [];
        $data['translations'] = $record->translations()->get()
            ->mapWithKeys(fn (ArticleTranslation $translation): array => [
                $translation->locale => [
                    'title' => $translation->title,
                    'excerpt' => $translation->excerpt,
                    'body' => $translation->body,
                ],
            ])
            ->all();
        $data['contexts'] = ContextsRepeater::fill($record);

        return $data;
    }

    /**
     * The contexts repeater keeps `key` and `url` apart while the admin is
     * picking; the writer wants one key per row, in the order Filament hands
     * the rows back, which is the order they were dragged into.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $rows = $data['contexts'] ?? [];
        $data['contexts'] = ContextsRepeater::dehydrate(is_array($rows) ? array_values($rows) : []);

        return $data;
    }

    /**
     * An upload made on this page is linked to the article the moment it is
     * stored, so this only catches a body that quotes an image someone
     * uploaded elsewhere and never saved — the create page's orphans. It
     * links; it never deletes and never takes an image off another article.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Article) {
            app(MediaRecorder::class)->linkOrphans($record);
        }
    }

    protected function afterFill(): void
    {
        $this->activeLocale ??= TranslationTabs::languages()['default'];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            PreviewAction::make(),
            ConvertToMarkdownAction::make(),
            DeleteArticleAction::make(),
        ];
    }

    /** The panel user's id, or null for a panel without an authenticated user. Public: the header actions attribute their writes to it. */
    public function userId(): ?int
    {
        return $this->panelUserId();
    }
}
