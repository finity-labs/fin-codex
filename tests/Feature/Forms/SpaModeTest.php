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
 *
 * Since Phase 16 the pattern comes from the panel's OWN Help Center page, not
 * from the core's published prefix, so it reads /admin/help/* on admin and
 * /staff/help/* on staff. The pattern and the hint's href are both relative to
 * the application root and therefore match; a Help Center page link is absolute
 * and therefore does not, which is what lets the hint keep its drawer intercept
 * while a tree link still swaps the page in place. The last two rows are that
 * pair.
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

it('excludes the help center from SPA navigation once the plugin boots on a SPA panel', function (): void {
    Filament::getPanel('admin')->spa();
    $this->usesPanel('admin', finCodexSpaUser());

    $view = app(ViewManager::class);

    // The /admin/users row is the regression guard for a blank prefix, which
    // used to leave a pattern that excepted every application URL.
    expect($view->hasSpaMode())->toBeTrue()
        ->and($view->hasSpaMode('/admin/users'))->toBeTrue()
        ->and($view->hasSpaMode('/admin/help/users#assigning-roles'))->toBeFalse()
        ->and($view->hasSpaMode('/admin/help/users/roles'))->toBeFalse();
});

it('renders the hint anchor without wire:navigate on a SPA panel while other links keep it', function (): void {
    finCodexSpaSeedUsers();
    Filament::getPanel('admin')->spa();

    $html = $this->actingAs(finCodexSpaUser(), 'web')
        ->get('/admin/users/create')->assertOk()->getContent();
    $anchor = finCodexSpaAnchor($html);

    expect($anchor)->not->toBeNull()
        ->toContain('href="/admin/help/users#assigning-roles"')
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

/*
 * The panel's own Help Center decides the pattern, so a host that set a prefix
 * in the core's config file no longer steers the exception: inside a panel the
 * plugin overwrites that value with the panel's own path before the pattern is
 * built. The config value is excepted from nothing.
 */
it("follows the panel's own prefix, not the config file's", function (): void {
    config(['lin-codex.routes.help_center' => '/docs']);
    Filament::getPanel('staff')->spa();
    $this->usesPanel('staff', finCodexSpaUser());

    $view = app(ViewManager::class);

    expect($view->hasSpaMode('/staff/help/users'))->toBeFalse()
        ->and($view->hasSpaMode('/docs/users'))->toBeTrue();
});

it('does not accumulate the exception across boots', function (): void {
    Filament::getPanel('admin')->spa();
    $this->usesPanel('admin', finCodexSpaUser());

    $view = app(ViewManager::class);
    $before = finCodexSpaExceptions($view);

    Filament::getPanel('admin')->boot();
    Filament::getPanel('admin')->boot();

    expect($view->hasSpaMode('/admin/help/x'))->toBeFalse()
        ->and(array_count_values($before)['/admin/help/*'] ?? 0)->toBe(1)
        ->and(finCodexSpaExceptions($view))->toBe($before);
});

/*
 * The other half of the pair. A tree link on the Help Center page is built with
 * the page's own absolute URL, which the relative exception pattern cannot
 * match, so Filament arms Livewire's navigate listener on it and a reader who
 * clicks one swaps the article in place. The hint anchor two rows above is the
 * same panel, the same request cycle and the opposite answer.
 */
it('keeps wire:navigate on a Help Center tree link while the hint goes without it', function (): void {
    finCodexSpaSeedUsers();
    Filament::getPanel('admin')->spa();

    $html = $this->actingAs(finCodexSpaUser(), 'web')
        ->get('/admin/help')->assertOk()->getContent();

    preg_match('/<a[^>]*data-fin-codex-help-node="users"[^>]*>/', $html, $m);
    $treeLink = $m === [] ? null : html_entity_decode($m[0], ENT_QUOTES);

    expect($treeLink)->not->toBeNull()
        ->toContain('href="http://localhost/admin/help/users"')
        ->toContain('wire:navigate')
        ->and(finCodexSpaNavigateAnchors($html))->toBeGreaterThanOrEqual(1);
});
