<?php

use FinityLabs\FinCodex\Tests\Fixtures\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * PANEL-06's spec, one panel per test method (see PanelsTest). The core
 * stylesheet reaches every plugin panel page through lin-codex's own hashed
 * route, rendered at HEAD_END by <x-lin-codex::styles />: never through
 * filament:assets, FilamentAsset or a custom theme, and nothing is published
 * into the harness so the route branch is the one asserted on. The inline
 * rule after the link points the core's accent at the panel's primary scale
 * (--primary-600, --primary-400 in dark mode) and its font at the panel's
 * --font-family. Dark mode follows Filament's Alpine theme store, not the OS:
 * every wrapper binds the light class to $store.theme, and a panel without
 * dark mode also carries the class statically so the core's
 * prefers-color-scheme rule is opted out before Alpine runs.
 */

/** The opening tag of the wrapper carrying $attribute="$panel"; fails the test when there is none. */
function finCodexWrapperTag(string $html, string $attribute, string $panel): string
{
    if (preg_match('/<div[^>]*'.preg_quote($attribute, '/').'="'.preg_quote($panel, '/').'"[^>]*>/', $html, $matches) !== 1) {
        test()->fail("No <div ... {$attribute}=\"{$panel}\"> wrapper in the page.");
    }

    return $matches[0];
}

function finCodexThemeUser(string $guard = 'web'): User
{
    return User::create(['name' => 'Tester', 'email' => $guard.'@example.com']);
}

it('links the core stylesheet from its own route in the head, before the accent rule', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    $link = strpos($html, '/codex/assets/codex.css?v=');
    $head = strpos($html, '</head>');
    $rule = strpos($html, '--codex-accent: var(--primary-600)');

    expect($link)->not->toBeFalse()
        ->and($head)->not->toBeFalse()
        ->and($rule)->not->toBeFalse()
        ->and($link)->toBeLessThan($head)
        ->and($rule)->toBeGreaterThan($link)
        ->and($rule)->toBeLessThan($head)
        ->and(substr_count($html, '/codex/assets/codex.css?v='))->toBe(1)
        ->and($html)->toContain('<link rel="stylesheet" href="')
        ->toContain('<style data-fin-codex-theme>')
        ->toContain('--codex-accent: var(--primary-400)')
        ->toContain('--codex-accent-fg: #fff')
        ->toContain('--codex-font: var(--font-family)')
        ->toContain('.fin-codex-guest-link')
        ->toContain('--primary-600:')
        ->toContain('--font-family:')
        ->not->toContain('vendor/lin-codex/codex.css')
        ->not->toContain('/css/finity-labs/');
})->with(['/admin/login', '/staff/login']);

it('serves the stylesheet the head links', function (): void {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect(preg_match('/<link rel="stylesheet" href="([^"]*\/codex\/assets\/codex\.css\?v=[^"]+)"/', $html, $matches))->toBe(1);

    // The route answers with a file response (no body buffer), so read the file it points at.
    $response = $this->get($matches[1])->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
    $file = $response->baseResponse;

    expect($file)->toBeInstanceOf(BinaryFileResponse::class)
        ->and(file_get_contents($file->getFile()->getPathname()))->toContain('.codex-root');
});

it('binds the drawer and the guest link to the panel theme store on a login page', function (): void {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    foreach (['data-fin-codex-drawer', 'data-fin-codex-guest-link'] as $attribute) {
        expect(finCodexWrapperTag($html, $attribute, 'admin'))
            ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"')
            ->toContain('style="display: contents"')
            ->toContain('x-data')
            ->not->toContain('class="light"');
    }
});

it('binds the topbar button to the panel theme store on an app page', function (): void {
    $html = $this->actingAs(finCodexThemeUser(), 'web')->get('/admin')->assertOk()->getContent();

    foreach (['data-fin-codex-help-button', 'data-fin-codex-drawer'] as $attribute) {
        expect(finCodexWrapperTag($html, $attribute, 'admin'))
            ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"')
            ->toContain('style="display: contents"')
            ->toContain('x-data')
            ->not->toContain('class="light"');
    }
});

it('adds a static light class on a panel without dark mode', function (): void {
    $html = $this->actingAs(finCodexThemeUser(), 'web')->get('/portal')->assertOk()->getContent();

    foreach (['data-fin-codex-drawer', 'data-fin-codex-help-button'] as $attribute) {
        expect(finCodexWrapperTag($html, $attribute, 'portal'))
            ->toContain('class="light"')
            ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"')
            ->toContain('style="display: contents"');
    }
});

it('adds a static light class on the login page of a panel without dark mode', function (): void {
    $html = $this->get('/portal/login')->assertOk()->getContent();

    foreach (['data-fin-codex-drawer', 'data-fin-codex-guest-link'] as $attribute) {
        expect(finCodexWrapperTag($html, $attribute, 'portal'))
            ->toContain('class="light"')
            ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"')
            ->toContain('style="display: contents"');
    }
});

it('never adds a static light class on a panel with dark mode', function (): void {
    $html = $this->get('/staff/login')->assertOk()->getContent();

    expect(finCodexWrapperTag($html, 'data-fin-codex-drawer', 'staff'))
        ->not->toContain('class="light"')
        ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"');
});
