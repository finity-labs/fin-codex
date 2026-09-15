<?php

declare(strict_types=1);

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Scope\ContextPanels;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\ViewAllPanelsArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Auth\ArticleGate;
use FinityLabs\LinCodex\Auth\Viewer;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;

/*
 * SCOPE-04's seam: "what would a reader standing in panel X see?", asked of the
 * gate itself rather than answered a second time beside it.
 *
 * Every row drives the verdicts through the core, exactly as PanelScopeGateTest
 * does — the decorated ContentSource filtered by lin-codex's own ArticleGate —
 * so what these rows pin is what the drawer, the hints, search and the Help
 * Center all get.
 *
 * The viewer of record is one the host policy lets read every panel, because
 * that is the viewer the filter exists for: without the seam the rule is lifted
 * for exactly that viewer and every panel option would answer "everything".
 *
 * One panel per test method, as everywhere else in this directory: Filament
 * boots the first panel of a PHP request cycle only. Articles are seeded before
 * the first read, so no memo needs dropping.
 *
 * The helpers are file-local and prefixed, the way PanelScopeSurfacesTest's are:
 * Pest's are global for the whole run but only for the files the run actually
 * loads, so calling PanelScopeGateTest's set from here would leave this file
 * unrunnable on its own. They seed the same six-article set that file reads the
 * panel rule off, and the two must stay in step.
 */

/** Put the hook in the core's slot without booting a panel. */
function finCodexScopePreviewInstall(): void
{
    config()->set('lin-codex.auth.gate', PanelScopeGate::class);
}

function finCodexScopePreviewUser(): User
{
    return User::create(['name' => 'Preview', 'email' => 'preview@example.com']);
}

/** The singleton the panel boot installs; preview() is asked of it directly. */
function finCodexScopePreviewGate(): PanelScopeGate
{
    return app(PanelScopeGate::class);
}

/** One published, public article, optionally carrying one stored context. */
function finCodexScopePreviewArticle(string $slug, ?ContextType $type = null, string $key = '', ?string $panelId = null): Article
{
    $factory = Article::factory()->public()->published()->withTranslation('en', [
        'title' => ucfirst(str_replace(['-', '/'], ' ', $slug)),
        'body' => 'Body of '.$slug.'.',
    ]);

    if ($type !== null) {
        $factory = $factory->withContext($type, $key, $panelId);
    }

    return $factory->create(['slug' => $slug]);
}

/**
 * The six-article set the panel rule is read off: one general, three pinned by
 * an explicit prefix (admin's route, staff's route, staff's page class) and two
 * carrying no panel of their own — a plain Laravel route and a class no panel
 * has, the set's two "any panel" members, read from every panel.
 */
function finCodexScopePreviewSeed(): void
{
    finCodexScopePreviewArticle('intro');
    finCodexScopePreviewArticle('admin-guide', ContextType::Route, 'filament.admin.pages.dashboard', 'admin');
    finCodexScopePreviewArticle('staff-guide', ContextType::Route, 'filament.staff.pages.dashboard', 'staff');
    finCodexScopePreviewArticle('prefixed-staff', ContextType::PageClass, Dashboard::class, 'staff');
    finCodexScopePreviewArticle('plain-page', ContextType::Route, 'shop.index');
    finCodexScopePreviewArticle('unknown-class', ContextType::PageClass, 'App\\Nowhere');
}

/** What the seed set holds, whole: the answer a lifted viewer gets unasked. */
function finCodexScopePreviewEverything(): array
{
    return ['admin-guide', 'intro', 'plain-page', 'prefixed-staff', 'staff-guide', 'unknown-class'];
}

/** The seed set as the admin panel's own rule scopes it. */
function finCodexScopePreviewOnAdmin(): array
{
    return ['admin-guide', 'intro', 'plain-page', 'unknown-class'];
}

