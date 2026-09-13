{{-- Rendered at the configured help button hook (USER_MENU_AFTER by default, SIDEBAR_FOOTER as the topbar-less fallback) by HelpMount::button(). Filament's own icon button at the notification bell's size (icon-size lg, default button size), in the primary colour with the page's article count as its badge, so it sits beside the bell as one of the panel's controls. The anchor keeps this panel's own Help Center page for a click without JavaScript; with it, the drawer opens in place. The href arrives as a prop — resolving a route by name here would take the whole page down when it is gone. wire:ignore keeps a refresh-topbar morph off the server-rendered badge; display: contents keeps the flex row intact. --}}
<div wire:ignore data-fin-codex-help-button="{{ $panelId }}"
     data-fin-codex-page="{{ $pageClass }}"
     data-fin-codex-resource="{{ $resourceClass }}"
     data-fin-codex-guard="{{ $guard }}"
     style="display: contents">
    <x-filament::icon-button
        tag="a"
        :href="$href"
        color="primary"
        :icon="\Filament\Support\Icons\Heroicon::OutlinedQuestionMarkCircle"
        icon-size="lg"
        :label="$tooltip"
        :tooltip="$tooltip"
        :badge="$badge > 0 ? $badge : null"
        badge-color="primary"
        data-codex-help-button
        x-data="{}"
        x-on:click.prevent="window.dispatchEvent(new CustomEvent('codex:open'))"
    />
</div>
