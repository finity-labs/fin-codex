{{-- The view modal's body: the image at its natural size, capped to the modal. ViewMediaAction prepared {url, name}; inline styles because a package cannot rely on the host's Tailwind build having scanned our Blade files. --}}
<div data-fin-codex-media-view style="display: flex; justify-content: center">
    <img src="{{ $url }}" alt="{{ $name }}" style="max-width: 100%; max-height: 75vh; object-fit: contain; border-radius: .5rem">
</div>
