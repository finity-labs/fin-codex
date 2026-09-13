<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\ViewManager;
use Filament\View\PanelsRenderHook;
use FinityLabs\FinCodex\Enums\HelpCenterPlacement;
use FinityLabs\FinCodex\Enums\NavigationGroup;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Panel\HelpMount;
use FinityLabs\FinCodex\Panel\RefreshHelpCenterPrefix;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinCodex\Search\HelpSearchProvider;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * Per-panel options for the Codex panel layer.
 *
 * Every option that can differ between two panels lives here as a fluent
 * method, never in config: the help button hook, the shortcut, the drawer
 * width, whether the button and the guest drawer render at all, whether the
 * panel manages help content or only reads it, global search, navigation
 * placement and the class overrides. Later code reads them through
 * filament('fin-codex').
 */
class FinCodexPlugin implements Plugin
{
    use EvaluatesClosures;

    protected string|Closure|null $helpButtonRenderHook = null;

    protected string|Closure|null $shortcut = 'ctrl+/';

    protected int|Closure $drawerWidth = 480;

    protected bool|Closure $helpButton = true;

    protected bool|Closure $guestDrawer = true;

    protected bool|Closure $authoring = true;

    protected bool|Closure $globalSearch = false;

    protected string|UnitEnum|Closure|null $navigationGroup = NavigationGroup::Help;

    protected int|Closure|null $navigationSort = null;

    protected HelpCenterPlacement|Closure $helpCenterPlacement = HelpCenterPlacement::UserMenu;

    protected string|UnitEnum|Closure|null $helpCenterNavigationGroup = null;

    protected int|Closure|null $helpCenterNavigationSort = 1000;

    protected string|Closure|null $helpCenterNavigationLabel = null;

    protected string|BackedEnum|Htmlable|Closure|null $helpCenterNavigationIcon = null;

    /** @var class-string|null */
    protected ?string $articleResource = null;

    /** @var class-string|null */
    protected ?string $settingsPage = null;

    /** @var class-string|null */
    protected ?string $coveragePage = null;

    /** @var class-string|null */
    protected ?string $helpCenterPage = null;

    protected string $policyNamespace = 'App\\Policies';

