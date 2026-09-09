{{-- Rendered at BODY_END by HelpMount::drawer(). The wrapper carries the identity as data attributes for tests, follows Filament's Alpine theme store (a light ancestor opts the core stylesheet out of its prefers-color-scheme rule; the static class covers panels without dark mode before Alpine runs) and stays out of layout with display: contents. The component is fin-codex's subclass of the core drawer, which renders the Filament-native shell; the tag is self-closed as Livewire 4 requires, and the shortcut always travels ('' disables it). --}}
<div data-fin-codex-drawer="{{ $panelId }}"
     data-fin-codex-page="{{ $pageClass }}"
     data-fin-codex-resource="{{ $resourceClass }}"
     data-fin-codex-guard="{{ $guard }}"
     data-fin-codex-shortcut="{{ $shortcut }}"
     @if (! $hasDarkMode) class="light" @endif
     x-data
     x-bind:class="{ light: $store.theme === 'light' }"
     style="display: contents">
    <livewire:fin-codex.help-drawer :page-class="$pageClass" :panel-id="$panelId" :guard="$guard" :shortcut="$shortcut" :width="$width" />
</div>
