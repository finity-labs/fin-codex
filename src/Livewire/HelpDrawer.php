<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use FinityLabs\LinCodex\Data\TreeNode;
use FinityLabs\LinCodex\Livewire\HelpDrawer as CoreHelpDrawer;
use FinityLabs\LinCodex\Reading\ReadArticle;
use FinityLabs\LinCodex\Search\SearchHit;
use FinityLabs\LinCodex\Search\SearchResult;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The core drawer, presented with Filament's schema components.
 *
 * Every property, action and the Alpine glue are the core's: this class only
 * decides how the state is shown. Three schemas do that — header(), content()
 * and footer() — built from Filament's own components: icon-button actions
 * for back and close, a live TextInput for the search, Tabs bound to $tab for
 * "This page" and "Browse", link actions for every article, section, crumb
 * and related entry, Sections for tree groups and the table of contents, and
 * Html for the rendered article body, which lin-codex's stylesheet keeps
 * styling (callouts, steps and figures are the core's Markdown syntax). A
 * host that wants a different shell extends this class and overrides one of
 * the three schemas, the way it overrides the article resource's form.
 *
 * The view (fin-codex::livewire.help-drawer) keeps only what is layout or
 * what the glue queries: the root and its data attributes, the overlay, the
 * panel, the scrolling body (.codex-drawer__body) and the lightbox.
 *
 * $tab is the tab strip's own state, 'page' or 'tree', kept in step with the
 * core's $view on every render; a click on a tab lands in updatedTab(), which
 * goes through the core's goTo() so history and the first-article rule stay
 * the core's. The view data is assembled once per request (data()), because
 * the core's search is rate-limited and must not run twice for one render.
 *
 * Registered as `fin-codex.help-drawer` in the service provider; the panel
 * mount (panel/drawer.blade.php) renders this tag instead of the core's.
 */
class HelpDrawer extends CoreHelpDrawer implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public string $tab = 'page';

    /**
     * The view data of this request, keyed by the state it was built from.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $viewDataMemo = [];

    protected function viewName(): string
    {
        return 'fin-codex::livewire.help-drawer';
    }

    public function updatedTab(string $value): void
    {
        $this->goTo($value === 'tree' ? 'tree' : 'page');
    }

    public function render(): View
    {
        $this->tab = $this->view === 'tree' ? 'tree' : 'page';

        // Filament caches each schema for the request, and content() is
        // built while a field update or a tab change is handled — before the
        // core's hook has moved the view on. Rebuild at render, so the
        // schemas show the state the view shows.
        $this->cachedSchemas = [];

        return view($this->viewName(), $this->data());
    }

    public function header(Schema $schema): Schema
    {
        return $schema->components([
            Flex::make([
                Actions::make([
                    Action::make('back')
                        ->iconButton()
                        ->icon(Heroicon::OutlinedArrowLeft)
                        ->color('gray')
                        ->label(__('lin-codex::lin-codex.ui.back'))
                        ->extraAttributes(['data-fin-codex-drawer-back' => 'true'])
                        ->action(fn () => $this->back()),
                ])->grow(false)->hidden($this->history === []),
                Text::make((string) $this->data()['title'])
                    ->weight(FontWeight::SemiBold)
                    ->size(TextSize::Medium),
                Actions::make([
                    Action::make('close')
                        ->iconButton()
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('gray')
                        ->label(__('lin-codex::lin-codex.ui.close'))
                        ->extraAttributes(['data-fin-codex-drawer-close' => 'true'])
                        ->action(fn () => $this->close()),
                ])->grow(false),
            ])->verticallyAlignCenter(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->data();

        return $schema->components([
            TextInput::make('query')
                ->hiddenLabel()
                ->type('search')
                ->placeholder(__('lin-codex::lin-codex.ui.search_placeholder'))
                ->prefixIcon(Heroicon::OutlinedMagnifyingGlass)
                ->autocomplete(false)
                ->live(debounce: 300)
                ->extraInputAttributes([
                    'data-codex-focus' => 'true',
                    'aria-label' => __('lin-codex::lin-codex.ui.search'),
                ]),
            Tabs::make('tabs')
                ->livewireProperty('tab')
                ->contained(false)
                ->tabs([
                    'page' => Tab::make(__('lin-codex::lin-codex.ui.this_page'))
                        ->extraAttributes(['data-fin-codex-drawer-tab' => 'page'])
                        ->schema($this->pageComponents($data)),
                    'tree' => Tab::make(__('lin-codex::lin-codex.ui.browse'))
                        ->extraAttributes(['data-fin-codex-drawer-tab' => 'tree'])
                        ->schema($this->treeComponents($data['nodes'], $this->slug)),
                ]),
        ]);
    }

    public function footer(Schema $schema): Schema
    {
        $shortcut = $this->data()['options']['shortcut'] ?? null;

        return $schema->components([
            Flex::make([
                Actions::make([
                    Action::make('help-center')
                        ->link()
                        ->size('sm')
                        ->label(__('lin-codex::lin-codex.ui.open_help_center'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->iconPosition(IconPosition::After)
                        ->extraAttributes(['data-fin-codex-drawer-help-center' => 'true'])
                        ->url((string) $this->data()['helpCenterUrl']),
                ])->grow(false),
                Text::make($shortcut === null ? '' : (string) __('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => $shortcut]))
                    ->size(TextSize::ExtraSmall)
                    ->color('gray')
                    ->hidden($shortcut === null)
                    ->grow(false),
            ])->alignment(Alignment::Between)->verticallyAlignCenter(),
        ]);
    }

    /**
     * The view data, assembled once per state per request. Filament builds
     * the content schema while it handles a field update, before the core's
     * updatedQuery() hook has moved the view to "search", so a memo taken
     * then would be stale by render time; keying it on the view, the slug
     * and the query keeps every state's data built once — the core's search
     * is rate-limited and must not run twice for one render.
     *
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $key = implode('|', [$this->view, $this->slug ?? '', trim($this->query)]);

        return $this->viewDataMemo[$key] ??= $this->viewData();
    }

    /**
     * What the "This page" tab holds: the search result while searching, the
     * article while reading, the page's articles otherwise — and the whole
     * tree when the page has none.
     *
     * @param  array<string, mixed>  $data
     *
     * @return list<Component>
     */
    private function pageComponents(array $data): array
    {
        if ($this->view === 'search') {
            return $data['result'] instanceof SearchResult ? $this->searchComponents($data['result']) : [];
        }

        if ($this->view === 'article') {
            return $this->articleComponents($data);
        }

        if ($this->pageArticles === []) {
            return [
                Text::make(__('lin-codex::lin-codex.ui.no_help_for_page'))->color('gray'),
                ...$this->treeComponents($data['nodes'], null),
            ];
        }

        return $this->articleList($this->pageArticles);
    }

    /**
     * One link action per article, with its excerpt beneath.
     *
     * @param  list<array{slug: string, title: string, excerpt: ?string, isFallback: bool}>  $entries
     *
     * @return list<Component>
     */
    private function articleList(array $entries): array
    {
        return array_map(fn (array $entry): Group => Group::make([
            Actions::make([
                $this->openAction($entry['slug'], $entry['title'])
                    ->extraAttributes(['data-codex-page-article' => $entry['slug']]),
            ]),
            Text::make((string) $entry['excerpt'])
                ->size(TextSize::Small)
                ->color('gray')
                ->hidden(blank($entry['excerpt'])),
        ]), $entries);
    }

    /**
     * The article: crumbs, the "also on this page" links, the fallback notice,
     * the table of contents, the rendered body and the related articles. The
     * body keeps .codex-article__body, which the glue scrolls headings inside
     * and the core stylesheet styles.
     *
     * @param  array<string, mixed>  $data
     *
     * @return list<Component>
     */
    private function articleComponents(array $data): array
    {
        $read = $data['read'];

        if (! $read instanceof ReadArticle) {
            return [Text::make(__('lin-codex::lin-codex.ui.not_found'))->color('gray')];
        }

        $components = [];

        if ($read->breadcrumbs !== []) {
            $components[] = Actions::make(array_map(
                fn (array $crumb): Action => $this->openAction($crumb['slug'], $crumb['title'])->size('sm')->color('gray'),
                $read->breadcrumbs,
            ));
        }

        /** @var list<array{slug: string, title: string, excerpt: ?string, isFallback: bool}> $also */
        $also = array_values(array_filter($data['also'], fn (array $entry): bool => $entry['slug'] !== $this->slug));

        if ($also !== []) {
            $components[] = Section::make(__('lin-codex::lin-codex.ui.also_on_this_page'))
                ->compact()
                ->schema([Actions::make(array_map(
                    fn (array $entry): Action => $this->openAction($entry['slug'], $entry['title'])->extraAttributes(['data-codex-page-article' => $entry['slug']]),
                    $also,
                ))]);
        }

        if (is_string($data['fallbackNotice'])) {
            $components[] = Text::make($data['fallbackNotice'])->color('warning')->size(TextSize::Small);
        }

        if ($read->rendered->toc !== []) {
            $components[] = Section::make(__('lin-codex::lin-codex.ui.on_this_page'))
                ->compact()
                ->collapsible()
                ->collapsed(count($read->rendered->toc) < 3)
                ->schema([UnorderedList::make(array_map(
                    static fn (array $entry): Text => Text::make(new HtmlString('<a href="#'.e($entry['id']).'">'.e($entry['text']).'</a>'))->size(TextSize::Small),
                    $read->rendered->toc,
                ))]);
        }

        $components[] = Html::make(new HtmlString('<div class="codex-article__body" lang="'.e($read->locale).'">'.$read->rendered->html.'</div>'));

        if ($read->related !== []) {
            $components[] = Section::make(__('lin-codex::lin-codex.ui.related'))
                ->compact()
                ->schema([Actions::make(array_map(
                    fn (array $entry): Action => $this->openAction($entry['slug'], $entry['title']),
                    $read->related,
                ))]);
        }

        return $components;
    }

    /**
     * @return list<Component>
     */
    private function searchComponents(SearchResult $result): array
    {
        if ($result->rateLimited) {
            return [Text::make(__('lin-codex::lin-codex.ui.rate_limited', ['seconds' => $result->retryAfterSeconds]))->color('warning')];
        }

        if ($result->hits === []) {
            return [Text::make(__('lin-codex::lin-codex.ui.no_results'))->color('gray')];
        }

        return array_map(fn (SearchHit $hit): Group => Group::make([
            Actions::make([
                $this->openAction($hit->slug, $hit->title)->extraAttributes(['data-codex-hit' => $hit->slug]),
            ]),
            Text::make(implode(' › ', $hit->sectionPath))
                ->size(TextSize::ExtraSmall)
                ->color('gray')
                ->hidden($hit->sectionPath === []),
            // The snippet is SnippetBuilder's: everything escaped, <mark> only.
            Text::make(new HtmlString($hit->snippet))->size(TextSize::Small),
        ]), $result->hits);
    }

    /**
     * The tree: a collapsible Section per group, a link action per article
     * with its own children nested beneath it, the current article in the
     * primary colour.
     *
     * @param  list<TreeNode>  $nodes
     *
     * @return list<Component>
     */
    private function treeComponents(array $nodes, ?string $current): array
    {
        $components = [];

        foreach ($nodes as $node) {
            if ($node->isGroup()) {
                $components[] = Section::make($node->label)
                    ->compact()
                    ->collapsible()
                    ->extraAttributes(['data-codex-tree-node' => $node->slug])
                    ->schema($this->treeComponents($node->children, $current));

                continue;
            }

            $link = Actions::make([
                $this->openAction($node->slug, $node->label)
                    ->color($node->slug === $current ? 'primary' : 'gray')
                    ->extraAttributes(['data-codex-tree-node' => $node->slug]),
            ]);

            $components[] = $node->children === []
                ? $link
                : Group::make([
                    $link,
                    Group::make($this->treeComponents($node->children, $current))
                        ->extraAttributes(['class' => 'fin-codex-drawer__children']),
                ]);
        }

        return $components;
    }

    /**
     * A link that shows one article in the drawer. Named after the slug, so
     * two links to one article in one schema share a name and Filament keeps
     * them apart by their schema component.
     */
    private function openAction(string $slug, string $label): Action
    {
        return Action::make('open-'.Str::slug(str_replace('/', '-', $slug)))
            ->link()
            ->label($label)
            ->action(fn () => $this->show($slug));
    }
}
