{{-- The preview slide-over's body. The html has already been through lin-codex's renderer, which is the sanitiser too, so it is echoed raw; the raw article body never is. The wrapper follows the panel's Alpine theme store exactly like the drawer (a light ancestor opts the core stylesheet out of its prefers-color-scheme rule; the static class covers a panel without dark mode before Alpine runs), and .codex-root is what the stylesheet already in the panel head paints. --}}
<div @if (! $hasDarkMode) class="light" @endif
     x-data
     x-bind:class="{ light: $store.theme === 'light' }">
    <div class="codex-root" data-fin-codex-preview>
        {!! $html !!}
    </div>
</div>
