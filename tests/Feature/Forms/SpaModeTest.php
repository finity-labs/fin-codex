<?php

use Filament\Facades\Filament;
use Filament\Support\View\ViewManager;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;

/*
 * HELP-04 on a panel with ->spa(). Filament adds wire:navigate to every
 * same-app href, and Livewire's navigate listener starts on mousedown without
 * consulting defaultPrevented, so the hint's Alpine intercept would lose the
 * race and the click would leave for the help center even with a drawer on
 * the page. FinCodexPlugin::boot() appends the help-center pattern to
 * Filament's SPA URL exceptions, which Panel::boot() seeds with the panel's
 * own list right before plugins boot. Every row flips ->spa() on a fixture
 * panel before the request or the boot, because panel state is read at boot
 * time (the HelpButtonTest ->topbar(true) trick); one panel per test.
 */

function finCodexSpaUser(): User
{
    return User::create(['name' => 'Member', 'email' => 'spa@example.com']);
}

function finCodexSpaSeedUsers(): void
{
    Article::factory()->public()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'How users work.'])
        ->create(['slug' => 'users']);
}

/**
 * The rendered hint anchor with its attributes entity-decoded, or null.
 */
function finCodexSpaAnchor(string $html): ?string
{
    return preg_match('/<a[^>]*fi-ac-icon-btn-action[^>]*>/', $html, $m) === 1
        ? html_entity_decode($m[0], ENT_QUOTES)
        : null;
}

/**
 * Anchors carrying Filament's SPA attribute. A substring count would also hit
 * the livewire:navigated event name in the base layout's dark-mode script.
 */
function finCodexSpaNavigateAnchors(string $html): int
{
    return preg_match_all('/<a[^>]*\\swire:navigate[\\s>]/', $html);
}

/**
 * @return list<string>
 */
function finCodexSpaExceptions(ViewManager $view): array
{
    $property = new ReflectionProperty($view, 'spaModeUrlExceptions');

    return array_values($property->getValue($view));
}

it('excludes the help center from SPA navigation once the plugin boots on a SPA panel', function (): void {
    Filament::getPanel('admin')->spa();
    $this->usesPanel('admin', finCodexSpaUser());

    $view = app(ViewManager::class);

    expect($view->hasSpaMode())->toBeTrue()
        ->and($view->hasSpaMode('/admin/users'))->toBeTrue()
        ->and($view->hasSpaMode('/help/users#assigning-roles'))->toBeFalse()
        ->and($view->hasSpaMode('/help/users/roles'))->toBeFalse();
});

it('renders the hint anchor without wire:navigate on a SPA panel while other links keep it', function (): void {
    finCodexSpaSeedUsers();
    Filament::getPanel('admin')->spa();

    $html = $this->actingAs(finCodexSpaUser(), 'web')
        ->get('/admin/users/create')->assertOk()->getContent();
    $anchor = finCodexSpaAnchor($html);

    expect($anchor)->not->toBeNull()
        ->toContain('href="/help/users#assigning-roles"')
        ->not->toContain('wire:navigate')
        ->and(finCodexSpaNavigateAnchors($html))->toBeGreaterThanOrEqual(1);
});

it('changes nothing without SPA mode', function (): void {
    finCodexSpaSeedUsers();

    $html = $this->actingAs(finCodexSpaUser(), 'web')
        ->get('/admin/users/create')->assertOk()->getContent();
    $anchor = finCodexSpaAnchor($html);

    expect(app(ViewManager::class)->hasSpaMode())->toBeFalse()
        ->and($anchor)->not->toBeNull()
        ->not->toContain('wire:navigate')
        ->and(finCodexSpaNavigateAnchors($html))->toBe(0);
});

it('honours a custom help-center prefix', function (): void {
    config(['lin-codex.routes.help_center' => '/docs']);
    Filament::getPanel('staff')->spa();
    $this->usesPanel('staff', finCodexSpaUser());

    $view = app(ViewManager::class);

    expect($view->hasSpaMode('/docs/users'))->toBeFalse()
        ->and($view->hasSpaMode('/help/users'))->toBeTrue();
});

it('does not accumulate the exception across boots', function (): void {
    Filament::getPanel('admin')->spa();
    $this->usesPanel('admin', finCodexSpaUser());

    $view = app(ViewManager::class);
    $before = finCodexSpaExceptions($view);

    Filament::getPanel('admin')->boot();
    Filament::getPanel('admin')->boot();

    expect($view->hasSpaMode('/help/x'))->toBeFalse()
        ->and(array_count_values($before)['/help/*'] ?? 0)->toBe(1)
        ->and(finCodexSpaExceptions($view))->toBe($before);
});
