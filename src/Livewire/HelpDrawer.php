<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
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
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Livewire\Concerns\RendersHelpSchemas;
use FinityLabs\LinCodex\Livewire\HelpDrawer as CoreHelpDrawer;
use FinityLabs\LinCodex\Reading\ReadArticle;
use FinityLabs\LinCodex\Rendering\ArticlePath;
use FinityLabs\LinCodex\Search\SearchResult;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

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
    use RendersHelpSchemas;

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
                        ->schema($this->helpTreeComponents($data['nodes'], $this->slug)),
                ]),
        ]);
    }

    /**
     * The shortcut hint, and "Open help center" whenever the viewer can
     * actually walk through that door.
     *
     * The link carries the article the drawer has open, so a reader keeps
     * their place on the way to the page, and falls back to the center's
     * root only when there is no article to carry. It stays an absolute URL:
     * the relative form matches the panel's own SPA exception pattern, which
     * would quietly turn the footer into a full page load.
     *
     * Three reasons to withhold it, and the whole Actions group goes rather
     * than the Action alone, so no empty wrapper is left behind. The URL is
     * null when no help-center prefix could be computed — a tenanted panel
     * before its tenant is known — and an action with a null URL still
     * renders an anchor, with an empty href. A simple-layout page is a guest
     * page, and the Help Center now lives inside the panel, behind the very
     * login the guest is looking at. And a viewer the page's own
     * gate refuses is never offered the link, only to meet a 403 behind it.
     *
     * The page class comes from $this->page, the locked memo the core
     * captures at mount, not from the current route: a Livewire update
     * request carries no page, so a footer that asked the route would flip
     * its own visibility between the first render and the next update. The
     * gate is asked of the class the panel actually registered, because a
     * helpCenterPage() override may tighten access and the shipped class
     * would be the wrong gate to ask.
     */
    public function footer(Schema $schema): Schema
    {
        $shortcut = $this->data()['options']['shortcut'] ?? null;
        $url = $this->data()['helpCenterUrl'];
        $href = blank($this->slug) ? $url : url(ArticlePath::href($this->slug));
        $pageClass = $this->page['class'] ?? null;
        $isSimple = is_string($pageClass) && is_subclass_of($pageClass, SimplePage::class);
        $mayRead = FinCodexPlugin::helpCenterPageClass($this->page['panel'] ?? null)::canAccess();

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
                        ->url((string) $href),
                ])->grow(false)->hidden($url === null || $isSimple || ! $mayRead),
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
            return $data['result'] instanceof SearchResult ? $this->helpSearchComponents($data['result']) : [];
        }

        if ($this->view === 'article') {
            return $this->articleComponents($data);
        }

        if ($this->pageArticles === []) {
            return [
                Text::make(__('lin-codex::lin-codex.ui.no_help_for_page'))->color('gray'),
                ...$this->helpTreeComponents($data['nodes'], null),
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
                $this->helpLink($entry['slug'], $entry['title'])
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
            $components[] = $this->helpBreadcrumbs($read->breadcrumbs);
        }

        /** @var list<array{slug: string, title: string, excerpt: ?string, isFallback: bool}> $also */
        $also = array_values(array_filter($data['also'], fn (array $entry): bool => $entry['slug'] !== $this->slug));

        if ($also !== []) {
            $components[] = Section::make(__('lin-codex::lin-codex.ui.also_on_this_page'))
                ->compact()
                ->schema([Actions::make(array_map(
                    fn (array $entry): Action => $this->helpLink($entry['slug'], $entry['title'])->extraAttributes(['data-codex-page-article' => $entry['slug']]),
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
                ->schema([UnorderedList::make($this->helpHeadingEntries($read->rendered->toc))]);
        }

        $components[] = Html::make(new HtmlString('<div class="codex-article__body" lang="'.e($read->locale).'">'.$read->rendered->html.'</div>'));

        if ($read->related !== []) {
            $components[] = $this->helpRelatedSection($read->related);
        }

        return $components;
    }

    /**
     * A link that shows one article in the drawer. Named after the slug, so
     * two links to one article in one schema share a name and Filament keeps
     * them apart by their schema component.
     */
    protected function helpLink(string $slug, string $label): Action
    {
        return Action::make($this->helpActionName($slug))
            ->link()
            ->label($label)
            ->action(fn () => $this->show($slug));
    }

    /**
     * The drawer's own prefix, which must stay distinct from the Help Center
     * page's: this drawer is mounted on every panel page, that page included,
     * so a shared prefix would put two elements with one id on it, have the
     * two trees remember a single open state between them, and let the
     * page's arrival dispatcher unfold the drawer's sections behind the overlay.
     */
    protected function helpSectionIdPrefix(): string
    {
        return 'fin-codex-drawer-';
    }

    protected function helpNodeAttribute(): string
    {
        return 'data-codex-tree-node';
    }

    protected function helpHitAttribute(): string
    {
        return 'data-codex-hit';
    }
}
