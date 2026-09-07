<?php

use Filament\Facades\Filament;
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

it('registers the shipped policy when no panel is current', function (): void {
    expect(Filament::getCurrentPanel())->toBeNull();

    finCodexRegisterPolicies();

    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(ArticlePolicy::class);
});

it('prefers a host policy that exists in the panel policy namespace', function (): void {
    $this->usesPanel('staff');

    if (! class_exists('Staff\\Policies\\ArticlePolicy', false)) {
        class_alias(HostArticlePolicy::class, 'Staff\\Policies\\ArticlePolicy');
    }

    finCodexRegisterPolicies();

    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(HostArticlePolicy::class);
});

it('closes the resource while a deny-all policy is registered', function (): void {
    $this->usesPanel('admin', finCodexPolicyUser());

    expect(ArticleResource::canViewAny())->toBeTrue();

    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    expect(ArticleResource::canViewAny())->toBeFalse();
});
