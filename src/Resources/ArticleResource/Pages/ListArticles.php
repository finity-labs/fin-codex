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
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Livewire\FileArticlesTable;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;

/**
 * The article list, in two tabs: the database articles, and the ones that
 * still live only in a file.
 *
 * The second tab is a nested Livewire component swapped into `content()`
 * rather than a second mode of this page's own table. `ListRecords` builds
 * its table before the `activeTab` update is applied, so a table switching
 * between a query and `records()` would still show the database rows on the
 * request that switches the tab (research Pitfall 3); replacing the content
 * component happens during the render that follows the update, so the files
 * table is already there.
 *
 * `ListRecords` declares `#[Url(as: 'tab')] public ?string $activeTab`, so
 * `?tab=files` deep-links to the second tab, and `getDefaultActiveTab()`
 * picks the first key, which keeps `articles` the default.
 */
final class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

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
     * Filament's own list content with the table swapped for the nested
     * component on the files tab; the render hooks stay where hosts expect
     * them.
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
            $this->activeTab === 'files'
                ? Livewire::make(FileArticlesTable::class)->key('fin-codex-files-table')
                : EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
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
