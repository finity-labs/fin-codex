{{-- Rendered at SIMPLE_PAGE_END by HelpMount::guestLink(): Filament's link component under the auth form, no badge. The anchor keeps the help-center URL for a click without JavaScript; with it, the drawer opens in place. --}}
<div data-fin-codex-guest-link="{{ $panelId }}"
     data-fin-codex-guard="{{ $guard }}"
     class="fin-codex-guest-link">
    <x-filament::link
        :href="route('lin-codex.help-center')"
        :icon="\Filament\Support\Icons\Heroicon::OutlinedQuestionMarkCircle"
        size="sm"
        data-codex-help-button
        x-data="{}"
        x-on:click.prevent="window.dispatchEvent(new CustomEvent('codex:open'))"
    >
        {{ $label }}
    </x-filament::link>
</div>
