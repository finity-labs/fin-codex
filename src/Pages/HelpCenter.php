<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Pages;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinSupport\Pages\Concerns\HasPageShieldSupport;
use FinityLabs\LinCodex\Data\TreeNode;
use FinityLabs\LinCodex\Livewire\Concerns\CapturesPageHelp;
use FinityLabs\LinCodex\Livewire\Concerns\SearchesArticles;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Reading\ArticleReader;
use FinityLabs\LinCodex\Reading\ReadArticle;
use FinityLabs\LinCodex\Reading\TreeBuilder;
use FinityLabs\LinCodex\Search\SearchHit;
use FinityLabs\LinCodex\Search\SearchResult;
use FinityLabs\LinCodex\View\PageHelpResolver;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The Help Center: the whole help library inside the panel, at {panel}/help and
 * {panel}/help/{slug}, behind the panel's own auth and guard.
 *
 * ONE page class on ONE route. The slug is a single optional wildcard
 * parameter, because article slugs are path-like (account/signing-in), so
 * {panel}/help, {panel}/help/intro and {panel}/help/account/signing-in are all
 * this class. Filament builds a page's route from getRoutePath() and exposes no
 * hook for the route's own where(), so the parameter's pattern is declared
 * globally by the service provider — see SLUG_PARAMETER below.
 *
 * There is no Blade view and no custom layout: content() returns the whole
 * three-column page out of schema components and the framework's own page view
 * renders it, exactly as HelpCoverage does. A rail on the left (the contents
 * tree and the search), the article in the middle, "On this page" on the right.
 *
 * Registered on EVERY panel that carries the plugin, authoring(false)
 * included: that flag means "this panel only reads help", and this page is the
 * reading surface the button, the drawer and the field hints point at.
 *
 * Whether it carries a navigation item follows the panel's placement option,
 * and its group, sort, label and icon come from four options of their own —
 * never from navigationGroup() / navigationSort(), which stay with the three
 * authoring screens. Declaring shouldRegisterNavigation() here discards the
 * Shield trait's version, so the canAccess() conjunct is re-stated rather than
 * inherited. Under the None placement the page is still registered and
 * {panel}/help still answers: only the two menu entries go, and the drawer,
 * the field hints and a bookmark all still reach it.
 *
 * Not final: a helpCenterPage() override extends it, exactly as HelpSettings
 * and HelpCoverage are open for their own options.
 *
 * Access goes through HasPageShieldSupport, like the other two pages: Shield's
 * own permission when Shield is installed, the opt-in Gate ability
 * page_HelpCenter when it is not, open to any panel user otherwise.
 */
class HelpCenter extends Page
{
    use CapturesPageHelp;
    use HasPageShieldSupport;
    use SearchesArticles;

    protected static ?string $slug = 'help';

    /**
     * The route parameter name, distinctive on purpose: the pattern that lets
     * it hold slashes is registered globally on the router, so the name has to
     * be one no host route would pick by accident.
     */
    public const SLUG_PARAMETER = 'codexSlug';

    /**
     * The panel filter's value for "every panel at once", distinct from a panel
     * id and from null: null is the state of a page nobody has filtered, and a
     * panel id names one panel, so the third answer needs a value of its own.
     */
    public const ALL_PANELS = '__all';

    /** Three columns need the room; the article text is not capped to a reading measure. */
    protected Width|string|null $maxContentWidth = Width::Full;

    /** The article being read, or null on the landing. Comes from the route. */
    public ?string $codexSlug = null;

    /** The rail's tab strip, bound through Tabs::livewireProperty(). */
    public string $tab = 'contents';

    /** The panel scope filter. Null means "the normal rule". */
    public ?string $panelFilter = null;

    /** Whether read() has been asked yet; the answer itself may legitimately be null. */
    private bool $readResolved = false;

    private ?ReadArticle $readMemo = null;

    /** @var list<TreeNode>|null */
    private ?array $treeMemo = null;

    /**
     * One search result per query, for this request only.
     *
     * @var array<string, SearchResult>
     */
    private array $searchMemo = [];

