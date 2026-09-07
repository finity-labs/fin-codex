{{-- Rendered at the configured help button hook (or SIDEBAR_FOOTER as the fallback) by HelpMount::button(). wire:ignore keeps a refresh-topbar morph off the server-rendered badge; the theme binding mirrors the drawer wrapper; display: contents keeps the flex topbar row intact. --}}
<div wire:ignore data-fin-codex-help-button="{{ $panelId }}"
     data-fin-codex-page="{{ $pageClass }}"
     data-fin-codex-resource="{{ $resourceClass }}"
     data-fin-codex-guard="{{ $guard }}"
     @if (! $hasDarkMode) class="light" @endif
     x-data
     x-bind:class="{ light: $store.theme === 'light' }"
     style="display: contents">
    <x-lin-codex::help-button :page-class="$pageClass" :panel-id="$panelId" :guard="$guard" :badge="$pageClass !== null" :x-tooltip="$tooltip" class="fin-codex-help-button" />
</div>