/** The seed set as the staff panel's own rule scopes it. */
function finCodexScopePreviewOnStaff(): array
{
    return ['intro', 'plain-page', 'prefixed-staff', 'staff-guide', 'unknown-class'];
}

it('answers for another panel although the viewer\'s grant would lift the rule', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    // After usesPanel(): the plugin's boot registers the shipped policy again.
    Gate::policy(Article::class, ViewAllPanelsArticlePolicy::class);

    $viewer = Viewer::authenticated($user, 'web');

    expect(finCodexSeenSlugs($viewer))->toBe(finCodexScopePreviewEverything())
        ->and(finCodexScopePreviewGate()->preview('staff', fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(finCodexScopePreviewOnStaff());
});

it('answers for the current panel too, which the same grant would otherwise widen', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    Gate::policy(Article::class, ViewAllPanelsArticlePolicy::class);

    $viewer = Viewer::authenticated($user, 'web');

    expect(finCodexScopePreviewGate()->preview('admin', fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(finCodexScopePreviewOnAdmin());
});

it('hands a null preview straight back to the normal rule', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    Gate::policy(Article::class, ViewAllPanelsArticlePolicy::class);

    $viewer = Viewer::authenticated($user, 'web');

    expect(finCodexScopePreviewGate()->preview(null, fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(finCodexScopePreviewEverything());
});

it('answers for a panel while no panel at all is current', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $viewer = Viewer::authenticated($user, 'web');

    // Outside every panel the gate returns early. A preview is still a question
    // it has to answer: the asker is holding a panel id of its own, and the
    // absence of a current panel is not a reason to ignore it.
    expect(finCodexSeenSlugs($viewer))->toBe(finCodexScopePreviewEverything())
        ->and(finCodexScopePreviewGate()->preview('staff', fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(finCodexScopePreviewOnStaff());
});

it('gives a nested call its own panel and the outer one its panel back', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    Gate::policy(Article::class, ViewAllPanelsArticlePolicy::class);

    $viewer = Viewer::authenticated($user, 'web');
    $gate = finCodexScopePreviewGate();

    $answers = $gate->preview('admin', function () use ($gate, $viewer): array {
        $inner = $gate->preview('staff', fn (): array => finCodexSeenSlugs($viewer));

        // The outer preview is back in force here: not the normal rule, which
        // for this viewer would answer with everything, and not staff either.
        return ['inner' => $inner, 'outer' => finCodexSeenSlugs($viewer)];
    });

    expect($answers['inner'])->toBe(finCodexScopePreviewOnStaff())
        ->and($answers['outer'])->toBe(finCodexScopePreviewOnAdmin());
});

/*
 * The two no-leak rows. Neither calls forgetHelpMemo(): that helper drops the
 * singleton, and a fresh gate has no preview to leak, so the rows would pass
 * over a preview() that never restores anything. The viewer here is deliberately
 * one the shipped policy grants nothing, so the read after the preview answers
 * with the admin set — a leaked 'staff' would show prefixed-staff and staff-guide
 * and take admin-guide away.
 */
it('is answering by the normal rule again on the very next read', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    $viewer = Viewer::authenticated($user, 'web');

    expect(finCodexScopePreviewGate()->preview('staff', fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(finCodexScopePreviewOnStaff())
        ->and(finCodexSeenSlugs($viewer))->toBe(finCodexScopePreviewOnAdmin());
});

it('is answering by the normal rule again after the callback throws', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    $viewer = Viewer::authenticated($user, 'web');

    expect(fn (): mixed => finCodexScopePreviewGate()->preview('staff', function (): never {
        throw new RuntimeException('inside the preview');
    }))->toThrow(RuntimeException::class, 'inside the preview');

    expect(finCodexSeenSlugs($viewer))->toBe(finCodexScopePreviewOnAdmin());
});

it('changes nothing for a viewer already scoped to the panel being previewed', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    $viewer = Viewer::authenticated($user, 'web');

    expect(finCodexSeenSlugs($viewer))->toBe(finCodexScopePreviewOnAdmin())
        ->and(finCodexScopePreviewGate()->preview('admin', fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(finCodexScopePreviewOnAdmin());
});

/*
 * The outside-panels bucket, which is a panel id only in the sense that the
 * filter carries it as one. The decision of 2026-09-12: an article is outside
 * when at least one of its contexts resolves into no panel, mirroring the
 * own-panel rule one for one rather than demanding that every context be
 * outside. The mirror image of these rows, under the normal rule, is in
 * PanelScopeGateTest — a panel-less context does not restrict there, so the
 * same fixtures come out of the two files with opposite meanings on purpose.
 */
it('gathers what no panel claims, the general articles included', function (): void {
    finCodexScopePreviewSeed();
    finCodexScopePreviewArticle('shop-url', ContextType::Url, '/shop/checkout');
    finCodexScopePreviewArticle('admin-url', ContextType::Url, '/admin/reports');
    finCodexScopePreviewArticle('any-route', ContextType::Route, 'filament.admin.pages.dashboard');
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    Gate::policy(Article::class, ViewAllPanelsArticlePolicy::class);

    $viewer = Viewer::authenticated($user, 'web');

    // In: a plain Laravel route, a url under no panel's path, a class no panel
    // registers — and intro, which carries no contexts at all and is read from
    // everywhere, this bucket included. Out: the three pinned articles, and the
    // panel-less url and route whose keys do resolve into a panel. The bucket
    // asks where a context lands, which is a different question from the one
    // the named-panel rule asks about the very same articles.
    expect(finCodexScopePreviewGate()->preview(ContextPanels::OUTSIDE_PANELS, fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(['intro', 'plain-page', 'shop-url', 'unknown-class']);
});

it('takes one context landing outside as enough, beside a panel\'s own', function (): void {
    Article::factory()->public()->published()->withTranslation('en', ['title' => 'Mixed', 'body' => 'Mixed body.'])
        ->withContext(ContextType::PageClass, Dashboard::class, 'admin')
        ->withContext(ContextType::Route, 'shop.index')
        ->create(['slug' => 'mixed']);
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    $viewer = Viewer::authenticated($user, 'web');

    // The article really does document both, so it answers to both options.
    expect(finCodexScopePreviewGate()->preview(ContextPanels::OUTSIDE_PANELS, fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(['mixed'])
        ->and(finCodexScopePreviewGate()->preview('admin', fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(['mixed']);
});

it('leaves a pinned article out of it whatever its key would resolve into', function (): void {
    finCodexScopePreviewArticle('plain-page', ContextType::Route, 'shop.index');
    finCodexScopePreviewArticle('pinned-shop', ContextType::Route, 'shop.index', 'admin');
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    $viewer = Viewer::authenticated($user, 'web');

    // One key, two articles: the prefix is what decides, because a context that
    // names a panel resolves into that panel and never into nothing.
    expect(finCodexScopePreviewGate()->preview(ContextPanels::OUTSIDE_PANELS, fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(['plain-page']);
});

it('leaves out an article bound to a class some panel registers', function (): void {
    finCodexScopePreviewArticle('plain-page', ContextType::Route, 'shop.index');
    finCodexScopePreviewArticle('shared-page', ContextType::PageClass, Dashboard::class);
    finCodexScopePreviewInstall();
    $user = finCodexScopePreviewUser();
    $this->usesPanel('admin', $user);

    $viewer = Viewer::authenticated($user, 'web');

    // The forClass() expectation fails loudly if the fixture panels ever stop
    // registering the dashboard, which would leave the row proving nothing.
    expect(app(ContextPanels::class)->forClass(Dashboard::class))->not->toBe([])
        ->and(finCodexScopePreviewGate()->preview(ContextPanels::OUTSIDE_PANELS, fn (): array => finCodexSeenSlugs($viewer)))
        ->toBe(['plain-page']);
});
