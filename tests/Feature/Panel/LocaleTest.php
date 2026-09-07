<?php

use FinityLabs\FinCodex\Tests\Fixtures\User;

/*
 * PANEL-07's spec, one panel per test method (see PanelsTest). No locale prop
 * is passed and no plugin option exists: the drawer locks app()->getLocale()
 * at mount (it appears in the Livewire snapshot) and the chrome around it
 * (the "This page" tab, the guest link, the button's aria-label and tooltip,
 * the shortcut hint) follows the request locale. The strings come from
 * lin-codex's en/de/hu files plus fin-codex's own two keys (guest.link and
 * button.tooltip). Every de/hu expectation is read back through __() under
 * the target locale and proven different from English, so a missing
 * translation fails loudly instead of matching the English fallback. The
 * fixture panels have no locale middleware, so app()->setLocale() before
 * the request is the whole setup.
 */

function finCodexLocaleUser(): User
{
    return User::create(['name' => 'Tester', 'email' => 'web@example.com']);
}

it('renders the drawer tab, the guest link and the drawer locale in the panel locale on the login page', function (string $locale, string $thisPage, string $needHelp): void {
    app()->setLocale($locale);

    expect(__('lin-codex::lin-codex.ui.this_page'))->toBe($thisPage)
        ->and(__('fin-codex::fin-codex.guest.link'))->toBe($needHelp);

    if ($locale !== 'en') {
        expect($thisPage)->not->toBe('This page')
            ->and($needHelp)->not->toBe('Need help?');
    }

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain($thisPage)
        ->toContain($needHelp)
        ->toContain('&quot;locale&quot;:&quot;'.$locale.'&quot;');

    if ($locale !== 'en') {
        expect($html)->not->toContain('Need help?');
    }
})->with([
    ['en', 'This page', 'Need help?'],
    ['de', 'Diese Seite', 'Brauchen Sie Hilfe?'],
    ['hu', 'Ez az oldal', 'Segítségre van szüksége?'],
]);

it('labels the topbar button and its tooltip in the panel locale', function (string $locale, string $help): void {
    app()->setLocale($locale);

    expect(__('lin-codex::lin-codex.ui.help'))->toBe($help)
        ->and(__('fin-codex::fin-codex.button.tooltip'))->toBe($help);

    if ($locale !== 'en') {
        expect($help)->not->toBe('Help');
    }

    $html = $this->actingAs(finCodexLocaleUser(), 'web')->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('aria-label="'.$help.'"')
        ->toContain('x-tooltip="{ content: &#039;'.$help.'&#039;, theme: $store.theme }"')
        ->toContain('&quot;locale&quot;:&quot;'.$locale.'&quot;');

    if ($locale !== 'en') {
        // Scoped to the button's own element: since Phase 5 the sidebar also
        // carries the article resource's navigation group, which on this panel
        // is the fixture's literal navigationGroup('Help') option. A host
        // string is never translated by fin-codex, so a page-wide search for
        // aria-label="Help" would now find the group, not a stale button.
        expect($html)->not->toMatch('/data-codex-help-button[^>]*aria-label="Help"/');
    }
})->with([
    ['en', 'Help'],
    ['de', 'Hilfe'],
    ['hu', 'Súgó'],
]);

it('keeps the shortcut hint in the panel locale', function (string $locale): void {
    app()->setLocale($locale);

    $hint = __('lin-codex::lin-codex.ui.shortcut_hint', ['shortcut' => 'ctrl+/']);

    expect($hint)->not->toBe('Press ctrl+/');

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain($hint)
        ->not->toContain('Press ctrl+/');
})->with(['de', 'hu']);
