{{-- fin-codex's view for the help drawer (Livewire\HelpDrawer, extending the core component). What is here is layout the Alpine glue (lin-codex::livewire.partials.drawer-script) queries or the tests read: the root with its data attributes, the overlay, the panel, the scrolling body (.codex-drawer__body) and the lightbox. Everything shown inside — header, search, tabs, lists, article and footer — is the component's three schemas, built from Filament components. --}}
<div class="codex-root codex-drawer fin-codex-drawer"
     data-codex-drawer
     data-codex-page-count="{{ count($pageArticles) }}"
     data-codex-view="{{ $view }}"
     style="--codex-drawer-width: {{ $width }}px"
     x-data="codexDrawer(@js($options))"
     x-bind:data-open="$wire.isOpen ? 'true' : 'false'"
     x-on:codex:open.window="openFrom($event)"
     x-on:keydown.window="onKey($event)">
    <div class="codex-drawer__overlay" x-cloak x-show="$wire.isOpen" x-transition.opacity x-on:click="$wire.close()" aria-hidden="true"></div>
    <div class="codex-drawer__panel" x-cloak x-show="$wire.isOpen" x-transition role="dialog" aria-modal="true" aria-label="{{ __('lin-codex::lin-codex.ui.title') }}">
        <header class="fin-codex-drawer__header">
            {{ $this->header }}
        </header>
        <div class="codex-drawer__body fin-codex-drawer__content" x-on:click="onBodyClick($event)">
            {{ $this->content }}
        </div>
        <footer class="fin-codex-drawer__footer">
            {{ $this->footer }}
        </footer>
    </div>
    <div class="codex-lightbox" wire:ignore x-cloak x-show="lightbox !== null" x-on:click="closeLightbox()" x-on:keydown.escape.window="closeLightbox()" role="dialog" aria-label="{{ __('lin-codex::lin-codex.ui.lightbox_close') }}">
        <img class="codex-lightbox__image" x-bind:src="lightbox" x-bind:alt="lightboxAlt" alt="">
        <button type="button" class="codex-lightbox__close" aria-label="{{ __('lin-codex::lin-codex.ui.lightbox_close') }}">
            <svg class="codex-drawer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
    <x-filament-actions::modals />
    @script
    @include('lin-codex::livewire.partials.drawer-script')
    @endscript
</div>
