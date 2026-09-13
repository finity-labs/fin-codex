<?php

use Filament\Facades\Filament;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\FinCodexServiceProvider;
use FinityLabs\FinCodex\Policies\ArticlePolicy;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\HostArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;

/*
 * AUTH-01, first half: the shipped policy and the arm that registers it.
 *
 * fin-codex ships a policy, which fin-mail does not: a panel with
 * strictAuthorization() has to render the editor on a bare install, and
 * Laravel's Gate never guesses a policy for a model that lives in someone
 * else's package. The default answers yes to any authenticated panel user; a
 * host tightens by writing {policyNamespace}\ArticlePolicy, which the same
 * registration arm picks up.
 */

/** A fixture user; the shipped policy asks for nothing beyond being signed in. */
function finCodexPolicyUser(string $email = 'policy@example.com'): User
{
    return User::create(['name' => 'Policy', 'email' => $email]);
}

/**
 * Re-run the provider's own registration.
 *
 * Through the resolved provider rather than a fresh instance: the point of
 * every row that calls this is what the booted application does, and a new
 * FinCodexServiceProvider would prove nothing about the one that booted.
 */
function finCodexRegisterPolicies(): void
{
    $provider = app()->getProvider(FinCodexServiceProvider::class);

    (new ReflectionMethod(FinCodexServiceProvider::class, 'registerPolicies'))->invoke($provider);
}

it('registers the shipped policy for the core article on a bare boot', function (): void {
    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(ArticlePolicy::class);
});

it('allows every ability for an authenticated user', function (string $ability, bool $needsRecord): void {
    $user = finCodexPolicyUser();
    $article = Article::factory()->create();

    expect(Gate::forUser($user)->allows($ability, $needsRecord ? $article : Article::class))->toBeTrue();
})->with([
    'viewAny' => ['viewAny', false],
    'view' => ['view', true],
    'create' => ['create', false],
    'update' => ['update', true],
    'delete' => ['delete', true],
    'restore' => ['restore', true],
    'import' => ['import', false],
    'convert' => ['convert', true],
]);

/*
 * viewAllPanels is the one ability the shipped policy refuses, so it stays out
 * of the dataset above. Open-by-default is about keeping a fresh install
 * usable; reading another panel's help is a decision only the host can make,
 * per viewer, by writing the method at {policyNamespace}\ArticlePolicy.
 */
it('answers false to viewAllPanels for an authenticated user', function (): void {
    $user = finCodexPolicyUser();

    expect(Gate::forUser($user)->allows('viewAllPanels', Article::class))->toBeFalse();
});

it('registers the shipped policy when no panel is current', function (): void {
    expect(Filament::getCurrentPanel())->toBeNull();

    finCodexRegisterPolicies();

    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(ArticlePolicy::class);
});

/** The live plugin of one fixture panel; its setters are read when that panel boots. */
function finCodexPolicyPlugin(string $panel): FinCodexPlugin
{
    $plugin = Filament::getPanel($panel)->getPlugin('fin-codex');

    if (! $plugin instanceof FinCodexPlugin) {
        throw new RuntimeException("Panel {$panel} has no FinCodexPlugin.");
    }

    return $plugin;
}

/**
 * A host policy in a namespace no fixture panel names, pointed at from the
 * staff panel for one test. The alias outlives the test; the plugin instance
 * does not, so no later test inherits the override.
 */
function finCodexHostPolicyOnStaff(): void
{
    if (! class_exists('FinCodexHostTest\\Policies\\ArticlePolicy', false)) {
        class_alias(HostArticlePolicy::class, 'FinCodexHostTest\\Policies\\ArticlePolicy');
    }

    finCodexPolicyPlugin('staff')->policyNamespace('FinCodexHostTest\\Policies');
}

it('registers the host policy of the panel that boots, not the default panel\'s', function (): void {
    finCodexHostPolicyOnStaff();

    // The provider already ran with the default panel's namespace, App\Policies,
    // where no host class exists: the shipped policy is in force.
    expect(Filament::getCurrentPanel())->toBeNull()
        ->and(Gate::getPolicyFor(Article::class))->toBeInstanceOf(ArticlePolicy::class);

    $this->usesPanel('staff');

    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(HostArticlePolicy::class);
});

it('registers the serving panel\'s namespace on a real request', function (): void {
    finCodexHostPolicyOnStaff();

    $this->actingAs(finCodexPolicyUser(), 'staff')->get('/staff')->assertOk();

    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(HostArticlePolicy::class);
});

it('keeps the shipped policy at provider boot when only a non-default panel names a host policy', function (): void {
    finCodexHostPolicyOnStaff();

    expect(Filament::getCurrentPanel())->toBeNull();

    finCodexRegisterPolicies();

    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(ArticlePolicy::class);
});

it('closes the resource while a deny-all policy is registered', function (): void {
    $this->usesPanel('admin', finCodexPolicyUser());

    expect(ArticleResource::canViewAny())->toBeTrue();

    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    expect(ArticleResource::canViewAny())->toBeFalse();
});
