{{-- One flag per configured language, in settings order, in the three states OutdatedTranslations returns: full colour when translated, grey when missing, ringed amber when the default language was saved later. ArticlesTable::flags() prepared every entry (code, emoji, state, tooltip), so this view only paints. The theme binding mirrors the drawer wrapper so the tooltip follows the panel theme. --}}
<div class="fi-ta-text" style="display: flex; gap: .25rem; align-items: center">
    @foreach ($getState() as $flag)
        <span
            data-fin-codex-locale="{{ $flag['code'] }}"
            data-fin-codex-state="{{ $flag['state'] }}"
            x-data
            x-tooltip="{ content: @js($flag['tooltip']), theme: $store.theme }"
            @class(['fi-color-warning' => $flag['state'] === 'outdated'])
            @style([
                'filter: grayscale(1); opacity: .5' => $flag['state'] === 'missing',
                'border-radius: 9999px; box-shadow: 0 0 0 2px rgb(245 158 11)' => $flag['state'] === 'outdated',
            ])
        >{{ $flag['flag'] }}</span>
    @endforeach
</div>