    /**
     * What the Media tab's upload accepts, as MIME types. Documents a help
     * article would link to; never an archive, a script or an SVG — the
     * body editor's own drop zone takes the five raster image types.
     *
     * @var list<string>
     */
    public const DEFAULT_DOCUMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv',
    ];

    /** @var list<string>|Closure */
    protected array|Closure $documentTypes = self::DEFAULT_DOCUMENT_TYPES;

    protected int|Closure $documentMaxSize = 10_240;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    public const ID = 'fin-codex';

    public function getId(): string
    {
        return self::ID;
    }

    /**
     * Hooks are registered on the panel, never app-wide through the facade: Panel::boot()
     * flushes them for the current panel only and before plugins boot, so they must be
     * added here and two panels never see each other's output. Hook names are fixed
     * here; panel state (dark mode, topbar, guard) is read lazily in HelpMount at
     * render time.
     *
     * Evaluating a Closure-valued helpButtonRenderHook here is fine: the plugin instance
     * is fully configured before ->plugin() runs and the closure must not read panel
     * state. The hasTopbar() decision cannot be made here (the host may chain
     * ->topbar(false) after ->plugin()), so the sidebar closure makes it at render time.
     */
    public function register(Panel $panel): void
    {
        $panel->renderHook(PanelsRenderHook::HEAD_END, fn (array $scopes = []): HtmlString => $this->mount()->head($panel));

        // USER_MENU_AFTER renders inside Filament's user menu component, which
        // sits in the fi-topbar-end group beside the notification bell on a
        // panel with a topbar and in the sidebar footer on one without, so
        // one hook covers both layouts. The two fallbacks only render on a
        // panel that has no user menu at all (->userMenu(false)): TOPBAR_END
        // with a topbar, SIDEBAR_FOOTER without. Every decision that depends
        // on panel state is made at render time, because the host may chain
        // ->topbar(false) or ->userMenu(false) after ->plugin(). The topbar
        // end group is x-persist-ed, so under SPA mode the badge keeps the
        // count of the page the button was first rendered on (the README says
        // so); a host that wants it live there names TOPBAR_END.
        if ($this->hasExplicitHelpButtonRenderHook()) {
            $panel->renderHook($this->getHelpButtonRenderHook(), fn (array $scopes = []): HtmlString => $this->mount()->button($this, $panel));
        } else {
            $panel->renderHook(PanelsRenderHook::USER_MENU_AFTER, fn (array $scopes = []): HtmlString => $this->mount()->button($this, $panel));
            $panel->renderHook(PanelsRenderHook::TOPBAR_END, fn (array $scopes = []): HtmlString => $panel->hasUserMenu() || ! $panel->hasTopbar() ? new HtmlString('') : $this->mount()->button($this, $panel));
            $panel->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (array $scopes = []): HtmlString => $panel->hasUserMenu() || $panel->hasTopbar() ? new HtmlString('') : $this->mount()->button($this, $panel));
        }

        $panel->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn (array $scopes = []): HtmlString => $this->mount()->guestLink($this, $panel));
        $panel->renderHook(PanelsRenderHook::BODY_END, fn (array $scopes = []): HtmlString => $this->mount()->drawer($this, $panel));

        // The Help Center goes on EVERY panel, ->authoring(false) included: that
        // flag means "this panel only reads help", and this page is the reading
        // surface the button, the drawer and the field hints point at. A
        // helpCenterPage() override must extend Pages\HelpCenter; the
        // enforcement lives on helpCenterPageClass(), as it does for the article
        // resource. Panel::pages() appends, so the authoring block's own call
        // below is unaffected.
        $panel->pages([$this->getHelpCenterPage() ?? HelpCenter::class]);

        // The user-menu half of the placement option, registered beside the
        // page and before the authoring early return for the same reason: the
        // page goes on every panel, so its entry must too.
        //
        // Here rather than in boot(), because userMenuItems() appends to a
        // Panel that outlives the request while boot() runs on every one of
        // them — registering there multiplies the entry on a long-lived
        // worker. Everything that can vary between two requests is therefore a
        // closure: the label follows the locale, the URL differs per tenant,
        // and the visibility is a genuine per-request decision that Filament
        // re-reads every time it collects the menu.
        //
        // The placement lives in the visibility closure, so one registration
        // serves all four states and Navigation and None simply answer false.
        // The gate is asked of the page class this panel actually registered,
        // never of the shipped one, because a helpCenterPage() override may
        // tighten access. And the sort is -1 rather than anything lower: at -2
        // the entry becomes the first item of the block Filament groups on a
        // negative sort, and since it carries a URL the dropdown then stops
        // treating the viewer's name as its header on every panel without a
        // profile page.
        //
        // Label and icon are the shipped ones, not the four helpCenterNavigation
        // options: those describe the sidebar item, and this entry is fixed by
        // design. A host who wants it worded or placed differently registers a
        // user-menu item of its own and moves the placement to Navigation or
        // None. A panel with no user menu at all renders nothing here whatever
        // the placement, and the page stays reachable — the promise None makes,
        // arrived at from the host's side.
        $panel->userMenuItems([
            Action::make('fin-codex-help-center')
                ->label(fn (): string => (string) __('fin-codex::fin-codex.help_center.navigation'))
                ->icon(Heroicon::OutlinedBookOpen)
                ->sort(-1)
                ->url(fn (): ?string => $this->helpCenterUrl($panel->getId()))
                ->visible(fn (): bool => $this->getHelpCenterPlacement()->inUserMenu()
                    && static::helpCenterPageClass($panel->getId())::canAccess()),
        ]);

        // A panel's tenant middleware is Filament's own IdentifyTenant followed
        // by this, and Filament applies the list only inside the tenant route
        // group, so a panel without tenancy never sees it. isPersistent puts it
        // beside IdentifyTenant in Livewire's own list, so a drawer update on a
        // tenanted panel keeps the tenant in its links. Registered here rather
        // than in boot(): register() runs once when the provider builds the
        // panel, boot() runs every request, and the middleware arrays live on a
        // Panel that outlives both.
        $panel->tenantMiddleware([RefreshHelpCenterPrefix::class], isPersistent: true);

        // A panel that only reads help registers none of the three admin screens:
        // ->authoring(false) keeps the button, the drawer, the hints and the help
        // center and leaves the editor, Help settings and Help coverage to the
        // panels that answer true. Evaluated here for the same reason the button
        // hook is — the plugin is fully configured before ->plugin() runs — so the
        // closure may read config but not panel state or the request user.
        if (! $this->hasAuthoring()) {
            return;
        }

        // Panel::resources() appends to the host's list, it never replaces it. An
        // articleResource() override must extend Resources\ArticleResource; navigation
        // group and sort are not decided here but read from this plugin by the resource
        // at navigation time, so each panel files the editor its own way.
        $panel->resources([$this->getArticleResource() ?? ArticleResource::class]);

        // Same rule for both package pages: Panel::pages() appends to the host's
        // list, a settingsPage() or coveragePage() override must extend the class
        // it replaces, and each page reads this plugin's navigation group and sort
        // at navigation time.
        $panel->pages([
            $this->getSettingsPage() ?? HelpSettings::class,
            $this->getCoveragePage() ?? HelpCoverage::class,
        ]);
    }

    /** Resolved per call so the scoped CurrentPage is the current request's. */
    private function mount(): HelpMount
    {
        return app(HelpMount::class);
    }

    /**
     * Panel state (guard, global search provider, topbar) is read here or lazily,
     * never in register(). Each concern is its own private method: the SPA
     * exception returns early on a panel without SPA mode, and appending the
     * global search block after that return would skip it on every non-SPA panel.
     *
     * The prefix write runs second and owns the SPA exception, which is built
     * from the value it writes and must therefore be applied after it.
     */
    public function boot(Panel $panel): void
    {
        $this->bootPolicy();
        $this->applyHelpCenterPrefix($panel);
        $this->bootGlobalSearch($panel);
        $this->bootPanelScope();
    }

    /**
     * This panel's help-center prefix, written where the core reads it.
     *
     * The core's link builders read lin-codex.routes.help_center at call time,
     * so this one write per request steers the drawer footer, the field hints,
     * the global search rows and every article-to-article link the renderer
     * writes — with no setter and no core change. Idempotent across repeated
     * boots for the same reason bootPanelScope() is: the value is derived, never
     * accumulated. It must stay that way — no early-return guard may be added
     * here, because the tenant refresh has to be free to overwrite what boot
     * wrote.
     *
     * On a panel with tenancy the page route carries a tenant segment that
     * nobody has filled in yet when plugins boot, so route generation throws,
     * the prefix is null and the write is skipped; Panel\RefreshHelpCenterPrefix
     * repeats the call once the tenant is known.
     */
    private function applyHelpCenterPrefix(Panel $panel): void
    {
        $prefix = $this->helpCenterPrefix($panel->getId());

        if ($prefix !== null) {
            config()->set('lin-codex.routes.help_center', $prefix);
        }

        $this->bootSpaExceptions($panel);
    }

    /**
     * Re-derive and re-apply the prefix for the panel that is current now.
     *
     * The one public way in, for the tenant middleware and for nothing else.
     */
    public function refreshHelpCenterPrefix(Panel $panel): void
    {
        $this->applyHelpCenterPrefix($panel);
    }

    /**
     * The panel's help-center path relative to the application root, or null
     * when no URL can be built (a panel without the page, or a tenanted panel
     * before its tenant is known).
     *
     * Relative on purpose. The core turns the prefix into an absolute URL with
     * url(), which re-prepends the application root, so an absolute prefix on a
     * host served from a subdirectory would carry that base path twice. The
     * core's contract is a prefix relative to the application root.
     */
    private function helpCenterPrefix(?string $panelId): ?string
    {
        try {
            return static::helpCenterPageClass($panelId)::getUrl([], false, $panelId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The panel scope: general articles plus this panel's own, everywhere the
     * core reads (Scope\PanelScopeGate). Installed as a class name so the
     * core resolves one container instance and repeated boots — Octane boots
     * the panel every request, a test may boot after an HTTP request — see
     * the class already in place and add nothing. A host hook configured
     * before us is kept as the inner hook and runs first. Set here rather
     * than in register(): register() runs for every panel at provider boot,
     * boot() for the panel Filament serves, which is the Phase 13 precedent
     * for lin-codex.routes.help_center. The gate reads the current panel at
     * call time, so a request outside every panel keeps the core's answer.
     */
    private function bootPanelScope(): void
    {
        $current = config('lin-codex.auth.gate');

        if ($current === PanelScopeGate::class) {
            return;
        }

        app(PanelScopeGate::class)->wrap($current);
        config()->set('lin-codex.auth.gate', PanelScopeGate::class);
    }

    /**
     * This panel's article policy. The provider registered one at boot with
     * the default panel's namespace; a panel that names its own re-registers
     * here, once per request, before any page of it renders. The Gate map is
     * a plain array keyed by model, so repeating the registration on every
     * request costs nothing and leaks nothing.
     */
    private function bootPolicy(): void
    {
        FinCodexServiceProvider::registerArticlePolicy($this->getPolicyNamespace());
    }

    /**
     * Filament adds wire:navigate to every same-app href, and Livewire's navigate
     * listener starts on mousedown without consulting defaultPrevented, so the
     * CodexHelp hint's Alpine intercept would lose the race and the click would
     * leave for the help center even with a drawer on the page. Panel::boot()
     * pushes the panel's own spaUrlExceptions() before plugins boot, and
     * ViewManager::spaUrlExceptions() appends, so adding the help-center pattern
     * here survives a host that chains ->spaUrlExceptions() after ->plugin().
     * hasSpaMode($pattern) is the guard: it turns false once the pattern is on
     * the list, so repeated boots within one process add nothing.
     *
     * The exception pattern and the core's link builder are both relative to the
     * application root and therefore match each other; a Filament page's own
     * getUrl() is absolute and therefore does not. The manager compares the raw
     * href string, so the URL FORM, not the destination, decides whether
     * Livewire's navigate listener is armed on a given link. That is what lets
     * the hint keep its drawer intercept while a Help Center tree link still
     * swaps the page in place. Move either side to the other form and the two
     * behaviours silently trade places.
     *
     * A blank prefix is the third state and the reason for the early return
     * below: it would leave a pattern that matches every application URL, which
     * excepts the whole panel from SPA navigation. That is the state a published
     * fin-codex config is in before the write above lands, and the state a
     * tenanted panel is in at boot.
     */
    private function bootSpaExceptions(Panel $panel): void
    {
        if (! $panel->hasSpaMode()) {
            return;
        }

        // No third argument: config() only falls back to a default when the key
        // is absent, and the core's published config names it with a null value.
        $prefix = rtrim((string) config('lin-codex.routes.help_center'), '/');

        if ($prefix === '') {
            return;
        }

        $pattern = $prefix.'/*';
        $view = app(ViewManager::class);

        if ($view->hasSpaMode($pattern)) {
            $view->spaUrlExceptions([$pattern]);
        }
    }

    /**
     * The Help category in the panel's own search field, for the panels whose
     * globalSearch() option is on. Note the collision: this plugin's
     * globalSearch(bool) is the per-panel opt-in read here, while Filament's
     * Panel::globalSearch(string|bool) sets the provider — they are unrelated.
     *
     * The wrapper is installed on the CONTAINER, not on the Panel.
     * Panel::globalSearch(Wrapper::class) would mutate a long-lived object (a
     * worker or Testbench keeps panels across requests) while the binding behind
     * it lives in a container that gets flushed, and app($provider) resolves with
     * no constructor arguments, so the wrapper could not receive the provider it
     * wraps. Container::extend() on the resolved concrete has neither problem, it
     * is the house pattern already (ContentSource is extended the same way in
     * FinCodexServiceProvider), and Filament::getGlobalSearchProvider() still
     * returns the wrapper because it resolves through the container.
     */
    private function bootGlobalSearch(Panel $panel): void
    {
        if (! $this->hasGlobalSearch()) {
            return;
        }

        $provider = $panel->getGlobalSearchProvider();

        // null means the panel turned Filament's global search off entirely
        // (Panel::globalSearch(false)); nothing to wrap, so nothing is registered.
        if ($provider === null || $provider instanceof HelpSearchProvider) {
            return;
        }

        app()->extend(
            $provider::class,
            static fn (GlobalSearchProvider $inner): GlobalSearchProvider => $inner instanceof HelpSearchProvider
                ? $inner
                : new HelpSearchProvider($inner),
        );
    }

    public function helpButtonRenderHook(string|Closure $hook): static
    {
        $this->helpButtonRenderHook = $hook;

        return $this;
    }

    public function getHelpButtonRenderHook(): string
    {
        return $this->evaluate($this->helpButtonRenderHook) ?? PanelsRenderHook::USER_MENU_AFTER;
    }

    /**
     * True when the host chose the hook; register() then honours it as given
     * and registers no fallback.
     */
    public function hasExplicitHelpButtonRenderHook(): bool
    {
        return $this->helpButtonRenderHook !== null;
    }

    public function shortcut(string|Closure|null $shortcut): static
    {
        $this->shortcut = $shortcut;

        return $this;
    }

    public function getShortcut(): ?string
    {
        return $this->evaluate($this->shortcut);
    }

    public function drawerWidth(int|Closure $width): static
    {
        $this->drawerWidth = $width;

        return $this;
    }

    public function getDrawerWidth(): int
    {
        return $this->evaluate($this->drawerWidth);
    }

    /**
     * false removes the topbar button only; the drawer, its shortcut and
     * (Phase 4) field hints stay.
     */
    public function helpButton(bool|Closure $condition = true): static
    {
        $this->helpButton = $condition;

        return $this;
    }

    public function hasHelpButton(): bool
    {
        return $this->evaluate($this->helpButton);
    }

    /**
     * false renders no drawer, no help link and therefore no shortcut on
     * simple-layout pages (login, register, password reset, email
     * verification and any host SimplePage); signed-in pages are unaffected.
     */
    public function guestDrawer(bool|Closure $condition = true): static
    {
        $this->guestDrawer = $condition;

        return $this;
    }

    public function hasGuestDrawer(): bool
    {
        return $this->evaluate($this->guestDrawer);
    }

    /**
     * Whether this panel manages help content. false registers the article
     * resource, Help settings and Help coverage nowhere in it — no navigation
     * items and no routes — which is how a second panel carries the reading
     * half alone: the button, the drawer, its shortcut, field hints, the
     * panel scope and the help center all stay. Articles, abilities and the
     * panels that answer true are untouched.
     *
     * Read once, when the panel registers the plugin, so a closure here may
     * read config but not panel state or the signed-in user.
     */
    public function authoring(bool|Closure $condition = true): static
    {
        $this->authoring = $condition;

        return $this;
    }

    public function hasAuthoring(): bool
    {
        return $this->evaluate($this->authoring);
    }

    public function globalSearch(bool|Closure $enabled = true): static
    {
        $this->globalSearch = $enabled;

        return $this;
    }

    public function hasGlobalSearch(): bool
    {
        return $this->evaluate($this->globalSearch);
    }

    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->evaluate($this->navigationGroup);
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->evaluate($this->navigationSort);
    }

    /**
     * Where this panel's Help Center is reachable from: the user menu (the
     * default), the navigation, both, or neither.
     *
     * None withholds the two menu entries only. The page stays registered,
     * {panel}/help keeps answering, and the drawer footer, the field hints,
     * the global search rows and a bookmark all still reach it.
     */
    public function helpCenterPlacement(HelpCenterPlacement|Closure $placement): static
    {
        $this->helpCenterPlacement = $placement;

        return $this;
    }

    public function getHelpCenterPlacement(): HelpCenterPlacement
    {
        return $this->evaluate($this->helpCenterPlacement) ?? HelpCenterPlacement::UserMenu;
    }

    /**
     * The navigation group the Help Center item is filed under; null, the
     * default, leaves it at the top level.
     *
     * This and the three options below are the Help Center's alone and are
     * deliberately independent of navigationGroup() and navigationSort(),
     * which keep meaning what they have always meant: the editor, Help
     * settings and Help coverage. The Help Center must not join the sort
     * arithmetic those three chain off — it is the reading surface, not a
     * fourth admin screen, and a host arranges it wherever it likes.
     */
    public function helpCenterNavigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->helpCenterNavigationGroup = $group;

        return $this;
    }

    public function getHelpCenterNavigationGroup(): string|UnitEnum|null
    {
        return $this->evaluate($this->helpCenterNavigationGroup);
    }

    /**
     * Where the Help Center item sorts. The default 1000 puts it at the
     * bottom of a normal sidebar rather than in the middle of the host's own
     * arrangement; null genuinely means "no sort".
     */
    public function helpCenterNavigationSort(int|Closure|null $sort): static
    {
        $this->helpCenterNavigationSort = $sort;

        return $this;
    }

    public function getHelpCenterNavigationSort(): ?int
    {
        return $this->evaluate($this->helpCenterNavigationSort);
    }

    /**
     * The Help Center navigation item's label. The user-menu entry never
     * reads it — that entry's wording is fixed by design — so a custom label
     * renames the sidebar item alone. Defaults to the translated "Help
     * center", which is what the fixed entry says as well.
     *
     * The page's own heading stays "Help": the menu says "Help center"
     * because it opens the library, while the topbar's question-mark button
     * pops help next to what the viewer is doing. Two affordances, two words,
     * two icons.
     */
    public function helpCenterNavigationLabel(string|Closure|null $label): static
    {
        $this->helpCenterNavigationLabel = $label;

        return $this;
    }

    public function getHelpCenterNavigationLabel(): string
    {
        return $this->evaluate($this->helpCenterNavigationLabel) ?? (string) __('fin-codex::fin-codex.help_center.navigation');
    }

    /**
     * The Help Center item's icon, defaulting to an outlined book: the
     * library, never the question mark the topbar button already carries.
     *
     * The union matches Filament's own Page::getNavigationIcon() return type
     * exactly, because the page static hands this straight back.
     */
    public function helpCenterNavigationIcon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->helpCenterNavigationIcon = $icon;

        return $this;
    }

    public function getHelpCenterNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return $this->evaluate($this->helpCenterNavigationIcon) ?? Heroicon::OutlinedBookOpen;
    }

    /**
     * Swap in your own article resource; it must extend Resources\ArticleResource.
     *
     * @param  class-string  $resource
     */
    public function articleResource(string $resource): static
    {
        $this->articleResource = $resource;

        return $this;
    }

    /** @return class-string|null */
    public function getArticleResource(): ?string
    {
        return $this->articleResource;
    }

    /**
     * The article resource class in force for the current (or default)
     * panel: the articleResource() override when that panel has one and it
     * extends the built-in resource, Resources\ArticleResource otherwise. With
     * no panel current the default panel answers, and the built-in resource
     * stands in when there is no default panel or it carries no plugin. The
     * three resource pages answer
     * getResource() with this, which is what lets a host subclass change the
     * form, the table, the query and the relation managers, not only the
     * navigation statics.
     *
     * With a panel id, the answer is that panel's, whichever panel is current:
     * the coverage report scans every panel from one request and must file a
     * staff page under staff's resource, not admin's.
     *
     * @return class-string<ArticleResource>
     */
    public static function articleResourceClass(?string $panelId = null): string
    {
        try {
            $plugin = $panelId === null ? static::get() : Filament::getPanel($panelId)->getPlugin('fin-codex');
            $override = $plugin instanceof self ? $plugin->getArticleResource() : null;
        } catch (Throwable) {
            return ArticleResource::class;
        }

        return $override !== null && is_a($override, ArticleResource::class, true) ? $override : ArticleResource::class;
    }

    /** @param  class-string  $page */
    public function settingsPage(string $page): static
    {
        $this->settingsPage = $page;

        return $this;
    }

    /** @return class-string|null */
    public function getSettingsPage(): ?string
    {
        return $this->settingsPage;
    }

    /** @param  class-string  $page */
    public function coveragePage(string $page): static
    {
        $this->coveragePage = $page;

        return $this;
    }

    /** @return class-string|null */
    public function getCoveragePage(): ?string
    {
        return $this->coveragePage;
    }

    /**
     * Swap in your own Help Center page; it must extend Pages\HelpCenter.
     *
     * Unlike the settings and coverage pages, this one is registered on every
     * panel, whether or not the panel authors help.
     *
     * @param  class-string  $page
     */
    public function helpCenterPage(string $page): static
    {
        $this->helpCenterPage = $page;

        return $this;
    }

    /** @return class-string|null */
    public function getHelpCenterPage(): ?string
    {
        return $this->helpCenterPage;
    }

    /**
     * The Help Center page class in force for the current (or named) panel: the
     * helpCenterPage() override when that panel has one and it extends the
     * shipped page, Pages\HelpCenter otherwise. With no panel current the
     * default panel answers, and the shipped page stands in when there is no
     * default panel or it carries no plugin.
     *
     * This is what every caller outside the page itself builds a URL with — the
     * drawer's footer, the field hints, the global search rows — so a host that
     * subclasses the page has its own class linked to rather than ours.
     *
     * @return class-string<HelpCenter>
     */
    public static function helpCenterPageClass(?string $panelId = null): string
    {
        try {
            $plugin = $panelId === null ? static::get() : Filament::getPanel($panelId)->getPlugin('fin-codex');
            $override = $plugin instanceof self ? $plugin->getHelpCenterPage() : null;
        } catch (Throwable) {
            return HelpCenter::class;
        }

        return $override !== null && is_a($override, HelpCenter::class, true) ? $override : HelpCenter::class;
    }

    /**
     * The absolute URL of the Help Center page on the named panel, or on the
     * current one when no id is given; null when no URL can be built — a panel
     * without the page, or a tenanted panel before its tenant is known.
     *
     * Absolute on purpose, and the counterpart of the relative prefix the core
     * links with: this is what the topbar button, the drawer footer and the
     * user-menu entry link to, and an absolute URL keeps SPA navigation on for
     * them while the relative one keeps the drawer intercept on the hints.
     */
    public function helpCenterUrl(?string $panelId = null): ?string
    {
        try {
            return static::helpCenterPageClass($panelId)::getUrl([], true, $panelId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The MIME types the Media tab's upload accepts, replacing the default
     * list of documents; the body editor's image drop zone is unaffected.
     *
     * @param  list<string>|Closure  $mimeTypes
     */
    public function documentTypes(array|Closure $mimeTypes): static
    {
        $this->documentTypes = $mimeTypes;

        return $this;
    }

    /** @return list<string> */
    public function getDocumentTypes(): array
    {
        $types = $this->evaluate($this->documentTypes);

        return is_array($types) ? array_values(array_filter($types, 'is_string')) : self::DEFAULT_DOCUMENT_TYPES;
    }

    /** The largest document the Media tab accepts, in kilobytes. */
    public function documentMaxSize(int|Closure $kilobytes): static
    {
        $this->documentMaxSize = $kilobytes;

        return $this;
    }

    public function getDocumentMaxSize(): int
    {
        return (int) $this->evaluate($this->documentMaxSize);
    }

    /**
     * The current panel's document settings, or the defaults outside a
     * panel or on one without the plugin.
     *
     * @return array{types: list<string>, maxSize: int}
     */
    public static function documentUploads(): array
    {
        try {
            $plugin = static::get();
        } catch (Throwable) {
            $plugin = null;
        }

        return $plugin instanceof self
            ? ['types' => $plugin->getDocumentTypes(), 'maxSize' => $plugin->getDocumentMaxSize()]
            : ['types' => self::DEFAULT_DOCUMENT_TYPES, 'maxSize' => 10_240];
    }

    public function policyNamespace(string $namespace): static
    {
        $this->policyNamespace = $namespace;

        return $this;
    }

    public function getPolicyNamespace(): string
    {
        return $this->policyNamespace;
    }
}
