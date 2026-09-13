<?php

use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\SourceWarnings;
use FinityLabs\FinCodex\Help\ArticleLookup;
use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\FinCodex\Panel\CurrentPage;
use FinityLabs\FinCodex\Scope\ContextPanels;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\FinCodex\Tests\TestCase;
use FinityLabs\LinCodex\Ai\Contracts\AiClient;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Settings\CodexAiSettings;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use FinityLabs\LinCodex\View\PageHelpResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\LaravelSettings\Models\SettingsProperty;

uses(TestCase::class)->in(__DIR__);

/**
 * Drop the request-scoped memos (lin-codex's page help, fin-codex's page
 * identity and the hint lookup) and the decorated content source when a
 * test seeds articles or swaps the request after a first in-process
 * request. The ContentSource
 * extender is re-applied on the next resolution, so the fresh instance is
 * again the declared-contexts decorator, over a fresh registry scan.
 *
 * The coverage report and the source warnings are here for the same reason as
 * the rest: each memoises one reading of the source per request, so a test
 * that seeds an article after reading either would otherwise keep getting the
 * answer from before the seed. The context-to-panel resolver is dropped for
 * the DeclaredContexts reason instead: it memoises one reading of the panel
 * registry per process, so a test that registers a panel mid-test needs a
 * fresh one. The panel scope gate is dropped for both reasons at once: it
 * memoises one verdict map per panel per request and it asks the resolver for
 * every context it sees.
 */
function forgetHelpMemo(): void
{
    app()->forgetInstance(PageHelpResolver::class);
    app()->forgetInstance(CurrentPage::class);
    app()->forgetInstance(ArticleLookup::class);
    app()->forgetInstance(ContentSource::class);
    app()->forgetInstance(DeclaredContexts::class);
    app()->forgetInstance(ContextPanels::class);
    app()->forgetInstance(PanelScopeGate::class);
    app()->forgetInstance(CoverageReport::class);
    app()->forgetInstance(SourceWarnings::class);
}

/**
 * Point the file source at tests/Fixtures/docs for the rest of the test: a
 * three-article English tree (intro, users, users/roles) with a German
 * translation of users/roles. The config write is test-side and happens
 * before the source is resolved; forgetHelpMemo() drops the composite so the
 * next ContentSource sees the files. Nothing is imported — the rows stay
 * file-only, which is the point.
 */
function useFixtureDocs(): void
{
    config()->set('lin-codex.sources.filesystem.paths', [__DIR__.'/Fixtures/docs']);

    app()->forgetInstance(FilesystemSource::class);

    forgetHelpMemo();
}

/**
 * Flip the core's revisions switch for the rest of the test. It lives here
 * rather than in a test file because Pest helpers are global and the
 * revisions toggle is read by the harness, the editor and — since Phase 6 —
 * the revisions relation manager's settings gate, so a single-file run of
 * any of them needs it loaded.
 */
function enableRevisions(bool $enabled): void
{
    $settings = app(CodexSettings::class);
    $settings->revisions_enabled = $enabled;
    $settings->save();
}

/**
 * Bind a fake AiClient for the rest of the test and hand it back.
 *
 * The client is the whole seam: AiAvailabilityCheck, ArticleTranslator, the
 * settings page and the install command each resolve it from the container
 * at call time, so this one bind answers for all of them. Without it a test
 * gets the real client, which reports the SDK missing on every CI row.
 */
function finCodexFakeAi(?FakeAiClient $fake = null): FakeAiClient
{
    $fake ??= new FakeAiClient;

    app()->instance(AiClient::class, $fake);

    return $fake;
}

/**
 * Switch AI translation on with lin-codex's own enableAi() values - anthropic,
 * the provider's default model, the key sk-test and 120 seconds - plus
 * $overrides.
 *
 * The values are saved, so a fresh app(CodexAiSettings::class) anywhere in
 * the test reads them. The harness seeds the group, so nothing has to be
 * reloaded first; on an unseeded group use AiSettings::write() instead.
 *
 * @param  array<string, mixed>  $overrides
 */
function finCodexEnableAi(array $overrides = []): void
{
    $settings = app(CodexAiSettings::class);

    $values = array_merge([
        'enabled' => true,
        'provider' => 'anthropic',
        'model' => null,
        'api_key' => 'sk-test',
        'timeout' => 120,
    ], $overrides);

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();
}

/**
 * Drop every stored AI setting and the resolved instance with it: the
 * not_migrated state of a host that upgraded lin-codex without running the
 * new settings migration. Forgetting the instance also drops one that
 * AiSettings::write() may have bound.
 */
function finCodexAiUnseed(): void
{
    SettingsProperty::query()->where('group', 'lin-codex-ai')->delete();

    app()->forgetInstance(CodexAiSettings::class);
}

/** How many settings rows the lin-codex-ai group holds right now. */
function finCodexAiRows(): int
{
    return SettingsProperty::query()->where('group', 'lin-codex-ai')->count();
}

/**
 * The stored Filament notifications of one fixture user, read with the panel
 * bell's own query (data->format = filament), so a row a test reads here is a
 * row the panel would show and a plain Laravel notification never counts.
 *
 * @return Collection<int, DatabaseNotification>
 */
function finCodexNotificationsFor(User $user): Collection
{
    return $user->notifications()->where('data->format', 'filament')->get();
}

/**
 * The number on the help button's badge for one panel, null when the button
 * carries none. The button is Filament's icon button: the badge sits in its
 * fi-icon-btn-badge-ctn container, and only the text matters here.
 */
function finCodexButtonBadge(string $html, string $panel): ?int
{
    $start = strpos($html, 'data-fin-codex-help-button="'.$panel.'"');

    if ($start === false) {
        return null;
    }

    $button = substr($html, $start, (int) strpos($html, '</a>', $start) - $start);

    if (preg_match('/fi-icon-btn-badge-ctn"[^>]*>(.*?)<\/div>/s', $button, $matches) !== 1) {
        return null;
    }

    return (int) trim(strip_tags($matches[1]));
}

/**
 * The opening <a ...> tag of the help button for one panel: Filament's icon
 * button, whose attribute order is the component's, so assertions read the
 * whole tag rather than a fixed sequence.
 */
function finCodexButtonTag(string $html, string $panel): string
{
    $start = strpos($html, 'data-fin-codex-help-button="'.$panel.'"');

    if ($start === false) {
        test()->fail("No help button for the {$panel} panel in the page.");
    }

    $open = (int) strpos($html, '<a', $start);
    $close = (int) strpos($html, '>', $open);

    return substr($html, $open, $close - $open + 1);
}
