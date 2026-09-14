{{-- Rendered at HEAD_END by HelpMount::head(). The core stylesheet comes first so the rules below, at the same specificity, win the cascade. The core's tokens are remapped onto the panel's own variables — Filament's grey scale, primary scale, radii and font — for light and dark, so article content rendered by the core partials follows the panel theme; the shell rules space the Filament components the drawer view uses; the Help Center rules stick its two rails, indent its nested tree and stop its article column widening the grid, dropping to one sticky-free column below Filament's lg breakpoint; the guest link gets its spacing under the auth forms. The core stylesheet stays the only thing styling the rendered article body. --}}
<x-lin-codex::styles />
<style data-fin-codex-theme>
    .codex-root, .codex-help-button {
        --codex-bg: #fff;
        --codex-fg: var(--gray-950);
        --codex-muted: var(--gray-500);
        --codex-border: var(--gray-200);
        --codex-surface: var(--gray-50);
        --codex-accent: var(--primary-600);
        --codex-accent-fg: #fff;
        --codex-font: var(--font-family), ui-sans-serif, system-ui, sans-serif;
        --codex-radius: var(--radius-lg, 0.5rem);
        --codex-radius-sm: var(--radius-md, 0.375rem);
        --codex-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
        --codex-overlay: rgb(0 0 0 / 0.5);
    }
    .dark .codex-root, .dark .codex-help-button {
        --codex-bg: var(--gray-900);
        --codex-fg: #fff;
        --codex-muted: var(--gray-400);
        --codex-border: rgb(255 255 255 / 0.1);
        --codex-surface: var(--gray-800);
        --codex-accent: var(--primary-400);
        --codex-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.6);
        --codex-overlay: rgb(0 0 0 / 0.6);
    }
    .fin-codex-drawer .codex-drawer__panel {
        font-family: var(--codex-font);
    }
    .fin-codex-drawer__header {
        flex: none;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--codex-border);
    }
    .fin-codex-drawer__content {
        padding: 1rem;
    }
    .fin-codex-drawer__footer {
        flex: none;
        padding: 0.75rem 1rem;
        border-top: 1px solid var(--codex-border);
    }
    .fin-codex-help__rail,
    .fin-codex-help__toc {
        position: sticky;
        top: 1rem;
        align-self: start;
        max-height: calc(100vh - 9rem);
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    .fin-codex-help__article {
        min-width: 0;
    }
    @media (max-width: 1023px) {
        .fin-codex-help__toc {
            display: none;
        }
        .fin-codex-help__rail {
            position: static;
            max-height: none;
            overflow: visible;
        }
    }
    .fin-codex-guest-link {
        display: flex;
        justify-content: center;
        margin-top: 1rem;
    }
</style>