    /**
     * The landing and every article on one route.
     *
     * Only getRoutePath() is overridden. getRelativeRouteName() reads
     * getSlug(), not this, so the route name stays filament.{panel}.pages.help;
     * and routes() is deliberately left alone — its signature changed inside
     * the Filament 4 line and this package supports both majors, so an override
     * could not be written once for the whole supported range.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.static::getSlug($panel).'/{'.self::SLUG_PARAMETER.'?}';
    }

    /**
     * The navigation half of the panel's placement option.
     *
     * canAccess() is spelled out rather than inherited: a method declared on
     * the class always wins over the one the Shield trait ships, and parent::
     * from here resolves to Filament's own page, not to the trait, so
     * re-stating the conjunct is the only way to keep the Shield gate.
     *
     * FinCodexPlugin::get() bare, no try/catch, which is what every other
     * navigation static in this package does: they are only ever called inside
     * a panel render, where the plugin resolves.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return FinCodexPlugin::get()->getHelpCenterPlacement()->inNavigation()
            && static::canAccess()
            && parent::shouldRegisterNavigation();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FinCodexPlugin::get()->getHelpCenterNavigationGroup();
    }

    /**
     * The Help Center's own sort, never the three authoring screens' shared
     * one: it is the reading surface, not a fourth admin screen, so it does
     * not join the arithmetic those three chain off the panel's navigationSort.
     */
    public static function getNavigationSort(): ?int
    {
        return FinCodexPlugin::get()->getHelpCenterNavigationSort();
    }

    public static function getNavigationLabel(): string
    {
        return FinCodexPlugin::get()->getHelpCenterNavigationLabel();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return FinCodexPlugin::get()->getHelpCenterNavigationIcon();
    }

    public function mount(PageHelpResolver $resolver, ?string $codexSlug = null): void
    {
        $panel = Filament::getCurrentPanel();

        $this->capturePageHelp($resolver, static::class, $panel?->getId(), null, $panel?->getAuthGuard());
        $this->codexSlug = blank($codexSlug) ? null : $codexSlug;

        // The filter starts where the reader is standing, and it is reset on
        // every visit rather than persisted: a viewer who reads every panel must
        // never be quietly looking at another panel's help a week later.
        $this->panelFilter = $panel?->getId();
    }

    /** The browser tab follows the article, so open tabs and bookmarks stay legible. */
    public function getTitle(): string|Htmlable
    {
        return $this->read()?->translation->title ?? (string) __('fin-codex::fin-codex.help_center.title');
    }

    /**
     * Always "Help": the page looks like every other panel page, and the
     * article's own title lives in the article column, which is a different
     * level rather than a repeat. BasePage::getHeading() defaults to
     * getTitle(), so both have to be declared to get the two apart.
     */
    public function getHeading(): string|Htmlable|null
    {
        return (string) __('fin-codex::fin-codex.help_center.title');
    }

    /**
     * Typing is the only way to reach the search, and clearing the box is the
     * only way back: the reader never hunts for a tab.
     *
     * There is deliberately no tab persistence beside this. Tabs bound to a
     * Livewire property render through a branch of their own that never reads
     * the persistence flag, so asking for it would be a silent no-op — and it
     * would be pointless anyway, because the tab is derived from the query and
     * the page re-mounts on every navigation.
     */
    public function updatedQuery(): void
    {
        $this->tab = $this->hasSearchQuery() ? 'search' : 'contents';
    }

    /**
     * Filament caches each schema for the request, and content() is built while
     * the query field's update is still being handled — before updatedQuery()
     * has moved the tab — so a schema built then would show the tab the reader
     * just left. Dropping the cache here rebuilds it against the state the
     * render is about to show; the drawer clears the same cache for the same
     * reason.
     */
    public function render(): View
    {
        $this->cachedSchemas = [];

        return parent::render();
    }

