{{-- The revision slide-over's body. The html has already been through lin-codex's renderer, which is the sanitiser too, so it is echoed raw; the raw revision body never is. The wrapper follows the panel's Alpine theme store exactly like the drawer and the editor's own preview (a light ancestor opts the core stylesheet out of its prefers-color-scheme rule; the static class covers a panel without dark mode before Alpine runs), and .codex-root is what the stylesheet already in the panel head paints. Its own marker, not the editor preview's: a revision assertion must not be able to pass on the live preview. --}}
<div @if (! $hasDarkMode) class="light" @endif
     x-data
     x-bind:class="{ light: $store.theme === 'light' }">
    <div class="codex-root" data-fin-codex-revision-preview>
        <h1>{{ $title }}</h1>
        {!! $html !!}
    </div>
</div>
