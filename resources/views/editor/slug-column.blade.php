{{-- The article's identity, read like a table of contents entry: the parent path muted in front of the default-language title. ArticlesTable::pathParts() builds the state, so this view only lays it out. Styling is inline on purpose: a package cannot rely on the host's Tailwind build having compiled its utility classes. --}}
@php($state = $getState())

<div class="fi-ta-text" data-fin-codex-slug="{{ $state['slug'] }}" title="{{ $state['slug'] }}">
    @if ($state['parent'] !== null)<span data-fin-codex-slug-parent class="fi-color-gray" style="opacity: .55">{{ $state['parent'] }}/</span>@endif<span data-fin-codex-slug-title style="font-weight: 600">{{ $state['title'] }}</span>
</div>
