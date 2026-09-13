<?php

use Filament\Facades\Filament;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Auth\ArticleGate;
use FinityLabs\LinCodex\Auth\Viewer;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Facade;

/*
 * How the hook reaches the core's slot: FinCodexPlugin::boot() puts the class
 * name there, keeps whatever was there as the inner hook and never wraps
 * twice. A panel without the plugin installs nothing, and a core route served
 * after a panel request in the same process stays unscoped because no panel is
 * current on it — the config value survives that request, the verdict does not.
 *
 * One panel per test method, as everywhere else in the harness. The helpers
 * are file-local so a single-file run needs nothing from its neighbour.
 */

function finCodexBootUser(string $email = 'boot@example.com'): User
{
    return User::create(['name' => 'Boot', 'email' => $email]);
}

/**
 * One published, public article. The fourth argument pins the context to a
 * panel; without it the context names no panel and is read from every one.
 */
function finCodexBootArticle(string $slug, ?ContextType $type = null, string $key = '', ?string $panelId = null): Article
{
    $factory = Article::factory()->public()->published()->withTranslation('en', [
        'title' => ucfirst(str_replace('-', ' ', $slug)),
        'body' => 'Body of '.$slug.'.',
    ]);

    if ($type !== null) {
        $factory = $factory->withContext($type, $key, $panelId);
    }

    return $factory->create(['slug' => $slug]);
}

/** @return list<string> */
function finCodexBootSeen(Viewer $viewer): array
{
    $seen = array_keys(app(ArticleGate::class)->filter(app(ContentSource::class)->all(), $viewer));

    sort($seen);

    return $seen;
}

it('installs the hook when a fin-codex panel boots', function (): void {
    expect(config('lin-codex.auth.gate'))->toBeNull();

    $this->usesPanel('admin', finCodexBootUser());

    expect(config('lin-codex.auth.gate'))->toBe(PanelScopeGate::class)
        ->and(app(PanelScopeGate::class)->inner())->toBeNull();
});

it('keeps a host hook as the inner one, and both vetoes apply', function (): void {
    finCodexBootArticle('intro');
    finCodexBootArticle('admin-guide', ContextType::Route, 'filament.admin.pages.dashboard', 'admin');
    finCodexBootArticle('staff-guide', ContextType::Route, 'filament.staff.pages.dashboard', 'staff');
    $closure = fn (Viewer $viewer, ArticleData $article): bool => $article->slug !== 'intro';
    config()->set('lin-codex.auth.gate', $closure);

    $user = finCodexBootUser();
    $this->usesPanel('staff', $user);

    expect(config('lin-codex.auth.gate'))->toBe(PanelScopeGate::class)
        ->and(app(PanelScopeGate::class)->inner())->toBe($closure)
        ->and(finCodexBootSeen(Viewer::authenticated($user, 'staff')))->toBe(['staff-guide']);
});

it('wraps once however often the panel boots', function (): void {
    $panel = $this->usesPanel('admin', finCodexBootUser());

    $panel->getPlugin('fin-codex')->boot($panel);

    expect(config('lin-codex.auth.gate'))->toBe(PanelScopeGate::class)
        ->and(app(PanelScopeGate::class)->inner())->toBeNull();
});

it('installs nothing for a panel that does not carry the plugin', function (): void {
    $this->usesPanel('plain', finCodexBootUser());

    expect(config('lin-codex.auth.gate'))->toBeNull();
});

/**
 * End one in-process request and start the next the way a worker does.
 *
 * Testbench reuses one container for every $this->get() of a test, and
 * Filament's manager is a scoped binding holding the current panel, so without
 * this the panel a previous request set is still current on the next one.
 * Octane flushes exactly these two things between requests (FPM gets a fresh
 * process), which is what makes a core route a no-panel request in production.
 */
function finCodexEndRequest(): void
{
    app()->forgetScopedInstances();

    Facade::clearResolvedInstances();
}

it('installs the hook on a real panel request and leaves the core api unscoped', function (): void {
    finCodexBootArticle('intro');
    finCodexBootArticle('staff-guide', ContextType::Route, 'filament.staff.pages.dashboard');
    $user = finCodexBootUser();

    $this->actingAs($user, 'web')->get('/admin')->assertOk();

    expect(config('lin-codex.auth.gate'))->toBe(PanelScopeGate::class);

    finCodexEndRequest();

    // The config value survives into the next request of the same worker; the
    // verdict does not, because the core's own route boots no panel.
    expect(config('lin-codex.auth.gate'))->toBe(PanelScopeGate::class)
        ->and(Filament::getCurrentPanel())->toBeNull();

    $this->get('/codex/api/tree')->assertOk()->assertSee('staff-guide');
});
