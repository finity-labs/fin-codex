<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Panel;

use Filament\Panel;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\LinCodex\View\PageHelpResolver;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

/**
 * The bodies of the render hooks FinCodexPlugin::register() wires. Every body
 * reads the page identity from CurrentPage, never from hook scopes: the topbar
 * hook has none and the button and the drawer must agree. Options come from
 * the plugin instance that registered the hook, panel state (dark mode,
 * topbar) is read lazily at render time. Nothing here decides who may read
 * what; the guard goes in as a prop and lin-codex's ViewerResolver and
 * ArticleGate decide.
 */
final class HelpMount
{
    public function __construct(
        private readonly CurrentPage $currentPage,
        private readonly PageHelpResolver $pageHelp,
    ) {}

    /**
     * The core stylesheet link followed by the accent, font and guest-link
     * rules; fires at HEAD_END on every panel page.
     */
    public function head(Panel $panel): HtmlString
    {
        return $this->render('fin-codex::panel.head', []);
    }

    /**
     * The topbar (or sidebar-footer) button: skipped when the option is off
     * or no panel is current; badge-less when the request is not a page
     * render (a refresh-topbar update), which wire:ignore keeps invisible on
     * the client. The badge is the page's article count from the same
     * request-scoped resolver the drawer's mount() reads, so the two always
     * agree.
     *
     * The href is this panel's own Help Center page, taken as a prop rather
     * than resolved in the view: the core's public help-center route is off on
     * a fin-codex host and resolving it by name would throw, which in a render
     * hook takes the whole page down with it. helpCenterUrl() answers null
     * instead of throwing when no URL can be built, and '#' is the honest
     * degradation. No canAccess() check on purpose — the href is only what a
     * click without JavaScript does, the handler always opens the drawer, and
     * a viewer without the permission who follows it gets a 403.
     */
    public function button(FinCodexPlugin $plugin, Panel $panel): HtmlString
    {
        $identity = $this->currentPage->identity();

        if (! $plugin->hasHelpButton() || ! $identity->hasPanel()) {
            return new HtmlString('');
        }

        $pageClass = $identity->pageClass();

        return $this->render('fin-codex::panel.button', [
            'pageClass' => $pageClass,
            'resourceClass' => $identity->resourceClass,
            'panelId' => $identity->panelId,
            'guard' => $identity->guard,
            'href' => $plugin->helpCenterUrl($identity->panelId) ?? '#',
            'tooltip' => (string) __('fin-codex::fin-codex.button.tooltip'),
            'badge' => $pageClass === null ? 0 : $this->pageHelp->for($pageClass, $identity->panelId, null, $identity->guard)->count(),
        ]);
    }

    /**
     * The "Need help?" link under simple-layout forms at SIMPLE_PAGE_END;
     * badge-less, so it needs no page identity and costs nothing on form
     * re-renders.
     *
     * The href is an inert, same-page one: the core's public help center is
     * off on a fin-codex host and the panel's own Help Center page sits behind
     * the panel login, so a guest has no destination to be sent to. This href
     * never throws and never leaves the page; the drawer opens from the Alpine
     * click handler. Livewire::originalUrl() rather than the request URL,
     * because a re-render of the auth form arrives on Livewire's own update
     * endpoint and the snapshot is what still knows the page.
     */
    public function guestLink(FinCodexPlugin $plugin, Panel $panel): HtmlString
    {
        $identity = $this->currentPage->identity();

        if (! $plugin->hasGuestDrawer() || ! $identity->hasPanel()) {
            return new HtmlString('');
        }

        return $this->render('fin-codex::panel.guest-link', [
            'panelId' => $identity->panelId,
            'guard' => $identity->guard,
            'href' => Livewire::originalUrl().'?codex',
            'label' => (string) __('fin-codex::fin-codex.guest.link'),
        ]);
    }

    /**
     * The drawer at BODY_END: skipped when no panel is current and on
     * simple-layout pages when the guest drawer is off.
     *
     * A disabled shortcut travels as '' rather than null: Blade's @props
     * treats a null prop as absent (it applies the default with ??), which
     * would hand the core its "not passed" marker and re-enable the
     * configured lin-codex.ui.shortcut. The core reads '' as disabled.
     */
    public function drawer(FinCodexPlugin $plugin, Panel $panel): HtmlString
    {
        $identity = $this->currentPage->identity();

        if (! $identity->hasPanel() || ($identity->isSimplePage && ! $plugin->hasGuestDrawer())) {
            return new HtmlString('');
        }

        return $this->render('fin-codex::panel.drawer', [
            'pageClass' => $identity->pageClass(),
            'resourceClass' => $identity->resourceClass,
            'panelId' => $identity->panelId,
            'guard' => $identity->guard,
            'shortcut' => $plugin->getShortcut() ?? '',
            'width' => $plugin->getDrawerWidth(),
            'hasDarkMode' => $panel->hasDarkMode(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function render(string $view, array $data): HtmlString
    {
        return new HtmlString(view($view, $data)->render());
    }
}
