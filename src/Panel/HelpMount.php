<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Panel;

use Filament\Panel;
use FinityLabs\FinCodex\FinCodexPlugin;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

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
    public function __construct(private readonly CurrentPage $currentPage) {}

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
     * the client. The tooltip label stays unescaped Unicode (Súgó, not
     * S\u00fag\u00f3): Blade escapes the attribute, so only readability is
     * at stake.
     */
    public function button(FinCodexPlugin $plugin, Panel $panel): HtmlString
    {
        $identity = $this->currentPage->identity();

        if (! $plugin->hasHelpButton() || ! $identity->hasPanel()) {
            return new HtmlString('');
        }

        $label = (string) __('fin-codex::fin-codex.button.tooltip');

        return $this->render('fin-codex::panel.button', [
            'pageClass' => $identity->pageClass(),
            'resourceClass' => $identity->resourceClass,
            'panelId' => $identity->panelId,
            'guard' => $identity->guard,
            'hasDarkMode' => $panel->hasDarkMode(),
            'tooltip' => '{ content: '.Js::from($label, JSON_UNESCAPED_UNICODE).', theme: $store.theme }',
        ]);
    }

    /**
     * The "Need help?" link under simple-layout forms at SIMPLE_PAGE_END;
     * badge-less, so it needs no page identity and costs nothing on form
     * re-renders.
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
            'hasDarkMode' => $panel->hasDarkMode(),
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
