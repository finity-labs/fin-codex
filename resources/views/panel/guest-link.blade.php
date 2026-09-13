{{-- Rendered at SIMPLE_PAGE_END by HelpMount::guestLink(): Filament's link component under the auth form, no badge. The href is an inert, same-page one so the link never throws and never leaves the page — a guest has no help center to be sent to, the public one being off and the panel's own sitting behind this very login. The drawer opens from the click handler. --}}
<div data-fin-codex-guest-link="{{ $panelId }}"
     data-fin-codex-guard="{{ $guard }}"
     class="fin-codex-guest-link">
    <x-filament::link
        :href="$href"
        :icon="\Filament\Support\Icons\Heroicon::OutlinedQuestionMarkCircle"
        size="sm"
        data-codex-help-button
        x-data="{}"
        x-on:click.prevent="window.dispatchEvent(new CustomEvent('codex:open'))"
    >
        {{ $label }}
    </x-filament::link>
</div>
