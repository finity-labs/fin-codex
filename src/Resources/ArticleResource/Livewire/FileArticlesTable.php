<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Editor\FileArticleAdopter;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinSupport\Panel\Concerns\ResolvesPanelUser;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use FinityLabs\LinCodex\Sources\SlugPath;
use Livewire\Component;
use RuntimeException;

/**
 * The "From files" tab: every article the content source knows from a
 * Markdown or HTML file that has no database row yet, with the action that
 * imports one of them and opens it in the editor.
 *
 * It is a nested Livewire component rather than a second mode of the list
 * page's own table on purpose. `ListRecords` builds its table in
 * `bootedInteractsWithTable()`, which runs before the `activeTab` update is
 * applied, so a table that switched between a query and `records()` would
 * still render the database rows on the request that switches the tab
 * (research Pitfall 3). Swapping the whole content component works, because
 * the schema is built during the render that follows the update.
 *
 * The rows are arrays, not models: Filament keys an array record by its
 * array key (`ArrayRecord::getKeyName()` is `__key`), so the slug is the
 * record key and `wire:key`, the action's `recordKey` and
 * `assertCanSeeTableRecords()` all speak slugs. Search, sorting and
 * pagination belong to the closure — Filament hands a plain array straight
 * to the view — and the whole (small) map is returned at once, which is why
 * no paginator is rendered.
 *
 * `ContentSource` is the decorated composite: an article declared in code
 * through `HasHelp` but written as a file is here too, and a slug that has a
 * database row has an id and is filtered out, which is what makes an
 * imported article leave this tab.
 */
final class FileArticlesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use ResolvesPanelUser;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, array $sort): array => $this->fileArticles($search, $sort))
            ->columns([
                TextColumn::make('slug')
                    ->label(__('fin-codex::fin-codex.editor.columns.slug'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('fin-codex::fin-codex.editor.files.title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('locales')
                    ->label(__('fin-codex::fin-codex.editor.files.locales'))
                    ->badge(),
                TextColumn::make('path')
                    ->label(__('fin-codex::fin-codex.editor.files.path'))
                    ->color('gray'),
            ])
            ->recordActions([
                Action::make('import')
                    // Class level, and a Closure rather than the string form:
                    // the record here is a plain array, there is no article
                    // yet, and `import` falls back to `create` for exactly
                    // that reason. Hiding the button is the UX; the
                    // enforcement lives in FileArticleAdopter::adopt().
                    ->authorize(static fn (): bool => ArticleAbility::allows('import'))
                    ->label(__('fin-codex::fin-codex.editor.files.import'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (array $record) => $this->import((string) $record['slug'])),
            ])
            ->emptyStateHeading(__('fin-codex::fin-codex.editor.files.empty'))
            ->emptyStateIcon(Heroicon::OutlinedDocumentText);
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }

    /**
     * Import one file article with the panel user and open it. The
     * notification is persistent and sent before the redirect, because
     * `Notification::send()` pushes it into the session, where the edit page
     * picks it up on the next request.
     */
    private function import(string $slug): void
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

    /**
     * Every file-only article as a table row, searched and sorted in memory.
     *
     * @param  array{0: string|null, 1: string|null}  $sort  column and direction; both null until a header is clicked
     *
     * @return array<string, array{slug: string, title: string, locales: list<string>, path: string|null}>
     */
    private function fileArticles(?string $search, array $sort): array
    {
        $default = TranslationTabs::languages()['default'];
        $paths = app(FilesystemSource::class)->paths();

        $rows = collect(app(ContentSource::class)->all())
            ->filter(fn (ArticleData $article): bool => $article->id === null)
            ->map(fn (ArticleData $article): array => [
                'slug' => $article->slug,
                'title' => self::title($article, $default),
                'locales' => $article->locales(),
                'path' => self::relativePath($article->sourcePath, $paths),
            ]);

        if (filled($search)) {
            $needle = mb_strtolower($search);

            $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['slug']), $needle)
                || str_contains(mb_strtolower($row['title']), $needle));
        }

        $column = in_array($sort[0] ?? null, ['slug', 'title'], true) ? $sort[0] : 'slug';

        $rows = ($sort[1] ?? 'asc') === 'desc'
            ? $rows->sortByDesc($column, SORT_NATURAL)
            : $rows->sortBy($column, SORT_NATURAL);

        return $rows->keyBy('slug')->all();
    }

    /**
     * The default language's title, falling back to a humanised last slug
     * segment for a file that carries no translation in it — the same
     * fallback the Articles tab uses.
     */
    private static function title(ArticleData $article, string $defaultLocale): string
    {
        $title = $article->translation($defaultLocale)?->title;

        return blank($title)
            ? SlugPath::humanise(SlugPath::lastSegment($article->slug))
            : $title;
    }

    /**
     * The file path relative to the longest configured docs path that holds
     * it, which is the string the import stores in `source_path` and the one
     * the edit page's shadowed notice repeats. `ArticleImporter` derives it
     * the same way behind a private method; these four lines mirror it
     * rather than reach into the core.
     *
     * @param  list<string>  $paths
     */
    private static function relativePath(?string $absolute, array $paths): ?string
    {
        if ($absolute === null) {
            return null;
        }

        $normalised = str_replace('\\', '/', $absolute);
        $best = null;

        foreach ($paths as $path) {
            $prefix = rtrim(str_replace('\\', '/', $path), '/').'/';

            if (str_starts_with($normalised, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? $normalised : substr($normalised, strlen($best));
    }

    /**
     * The resource the current panel registered, so a host's
     * `articleResource()` override builds the edit URL.
     *
     * @return class-string<ArticleResource>
     */
    private function articleResource(): string
    {
        return FinCodexPlugin::articleResourceClass();
    }

    /** The panel user's id, or null for a panel without an authenticated user. */
    private function userId(): ?int
    {
        return $this->panelUserId();
    }
}
