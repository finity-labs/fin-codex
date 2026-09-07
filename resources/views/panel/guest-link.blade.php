{{-- Rendered at SIMPLE_PAGE_END by HelpMount::guestLink(): the labelled core button with no badge, inside the same theme wrapper as the drawer. --}}
<div data-fin-codex-guest-link="{{ $panelId }}"
     data-fin-codex-guard="{{ $guard }}"
     @if (! $hasDarkMode) class="light" @endif
     x-data
     x-bind:class="{ light: $store.theme === 'light' }"
     style="display: contents">
    <x-lin-codex::help-button :label="$label" :badge="false" :panel-id="$panelId" :guard="$guard" class="fin-codex-guest-link" />
</div>
