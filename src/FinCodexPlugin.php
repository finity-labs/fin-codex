<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\View\ViewManager;
use Filament\View\PanelsRenderHook;
use FinityLabs\FinCodex\Auth\ArticlePolicyRegistration;
use FinityLabs\FinCodex\Enums\NavigationGroup;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Panel\HelpMount;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Search\HelpSearchProvider;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * Per-panel options for the Codex panel layer.
 *
 * Every option that can differ between two panels lives here as a fluent
 * method, never in config: the help button hook, the shortcut, the drawer
 * width, whether the button and the guest drawer render at all, global
 * search, navigation placement and the class overrides. Later code reads
 * them through filament('fin-codex').
 */
class FinCodexPlugin implements Plugin
{
    use EvaluatesClosures;

    protected string|Closure|null $helpButtonRenderHook = null;

    protected string|Closure|null $shortcut = 'ctrl+/';

    protected int|Closure $drawerWidth = 480;

    protected bool|Closure $helpButton = true;

    protected bool|Closure $guestDrawer = true;

    protected bool|Closure $globalSearch = false;

    protected string|UnitEnum|Closure|null $navigationGroup = NavigationGroup::Help;

    protected int|Closure|null $navigationSort = null;

    /** @var class-string|null */
    protected ?string $articleResource = null;

    /** @var class-string|null */
    protected ?string $settingsPage = null;

    /** @var class-string|null */
    protected ?string $coveragePage = null;

    protected string $policyNamespace = 'App\\Policies';

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'fin-codex';
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
     * never in register(). Two independent concerns, two private methods: the SPA
     * exception returns early on a panel without SPA mode, and appending the
     * global search block after that return would skip it on every non-SPA panel.
     */
    public function boot(Panel $panel): void
    {
        $this->bootPolicy();
        $this->bootSpaExceptions($panel);
        $this->bootGlobalSearch($panel);
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
        ArticlePolicyRegistration::register($this->getPolicyNamespace());
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
     */
    private function bootSpaExceptions(Panel $panel): void
    {
        if (! $panel->hasSpaMode()) {
            return;
        }

        $pattern = rtrim((string) config('lin-codex.routes.help_center', '/help'), '/').'/*';
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
