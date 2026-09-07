{{-- Why this file can or cannot go. DeleteMediaAction::summary() decided every sentence, including each reference's label; this file loops. --}}
<div data-fin-codex-media-in-use class="fi-fo-field-wrp-hint">
    @if ($references === [])
        <p>{{ __('fin-codex::fin-codex.media.delete.free', ['disk' => $disk]) }}</p>
    @else
        <p><strong>{{ __('fin-codex::fin-codex.media.delete.in_use') }}</strong></p>
        <ul>
            @foreach ($references as $reference)
                <li data-fin-codex-media-ref="{{ $reference['slug'] }}:{{ $reference['locale'] }}">{{ $reference['label'] }}</li>
            @endforeach
        </ul>
        <p class="fi-color-warning">{{ __('fin-codex::fin-codex.media.delete.in_use_hint') }}</p>
    @endif
</div>