    /**
     * The whole page, out of schema components.
     *
     * The headings column is built first and the same list decides whether the
     * column renders at all, so the three empty cases — no article, an article
     * without headings, and a slug that found nothing — all hide the column
     * rather than leaving an empty box in the grid.
     *
     * The three large-screen spans are hand-tuned against a real application,
     * not an even split: the article gets the room and "On this page" gets the
     * little it needs. They still add up to the grid's twelve, and a test row
     * pins all three so a later layout edit cannot undo them in silence.
     */
    public function content(Schema $schema): Schema
    {
        $headings = $this->headingsComponents();

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 12])->schema([
                Group::make($this->railComponents())
                    ->columnSpan(['default' => 1, 'lg' => 3])
                    ->extraAttributes(['class' => 'fin-codex-help__rail']),
                Group::make($this->articleComponents())
                    ->columnSpan(['default' => 1, 'lg' => 7])
                    ->extraAttributes(['class' => 'fin-codex-help__article']),
                Group::make($headings)
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->extraAttributes(['class' => 'fin-codex-help__toc'])
                    ->hidden($headings === []),
            ]),
        ]);
    }

    /**
     * The left rail: one collapsible section holding the search box and the
     * Contents/Search tab strip.
     *
     * The whole rail is one Section so a narrow screen can fold it away and put
     * the article first, and it persists that choice: at this width the reader
     * arranges the rail once rather than on every article.
     *
     * The search field sits ABOVE the strip and outside both tabs, so it is
     * never behind a tab the reader has to find first — typing is what moves
     * them to the results.
     *
     * @return list<Component>
     */
    private function railComponents(): array
    {
        return [
            Section::make(__('fin-codex::fin-codex.help_center.rail_heading'))
                ->id('fin-codex-help-rail')
                ->compact()
                ->collapsible()
                ->persistCollapsed()
                ->extraAttributes(['data-fin-codex-help-rail' => 'true'])
                ->schema([
                    // At the top of the rail, so it reads as the scope
                    // everything below it runs in. A select rather than a row of
                    // buttons, because a host may carry more panels than a row
                    // can hold.
                    Select::make('panelFilter')
                        ->label(__('fin-codex::fin-codex.help_center.panel_filter'))
                        ->options($this->panelFilterOptions())
                        ->selectablePlaceholder(false)
                        ->native(false)
                        ->live()
                        ->extraAttributes(['data-fin-codex-help-panel-filter' => 'true'])
                        ->hidden(! $this->showsPanelFilter()),
                    TextInput::make('query')
                        ->hiddenLabel()
                        ->type('search')
                        ->placeholder(__('lin-codex::lin-codex.ui.search_placeholder'))
                        ->prefixIcon(Heroicon::OutlinedMagnifyingGlass)
                        ->autocomplete(false)
                        ->live(debounce: 300)
                        ->extraInputAttributes([
                            'aria-label' => __('lin-codex::lin-codex.ui.search'),
                            'data-fin-codex-help-search' => 'true',
                            'x-on:input' => "sessionStorage.setItem('fin-codex-help-q', \$event.target.value)",
                        ]),
                    $this->queryRestoreComponent(),
                    Tabs::make('tabs')
                        ->livewireProperty('tab')
                        ->contained(false)
                        ->tabs([
                            'contents' => Tab::make(__('fin-codex::fin-codex.help_center.contents'))
                                ->extraAttributes(['data-fin-codex-help-tab' => 'contents'])
                                ->schema([
                                    ...$this->treeComponents($this->tree(), 0),
                                    ...$this->treeArrivalComponents(),
                                ]),
                            'search' => Tab::make(__('lin-codex::lin-codex.ui.search'))
                                ->extraAttributes(['data-fin-codex-help-tab' => 'search'])
                                ->schema($this->searchComponents()),
                        ]),
                ]),
        ];
    }

    /**
     * Whether this viewer gets the panel filter at all: only one a host policy
     * lets read every panel's help.
     *
     * Nobody else is even told the option exists, because for anybody else the
     * filter could only ever show them less than they already see — the panel
     * rule is the scope they are in, not a view they may change.
     *
     * The user is passed explicitly. The two-argument form of the ability asks
     * the Gate's default guard resolver, which is not the guard that answered for
     * a panel like the staff fixture; the panel scope gate passes the user for
     * exactly the same reason.
     */
    protected function showsPanelFilter(): bool
    {
        $user = $this->viewer()->user;

        return $user !== null && ArticleAbility::allows('viewAllPanels', Article::class, $user);
    }

    /**
     * Run the callback as a reader standing in the panel the filter names.
     *
     * Only the tree and the search go through here, and the article already open
     * deliberately does not: the viewer holds the grant and may read it anyway,
     * so the filter must not yank a page out from under them.
     *
     * The answer comes from the panel scope gate itself rather than from a second
     * copy of the rule beside it, so what the filter shows and what that panel
     * really shows cannot drift apart. Null and "every panel at once" ask nothing
     * of it: the normal rule is already the answer for both.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     *
     * @return TReturn
     */
    private function scoped(Closure $callback): mixed
    {
        $filter = $this->panelFilter;

        if ($filter === null || $filter === self::ALL_PANELS) {
            return $callback();
        }

        return app(PanelScopeGate::class)->preview($filter, $callback);
    }

    /**
     * The filter's options: every panel the coverage report knows, its outside
     * bucket when it has one, and this page's own "every panel at once".
     *
     * The panel list comes from the coverage report rather than from the panel
     * registry, so the filter and the coverage page share one answer about which
     * panel a screen files under and the two screens cannot disagree. The panel
     * ids come back raw and untranslated, which is deliberate and shared: a
     * nicer label belongs in panelOptions(), where both screens would move
     * together.
     *
     * @return array<string, string>
     */
    protected function panelFilterOptions(): array
    {
        return app(CoverageReport::class)->panelOptions()
            + [self::ALL_PANELS => (string) __('fin-codex::fin-codex.help_center.all_panels')];
    }

    /**
     * Puts the query back after a hit has been opened and left.
     *
     * Every link in the rail is a real anchor, so opening a hit re-mounts the
     * page and the typed query would otherwise be gone. Kept in sessionStorage
     * for the visit only: wire:navigate stays in the same browser tab, so the
     * value survives the re-mount, and closing the tab drops it — the query is
     * never persisted beyond the visit.
     *
     * wire:ignore keeps Livewire from morphing the element, so x-init runs once
     * per mount rather than on every update.
     */
    private function queryRestoreComponent(): Html
    {
        return Html::make(new HtmlString(
            '<div wire:ignore x-data x-init="const q = sessionStorage.getItem(\'fin-codex-help-q\');'
            .' if (q) { $wire.set(\'query\', q) }" hidden></div>',
        ));
    }

    /**
     * The Contents tab: the whole tree the viewer may read. A node that has
     * children is a collapsible section, whether it is a folder group or an
     * article — an article-rooted section keeps its own link as the heading, so
     * the label opens the article and the chevron beside it works the children.
     * A node with no children is the link alone.
     *
     * The entry for the article being read is primary where every other entry
     * is gray, and it alone says it is the current page. Colour on its own is
     * not a marker: an entry that looks like its siblings tells the reader
     * nothing, which is what the v0.5 audit found here.
     *
     * The root article appears once. The heading link is the only way into it,
     * so nothing repeats it among its children.
     *
     * The depth only picks the FIRST-VISIT open state: the top level comes up
     * expanded so real article titles are there to read at once, and anything
     * deeper comes up closed so a big library does not arrive as a wall. On
     * every later visit the browser's own remembered state wins — see
     * sectionId() and treeArrivalComponents().
     *
     * @param  list<TreeNode>  $nodes
     *
     * @return list<Component>
     */
    private function treeComponents(array $nodes, int $depth): array
    {
        $components = [];

        foreach ($nodes as $node) {
            if ($node->isGroup()) {
                $components[] = Section::make($node->label)
                    ->id($this->sectionId($node->slug))
                    ->compact()
                    ->collapsible()
                    ->persistCollapsed()
                    ->collapsed($depth > 0)
                    ->extraAttributes(['data-fin-codex-help-node' => $node->slug])
                    ->schema($this->treeComponents($node->children, $depth + 1));

                continue;
            }

            // merge: true is required both times — linkTo() already carries the
            // node marker in its own attribute bag, and a second bare call
            // would replace it rather than add to it.
            $link = $this->linkTo($node->slug, $node->label)
                ->color($node->slug === $this->codexSlug ? 'primary' : 'gray');

            if ($node->slug === $this->codexSlug) {
                $link = $link->extraAttributes([
                    'aria-current' => 'page',
                    'data-fin-codex-help-active' => 'true',
                ], merge: true);
            }

            if ($node->children === []) {
                $components[] = Actions::make([$link]);

                continue;
            }

            // A Filament Action is Htmlable, so the whole link renders inside
            // the section's heading. The guard is what keeps a click on the
            // label from flipping the section as well as opening the article:
            // Filament's toggle listens on the element around the heading. An
            // empty string, never true — a true value renders as its own
            // attribute name, which Alpine would try to evaluate.
            $link = $link->extraAttributes(['x-on:click.stop' => ''], merge: true);

            $components[] = Section::make($link)
                // Not optional. Filament's own key closure would put the heading
                // through a string-typed helper, and an Action cannot be cast to
                // one; setting the key replaces the closure so it never runs.
                ->key($this->sectionId($node->slug).'::section')
                ->id($this->sectionId($node->slug))
                ->compact()
                ->collapsible()
                ->persistCollapsed()
                ->collapsed($depth > 0)
                ->extraAttributes(['data-fin-codex-help-node' => $node->slug])
                ->schema($this->treeComponents($node->children, $depth + 1));
        }

        return $components;
    }

    /**
     * The last thing in the Contents tab: open the ancestors of the article the
     * reader arrived at, and put its entry on screen.
     *
     * Both halves have to run after the tree, because Alpine initialises in
     * document order — a dispatcher placed before the sections would fire into
     * the void, and a query for the active entry would find nothing.
     *
     * The force-open is Filament's own escape hatch rather than a server-side
     * collapsed(false), which could not win: with persistCollapsed() the
     * rendered value is only $persist's initial, so on any browser that has been
     * here before the stored value takes over. Every collapsible Section already
     * listens for an expand-section window event carrying its id.
     *
     * The side effect is deliberate: that listener assigns to the persisted
     * value, so an ancestor forced open here stays remembered as open. The
     * ancestors of where the reader is get opened; every other group keeps
     * exactly what the reader left it as.
     *
     * @return list<Component>
     */
    private function treeArrivalComponents(): array
    {
        $ancestors = $this->ancestorSectionIds();

        if ($ancestors === []) {
            return [];
        }

        return [
            Html::make(new HtmlString(
                '<div data-fin-codex-help-expand="'.e(implode(' ', $ancestors)).'" x-data x-init="$nextTick(() => { '
                .Js::from($ancestors).'.forEach(id => window.dispatchEvent(new CustomEvent(\'expand-section\', { detail: { id } })));'
                .' document.querySelector(\'[data-fin-codex-help-active]\')?.scrollIntoView({ block: \'nearest\' }); })" hidden></div>',
            )),
        ];
    }

    /**
     * The section ids of the current article's ancestors, outermost first.
     *
     * Every ancestor that has children qualifies, because every one of them is
     * a collapsible section now — an article with children included. Anything
     * below the top level comes up closed on a first visit, so without this an
     * article nested under an article would arrive with its own entry hidden.
     *
     * Empty on the landing, and empty for an article with no ancestors at all,
     * in which case nothing is rendered rather than an inert block.
     *
     * @return list<string>
     */
    private function ancestorSectionIds(): array
    {
        if (blank($this->codexSlug)) {
            return [];
        }

        $sections = $this->sectionSlugs($this->tree());
        $segments = explode('/', $this->codexSlug);
        array_pop($segments);

        $ids = [];
        $path = '';

        foreach ($segments as $segment) {
            $path = $path === '' ? $segment : $path.'/'.$segment;

            if (in_array($path, $sections, true)) {
                $ids[] = $this->sectionId($path);
            }
        }

        return $ids;
    }

    /**
     * Every node in the tree that is drawn as a collapsible section, at any
     * depth: anything with children, folder group or article alike.
     *
     * @param  list<TreeNode>  $nodes
     *
     * @return list<string>
     */
    private function sectionSlugs(array $nodes): array
    {
        $slugs = [];

        foreach ($nodes as $node) {
            if ($node->children !== []) {
                $slugs[] = $node->slug;
            }

            $slugs = [...$slugs, ...$this->sectionSlugs($node->children)];
        }

        return $slugs;
    }

    /**
     * The DOM id of one tree group's section — and, at the same time, the key its
     * open state is remembered under: Section::id() feeds both, so an id that
     * moved between renders, between panels or across the re-mount every article
     * link causes would silently lose what the reader arranged.
     *
     * Derived from the node slug and nothing else, for exactly that reason, and
     * put through Str::slug() because Filament strips a handful of characters out
     * of a custom id and a stripped id would no longer match the key.
     */
    private function sectionId(string $slug): string
    {
        return 'fin-codex-help-'.Str::slug(str_replace('/', '-', $slug));
    }

    /**
     * The Search tab: the core's hits, its rate-limit line or its no-results
     * line.
     *
     * The hits stay in the rail rather than taking the middle column, so the
     * article a reader is on is still there beside them and a wrong guess costs
     * nothing. The rail is narrow and a hit carries three pieces of text, so the
     * block is kept generous: the title is the link, the section path is a small
     * grey line above the snippet, and the snippet reads at normal size.
     *
     * @return list<Component>
     */
    private function searchComponents(): array
    {
        $result = $this->searchResultMemo();

        if ($result === null) {
            return [];
        }

        if ($result->rateLimited) {
            return [Text::make(__('lin-codex::lin-codex.ui.rate_limited', ['seconds' => $result->retryAfterSeconds]))->color('warning')];
        }

        if ($result->hits === []) {
            return [Text::make(__('lin-codex::lin-codex.ui.no_results'))->color('gray')];
        }

        return array_map(fn (SearchHit $hit): Group => Group::make([
            Actions::make([
                $this->linkTo($hit->slug, $hit->title)
                    ->extraAttributes(['data-fin-codex-help-hit' => $hit->slug], merge: true),
            ]),
            Text::make(implode(' › ', $hit->sectionPath))
                ->size(TextSize::ExtraSmall)
                ->color('gray')
                ->hidden($hit->sectionPath === []),
            // SnippetBuilder's output: everything escaped already, with <mark>
            // around the matched prefixes and nothing else. Escaping it again
            // would show the reader the tag, and adding marks of our own would
            // disagree with what the JSON API and the drawer show for the same
            // search.
            Text::make(new HtmlString($hit->snippet))->size(TextSize::Small),
        ]), $result->hits);
    }

    /**
     * The current query's result, asked of the core at most once per render, or
     * null while there is nothing to search for.
     *
     * The memo is what keeps the limiter honest. Every Searcher::search() call
     * spends a token, and Filament builds the content schema once while it is
     * handling the query field's update and again at render — so two tokens a
     * keystroke, which turns a working search into the "too many searches" line
     * for exactly the fast typist the debounce is there to serve. Keyed on the
     * trimmed query, the way the drawer keys its own view data on its state.
     *
     * Fifty is asked for and the core clamps it to lin-codex.search.max_limit;
     * clamping it here as well would put the same rule in two places and make a
     * host's raised cap a lie.
     */
    protected function searchResultMemo(): ?SearchResult
    {
        if (! $this->hasSearchQuery()) {
            return null;
        }

        return $this->searchMemo[trim($this->query)] ??= $this->scoped(fn (): SearchResult => $this->searchResult(50));
    }

    /**
     * The middle column: the article, or one of the three states it can be in.
     *
     * The states are checked in this order and the order is the decision. A set
     * slug that finds nothing is a not-found even when nothing at all is
     * readable, because the reader asked for something specific; an empty tree
     * then wins over the landing, because "nothing has been written yet" is more
     * use than "pick a topic" when there are no topics.
     *
     * @return list<Component>
     */
    private function articleComponents(): array
    {
        $read = $this->read();

        if ($read === null) {
            return match (true) {
                $this->codexSlug !== null => $this->notFoundComponents(),
                $this->tree() === [] => $this->emptyComponents(),
                default => $this->landingComponents(),
            };
        }

        $components = [];

        if ($read->breadcrumbs !== []) {
            $components[] = Actions::make(array_map(
                fn (array $crumb): Action => $this->linkTo($crumb['slug'], $crumb['title'])->size('sm')->color('gray'),
                $read->breadcrumbs,
            ));
        }

        $components[] = Text::make($read->translation->title)
            ->weight(FontWeight::Bold)
            ->size(TextSize::Large);

        $notice = $this->fallbackNoticeFor($read);

        if (is_string($notice)) {
            // The drawer's treatment: a small warning-coloured line between the
            // title and the body, not a bordered callout that takes room from
            // the article.
            $components[] = Text::make($notice)->color('warning')->size(TextSize::Small);
        }

        $components[] = $this->bodyComponent($read);

        if ($read->related !== []) {
            // At the bottom, where a reader meets them after reading, rather
            // than competing with the headings rail for the right column.
            $components[] = Section::make(__('lin-codex::lin-codex.ui.related'))
                ->compact()
                ->schema([Actions::make(array_map(
                    fn (array $entry): Action => $this->linkTo($entry['slug'], $entry['title']),
                    $read->related,
                ))]);
        }

        return $components;
    }

    /**
     * The rendered body, its style scope and its lightbox, in one component.
     *
     * The codex-root wrapper is not optional. The core stylesheet defines every
     * token the body rules consume on .codex-root and .codex-help-button only,
     * while the body rules themselves are unscoped — and a Filament page carries
     * neither class anywhere. Without this wrapper callouts, steps, figures,
     * code blocks and tables render with no colours, borders or spacing, and the
     * panel's dark mode does nothing to them. fin-codex's own token remap in the
     * panel head view is keyed on the same two selectors.
     *
     * The lightbox is the core's markup, the core's classes and the renderer's
     * own marker attribute on every image, with a small inline Alpine object of
     * this page's own. The drawer's Alpine component cannot be reused: it is
     * built entirely around the drawer's open and close state and watches it as
     * it initialises, and its partial has to live inside a script block in a
     * component's own Blade view, which this page deliberately does not have.
     *
     * Downloads need nothing here: the core renderer already wrote them into the
     * body HTML.
     */
    private function bodyComponent(ReadArticle $read): Html
    {
        $closeLabel = e((string) __('lin-codex::lin-codex.ui.lightbox_close'));

        return Html::make(new HtmlString(
            '<div class="codex-root" x-data="{ lightbox: null, lightboxAlt: \'\' }"'
            .' x-on:click="const i = $event.target.closest(\'img[data-codex-lightbox]\'); if (i) { lightbox = i.currentSrc || i.src; lightboxAlt = i.alt || \'\' }"'
            .' x-on:keydown.escape.window="lightbox = null">'
            .'<div class="codex-article__body" lang="'.e($read->locale).'">'.$read->rendered->html.'</div>'
            .'<template x-if="lightbox !== null">'
            .'<div class="codex-lightbox" role="dialog" aria-label="'.$closeLabel.'" x-on:click="lightbox = null">'
            .'<img class="codex-lightbox__image" x-bind:src="lightbox" x-bind:alt="lightboxAlt" alt="">'
            .'<button type="button" class="codex-lightbox__close" aria-label="'.$closeLabel.'" x-on:click="lightbox = null"></button>'
            .'</div></template></div>',
        ));
    }

    /**
     * A slug that is missing, hidden, unpublished or belongs to another panel —
     * all of which look alike on purpose.
     *
     * The response stays 200: the page exists and works, only the requested
     * article is unavailable, and a 200 keeps the rail and the search usable
     * beside it while telling a probe nothing about whether the article exists.
     *
     * @return list<Component>
     */
    private function notFoundComponents(): array
    {
        return [Text::make(__('lin-codex::lin-codex.ui.not_found'))->color('gray')];
    }

    /**
     * Bare {panel}/help: the core's invitation and the top level of the tree.
     *
     * The rail carries the whole tree, but the middle is the largest area on
     * screen and one grey sentence wastes the first thing a reader sees. A node
     * that is a folder group has no article to open, so it is named rather than
     * linked.
     *
     * @return list<Component>
     */
    private function landingComponents(): array
    {
        $components = [Text::make(__('lin-codex::lin-codex.ui.pick_a_topic'))->color('gray')];

        foreach ($this->tree() as $node) {
            $components[] = $node->isGroup()
                ? Text::make($node->label)->weight(FontWeight::Medium)
                : Actions::make([$this->linkTo($node->slug, $node->label)]);
        }

        return $components;
    }

    /**
     * Nothing readable at all: a fresh install, or a viewer the scoping hides
     * everything from.
     *
     * The editor link is gated twice and both halves are needed. The ability is
     * the obvious one. The second is that the article resource is actually
     * registered on THIS panel: this page lives on every panel, while the
     * resource only goes on the panels that author, so building the create URL
     * on a reading-only panel would raise a missing-route error. It is a link
     * with a URL rather than a redirecting action, so nothing here touches
     * Livewire's back-button-cache flag.
     *
     * @return list<Component>
     */
    private function emptyComponents(): array
    {
        $components = [
            Text::make(__('fin-codex::fin-codex.help_center.empty'))
                ->weight(FontWeight::Bold)
                ->size(TextSize::Large),
            Text::make(__('fin-codex::fin-codex.help_center.empty_description'))->color('gray'),
        ];

        $resource = FinCodexPlugin::articleResourceClass();

        if (ArticleAbility::allows('create') && in_array($resource, Filament::getCurrentPanel()?->getResources() ?? [], true)) {
            $components[] = Actions::make([
                Action::make('write-first-article')
                    ->link()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->label(__('fin-codex::fin-codex.help_center.write_article'))
                    ->url($resource::getUrl('create')),
            ]);
        }

        return $components;
    }

    /**
     * The right column: "On this page", one entry per heading of the article.
     *
     * Plain anchors on purpose: the browser jumps and puts the heading id in the
     * hash for free, no JavaScript of ours, and a heading link can be copied
     * out. The table of contents is the renderer's own — second and third level
     * only, in document order, with the ids it already wrote into the body — so
     * nothing here parses the HTML for them.
     *
     * An empty list here is what hides the whole column: see content().
     *
     * The list is asked for a single column explicitly. Left alone it spreads
     * over two from the small breakpoint upwards, which reads as two ragged
     * stacks inside a column this narrow.
     *
     * @return list<Component>
     */
    private function headingsComponents(): array
    {
        $toc = $this->read()?->rendered->toc ?? [];

        if ($toc === []) {
            return [];
        }

        return [
            Section::make(__('lin-codex::lin-codex.ui.on_this_page'))
                ->compact()
                ->schema([
                    UnorderedList::make(array_map(
                        static fn (array $entry): Text => Text::make(new HtmlString('<a href="#'.e($entry['id']).'">'.e($entry['text']).'</a>'))->size(TextSize::Small),
                        $toc,
                    ))
                        ->columns(1),
                ]),
        ];
    }

    /**
     * The article being read, asked for once per request.
     *
     * Memoised because getTitle() and content() both want it and Filament may
     * build the content schema twice in one request. Deliberately NOT wrapped
     * in the panel scope filter: an article already open stays readable when
     * the filter moves to a panel that cannot see it.
     */
    protected function read(): ?ReadArticle
    {
        if (! $this->readResolved) {
            $this->readResolved = true;

            $this->readMemo = blank($this->codexSlug)
                ? null
                : app(ArticleReader::class)->read($this->codexSlug, $this->viewer(), $this->locale);
        }

        return $this->readMemo;
    }

    /**
     * Everything this viewer may read, as a tree, asked for once per request.
     *
     * @return list<TreeNode>
     */
    protected function tree(): array
    {
        return $this->treeMemo ??= $this->scoped(fn (): array => app(TreeBuilder::class)->build($this->viewer(), $this->locale));
    }

    /**
     * A link to another article: a real anchor with an href, never a Livewire
     * action, so the address bar is always right, the back button walks
     * articles and a reader can copy or open the link in a new tab.
     *
     * Named after the slug the way the drawer names its own, so two links to
     * one article in one schema do not collide.
     *
     * The colour is the caller's. Filament renders a link action with no colour
     * of its own in the primary one, so the tree sets gray on every entry but
     * the article being read — the contrast CENTER-02 asks for. The four other
     * callers here (search hits, breadcrumbs, related, the landing list) are
     * not a tree and keep what they have.
     */
    protected function linkTo(string $slug, string $label): Action
    {
        return Action::make('open-'.Str::slug(str_replace('/', '-', $slug)))
            ->link()
            ->label($label)
            ->url(static::getUrl([self::SLUG_PARAMETER => $slug]))
            ->extraAttributes(['data-fin-codex-help-node' => $slug]);
    }
}
