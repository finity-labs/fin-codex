{{-- What deleting this article takes with it, printed above the confirmation. Everything is decided in DeleteArticleAction::summary(); this file only loops. --}}
<div data-fin-codex-delete-summary class="fi-fo-field-wrp-hint">
    @if ($isEmpty)
        <p>{{ __('fin-codex::fin-codex.editor.delete.none') }}</p>
    @else
        @if ($children !== [])
            <p><strong>{{ __('fin-codex::fin-codex.editor.delete.children') }}</strong></p>
            <ul>
                @foreach ($children as $child)
                    <li data-fin-codex-child="{{ $child['slug'] }}">{{ $child['slug'] }} — {{ $child['where'] }}</li>
                @endforeach
            </ul>
        @endif

        @if ($media !== [])
            <p><strong>{{ __('fin-codex::fin-codex.editor.delete.media') }}</strong></p>
            <ul>
                @foreach ($media as $file)
                    <li data-fin-codex-media="{{ $file['id'] }}">{{ $file['label'] }}</li>
                @endforeach
            </ul>
        @endif
    @endif

    @if ($exposes)
        <p data-fin-codex-exposes class="fi-color-warning">{{ __('fin-codex::fin-codex.editor.delete.exposes') }}</p>
    @endif
</div>
