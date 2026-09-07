{{-- Contexts a class declares in code through HasHelp. They live on the read model only: no row exists in codex_article_contexts, nothing here is in the form state and nothing here is ever written, so the list is flat text with a badge. Styling is inline on purpose: a package cannot rely on the host's Tailwind build having compiled its utility classes. --}}
<ul data-fin-codex-declared style="display: flex; flex-direction: column; gap: .375rem; margin-bottom: .75rem">
    @foreach ($declarations as $declaration)
        <li
            data-fin-codex-declared-panel="{{ $declaration->panelId }}"
            style="display: flex; flex-wrap: wrap; gap: .375rem; align-items: center"
        >
            <span
                data-fin-codex-declared-contexts
                class="fi-color-gray"
                style="font-family: ui-monospace, monospace; font-size: .75rem; word-break: break-all; opacity: .75"
            >{{ collect($declaration->contexts)->map(fn ($context) => $context->toString())->implode(', ') }}</span>

            <span
                class="fi-badge fi-size-sm fi-color fi-color-gray"
                x-data
                x-tooltip="{
                    content: @js(__('fin-codex::fin-codex.editor.contexts.declared_by', ['class' => $declaration->class, 'panel' => $declaration->panelId])),
                    theme: $store.theme,
                }"
            >{{ __('fin-codex::fin-codex.editor.contexts.declared') }}</span>
        </li>
    @endforeach
</ul>
