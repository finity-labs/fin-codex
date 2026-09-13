<?php

declare(strict_types=1);

use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Policies\ArticlePolicy;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\ShieldedArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\UnregisteredShieldedArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\Shield\ShieldStub;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Auth\ArticleGate;
use FinityLabs\LinCodex\Auth\Viewer;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;

/*
 * SCOPE-03's missing half, found by UAT test 7: fin-codex:install created the
 * ViewAllPanels:Article permission row, the user ticked it on their role, and
 * nothing in the package ever read it — the shipped policy answered a hardcoded
 * no while Shield answered yes. shield:generate never writes a host policy for
 * the article (fin-codex has already bound one), so there was no other place
 * for the permission to be read either.
 *
 * The shipped policy now consults it, behind two protected seams. The rows below
 * run on a fixture subclass that overrides those seams, NOT on a stubbed
 * BezhanSalleh\* class: class_exists() is process-global, so a real stub would
 * flip every other row in the suite onto the Shield branch depending on load
 * order. PageShieldTest's last row guards that, and this file must never make it
 * red.
 *
 * A plain Gate::define() stands in for the granted permission because that is
 * exactly how spatie/laravel-permission answers one — it registers a gate hook
 * for every permission name, so $user->can('ViewAllPanels:Article') is a gate
 * question either way. No spatie symbol is needed anywhere, which is what keeps
 * both packages out of require-dev.
 */

/** A fixture user; the ability asks for nothing beyond being authorizable. */
function finCodexViewAllPanelsUser(string $email = 'viewall@example.com'): User
{
    return User::create(['name' => 'View all', 'email' => $email]);
}

/** Both ways the package asks the question, so neither can drift from the other. */
function finCodexViewAllPanelsAnswers(User $user): array
{
    return [
        'gate' => Gate::forUser($user)->allows('viewAllPanels', Article::class),
        'ability' => ArticleAbility::allows('viewAllPanels', Article::class, $user),
    ];
}

/*
 * -----------------------------------------------------------------------
 * The ability, with Shield present.
 * -----------------------------------------------------------------------
 */

it('grants view-all-panels when the user holds the permission shield generated', function (): void {
    $user = finCodexViewAllPanelsUser();

    Gate::policy(Article::class, ShieldedArticlePolicy::class);
    Gate::define(ShieldStub::VIEW_ALL_PANELS, fn (): bool => true);

    expect(finCodexViewAllPanelsAnswers($user))->toBe(['gate' => true, 'ability' => true]);
});

it('denies view-all-panels when the permission was never granted', function (): void {
    $user = finCodexViewAllPanelsUser();

    Gate::policy(Article::class, ShieldedArticlePolicy::class);

    // The permission row exists in Shield; nobody ticked it on this user's role.
    expect(Gate::has(ShieldStub::VIEW_ALL_PANELS))->toBeFalse()
        ->and(finCodexViewAllPanelsAnswers($user))->toBe(['gate' => false, 'ability' => false]);
});

it('denies view-all-panels when the permission says no for this user', function (): void {
    $user = finCodexViewAllPanelsUser();

    Gate::policy(Article::class, ShieldedArticlePolicy::class);
    Gate::define(ShieldStub::VIEW_ALL_PANELS, fn (): bool => false);

    expect(finCodexViewAllPanelsAnswers($user))->toBe(['gate' => false, 'ability' => false]);
});

it('denies view-all-panels when shield carries no permission for the ability', function (): void {
    $user = finCodexViewAllPanelsUser();

    Gate::policy(Article::class, UnregisteredShieldedArticlePolicy::class);

    // A host whose Shield entry predates the ability: the name is granted on a
    // role somewhere, but Shield does not map the action to it, so the policy
    // has nothing to ask and the scope stays on.
    Gate::define(ShieldStub::VIEW_ALL_PANELS, fn (): bool => true);

    expect(finCodexViewAllPanelsAnswers($user))->toBe(['gate' => false, 'ability' => false]);
});

/*
 * -----------------------------------------------------------------------
 * The default, which is the whole harness: no Shield.
 * -----------------------------------------------------------------------
 */

it('never consults the permission name without shield', function (): void {
    $user = finCodexViewAllPanelsUser();

    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(ArticlePolicy::class);

    // The name a Shield host would use, granted outright. Off a Shield host the
    // shipped policy must not look it up at all — the default stays closed.
    Gate::define(ShieldStub::VIEW_ALL_PANELS, fn (): bool => true);

    expect(finCodexViewAllPanelsAnswers($user))->toBe(['gate' => false, 'ability' => false]);
});

it('takes the permission name from shield rather than building one', function (): void {
    $seam = new ReflectionMethod(ShieldedArticlePolicy::class, 'shieldPermission');

    expect($seam->invoke(new ShieldedArticlePolicy, 'viewAllPanels'))->toBe(ShieldStub::VIEW_ALL_PANELS)
        ->and($seam->invoke(new ShieldedArticlePolicy, 'nothingShieldKnows'))->toBeNull();

    // Shield 4 makes the separator and the case configurable and lets a host
    // replace the key builder outright, so a name spelled out in the package is
    // wrong for somebody. It is asked for, never written down.
    $shipped = (string) file_get_contents(__DIR__.'/../../../src/Policies/ArticlePolicy.php');

    expect($shipped)->not->toContain(ShieldStub::VIEW_ALL_PANELS);
});

/*
 * -----------------------------------------------------------------------
 * End to end: the permission lifts the panel scope on a booted panel.
 * -----------------------------------------------------------------------
 */

/** One admin-bound article and one staff-bound one; the lift is what shows both. */
function finCodexViewAllPanelsSeed(): void
{
    foreach ([['admin-guide', 'filament.admin.pages.dashboard', 'admin'], ['staff-guide', 'filament.staff.pages.dashboard', 'staff']] as [$slug, $key, $panel]) {
        Article::factory()
            ->public()
            ->published()
            ->withTranslation('en', ['title' => ucfirst(str_replace('-', ' ', $slug)), 'body' => 'Body of '.$slug.'.'])
            ->withContext(ContextType::Route, $key, $panel)
            ->create(['slug' => $slug]);
    }

    config()->set('lin-codex.auth.gate', PanelScopeGate::class);
}

/**
 * The slugs the core admits for one viewer, sorted — the source's map through
 * ArticleGate::filter(), which is what the drawer, search and the reader share.
 *
 * @return list<string>
 */
function finCodexViewAllPanelsSeen(User $user): array
{
    $seen = array_keys(app(ArticleGate::class)->filter(app(ContentSource::class)->all(), Viewer::authenticated($user, 'web')));

    sort($seen);

    return $seen;
}

it('lifts the panel scope for a viewer holding the shield permission', function (): void {
    finCodexViewAllPanelsSeed();
    $user = finCodexViewAllPanelsUser();
    $this->usesPanel('admin', $user);

    // After usesPanel(): the plugin's boot registers the shipped policy again.
    Gate::policy(Article::class, ShieldedArticlePolicy::class);
    Gate::define(ShieldStub::VIEW_ALL_PANELS, fn (): bool => true);

    expect(finCodexViewAllPanelsSeen($user))->toBe(['admin-guide', 'staff-guide']);
});

it('keeps the panel scope on for a viewer without the permission', function (): void {
    finCodexViewAllPanelsSeed();
    $user = finCodexViewAllPanelsUser();
    $this->usesPanel('admin', $user);

    Gate::policy(Article::class, ShieldedArticlePolicy::class);

    expect(finCodexViewAllPanelsSeen($user))->toBe(['admin-guide']);
});
