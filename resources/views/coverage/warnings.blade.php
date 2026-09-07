{{-- Grouped in PHP (SourceWarnings::grouped()), so this file is a loop with no conditionals: the kind heading is the CORE's translated label and each line is the CORE's translated sentence. The two data attributes carry value and identity together, which is what makes a count inside a nested render assertable without a regex walking divs. Styling is inline on purpose: a package cannot rely on the host's Tailwind build having compiled its utility classes. --}}
<div data-fin-codex-warnings="{{ collect($groups)->sum('count') }}" style="display: flex; flex-direction: column; gap: .75rem">
    @foreach ($groups as $group)
        <div data-fin-codex-warning-kind="{{ $group['key'] }}" data-fin-codex-warning-count="{{ $group['key'] }}:{{ $group['count'] }}">
            <p class="fi-color-warning" style="font-weight: 600; margin-bottom: .25rem">{{ $group['label'] }} ({{ $group['count'] }})</p>

            <ul style="display: flex; flex-direction: column; gap: .25rem">
                @foreach ($group['lines'] as $line)
                    <li style="font-size: .875rem">
                        {{ $line['message'] }}
                        <span
                            class="fi-color-gray"
                            style="font-family: ui-monospace, monospace; font-size: .75rem; word-break: break-all; opacity: .75"
                            title="{{ __('fin-codex::fin-codex.warnings.path') }}"
                        >{{ $line['path'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
