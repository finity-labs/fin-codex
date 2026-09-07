{{-- MediaRelationManager prepared {url, isImage, label}; this file only paints. Inline styles because a package cannot rely on the host's Tailwind build having scanned our Blade files. --}}
@php($state = $getState())
<div data-fin-codex-media-thumb style="display: flex; align-items: center">
    @if ($state['isImage'] && $state['url'] !== null)
        <img src="{{ $state['url'] }}" alt="{{ $state['label'] }}" loading="lazy" decoding="async"
             style="width: 2.5rem; height: 2.5rem; object-fit: cover; border-radius: .375rem">
    @else
        <span class="fi-ta-text" style="display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: .375rem; border: 1px dashed currentColor; opacity: .5; font-size: .625rem">
            {{ __('fin-codex::fin-codex.media.no_preview') }}
        </span>
    @endif
</div>
