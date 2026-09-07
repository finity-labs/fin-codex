{{-- Rendered at HEAD_END by HelpMount::head(). The core link comes first so the rule below, at the same specificity, wins the cascade: the accent follows the panel's primary scale, the font follows the panel's --font-family and the guest link gets its spacing under the auth forms. --}}
<x-lin-codex::styles />
<style data-fin-codex-theme>
    .codex-root, .codex-help-button {
        --codex-accent: var(--primary-600);
        --codex-accent-fg: #fff;
        --codex-font: var(--font-family), ui-sans-serif, system-ui, sans-serif;
    }
    .dark .codex-root, .dark .codex-help-button {
        --codex-accent: var(--primary-400);
    }
    .fin-codex-guest-link {
        display: inline-flex;
        margin-top: 1rem;
    }
</style>
