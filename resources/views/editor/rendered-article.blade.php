{{-- The body of the article preview and of the revision preview. The html has already been through lin-codex's renderer, which is the sanitiser too, so it is echoed raw; a raw body never is. The wrapper follows the panel's Alpine theme store exactly like the drawer (a light ancestor opts the core stylesheet out of its prefers-color-scheme rule; the static class covers a panel without dark mode before Alpine runs). .codex-root is what the stylesheet already in the panel head paints; the title and the body carry the core partial's classes, so the stylesheet's article rules apply. Every image the renderer marks with data-codex-lightbox opens in the core lightbox on click, as in the drawer; the lightbox is teleported to the body because Filament's slide-over is a transformed ancestor that would trap a fixed element, and it sits above the modal on the core's own z-index. $marker is the attribute the tests read — one per preview, so a revision assertion cannot pass on the live preview. --}}
<div @if (! $hasDarkMode) class="light" @endif
     x-data="{ lightbox: null, lightboxAlt: '' }"
     x-bind:class="{ light: $store.theme === 'light' }"
     x-on:click="const image = $event.target.closest('img[data-codex-lightbox]'); if (image) { $event.preventDefault(); lightbox = image.currentSrc || image.src; lightboxAlt = image.alt || '' }">
    <div class="codex-root" {{ $marker }}>
        <article class="codex-article">
            @if (filled($title))
                <h2 class="codex-article__title">{{ $title }}</h2>
            @endif
            <div class="codex-article__body" lang="{{ $locale }}">{!! $html !!}</div>
        </article>
    </div>
    <template x-teleport="body">
        <div class="codex-root codex-lightbox{{ $hasDarkMode ? '' : ' light' }}"
             x-cloak
             x-show="lightbox !== null"
             x-bind:class="{ light: $store.theme === 'light' }"
             x-on:click="lightbox = null"
             x-on:keydown.escape.window="lightbox = null"
             role="dialog"
             aria-label="{{ __('lin-codex::lin-codex.ui.lightbox_close') }}">
            <img class="codex-lightbox__image" x-bind:src="lightbox" x-bind:alt="lightboxAlt" alt="">
            <button type="button" class="codex-lightbox__close" aria-label="{{ __('lin-codex::lin-codex.ui.lightbox_close') }}">
                <svg class="codex-drawer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
    </template>
</div>
