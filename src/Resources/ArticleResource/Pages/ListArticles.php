<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use FinityLabs\FinCodex\Coverage\WarningsSection;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Livewire\FileArticlesTable;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;

/**
 * The article list, in two tabs: the database articles, and the ones that
 * still live only in a file.
 *
 * The second tab is a nested Livewire component beside this page's own table
 * in `content()` rather than a second mode of that table. `ListRecords`
 * builds its table before the `activeTab` update is applied, so a table
 * switching between a query and `records()` would still show the database
 * rows on the request that switches the tab (research Pitfall 3). Which of
 * the two is shown is decided by visibility closures, evaluated as the schema
 * renders, not by building a different schema per tab: since Filament 4.15 /
 * 5.10 a resource page resolves its record while hydrating every Livewire
 * update, which reads the page's schemas through the magic getter and caches
 * `content()` before the tab update lands, so a schema built around
 * `$activeTab` would be the previous tab's on exactly that request.
 *
 * `ListRecords` declares `#[Url(as: 'tab')] public ?string $activeTab`, so
 * `?tab=files` deep-links to the second tab, and `getDefaultActiveTab()`
 * picks the first key, which keeps `articles` the default.
 */
final class ListArticles extends ListRecords
{
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

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'articles' => Tab::make(__('fin-codex::fin-codex.editor.tabs.articles')),
            'files' => Tab::make(__('fin-codex::fin-codex.editor.tabs.files'))
                ->badge(fn (): ?int => $this->fileArticleCount() ?: null),
        ];
    }

    /**
     * Filament's own list content with the nested component shown in the
     * table's place on the files tab; the render hooks stay where hosts
     * expect them.
     *
     * The warnings section goes above the tab strip and outside
     * getTabsContentComponent(): a source warning describes the whole source,
     * not one tab, and DeclaredContextsSource contributes warnings that have
     * nothing to do with files. It renders nothing when the sources are happy.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            WarningsSection::make(),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make()
                ->hidden(fn (): bool => $this->isOnFilesTab()),
            Livewire::make(FileArticlesTable::class)
                ->key('fin-codex-files-table')
                ->visible(fn (): bool => $this->isOnFilesTab()),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    private function isOnFilesTab(): bool
    {
        return $this->activeTab === 'files';
    }

    /**
     * How many articles are still file-only. One composite read per render
     * (the file scan behind it is memoised by path fingerprint), never one
     * per row.
     */
    private function fileArticleCount(): int
    {
        return count(array_filter(
            app(ContentSource::class)->all(),
            fn (ArticleData $article): bool => $article->id === null,
        ));
    }
}
