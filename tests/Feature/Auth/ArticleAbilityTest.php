<?php

use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/*
 * AUTH-01, second half: restore, import and convert against a host policy that
 * never heard of them.
 *
 * Laravel answers null — not false — when a policy exists but has no method
 * for the ability, which is what lets the helper tell "the host did not write
 * this one" apart from "the host said no". The first falls back to a standard
 * ability, the second is final.
 *
 * import falls back to create, not update: it is checked with no record in
 * hand, and Laravel drops a class-string subject before calling the policy, so
 * a two-parameter update() would be an ArgumentCountError instead of an
 * answer.
 */

/** Every ability, all of them granted. */
class FinCodexFullArticlePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function restore(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function import(Authenticatable $user): bool
    {
        return true;
    }

    public function convert(Authenticatable $user, Article $article): bool
    {
        return true;
    }
}

/**
 * A host that knows our vocabulary and says no to one word of it: update is
 * granted, restore is refused. The fallback must not touch this.
 */
class FinCodexRestoreDeniedPolicy extends FinCodexFullArticlePolicy
{
    public function restore(Authenticatable $user, Article $article): bool
    {
        return false;
    }
}

/** The policy a host actually writes: the five standard abilities, granted. */
class FinCodexStandardArticlePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, Article $article): bool
    {
        return true;
    }
}

/** The same five abilities, all refused — the fallback's other direction. */
class FinCodexStandardDenyPolicy extends FinCodexStandardArticlePolicy
{
    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return false;
    }
}

/** Sign a user in on the default guard, which is the admin panel's. */
function finCodexAbilityUser(): User
{
    $user = User::create(['name' => 'Ability', 'email' => 'ability@example.com']);

    test()->actingAs($user);

    return $user;
}

/**
 * Drop every registered policy, including the one the provider registered at
 * boot.
 *
 * Laravel's Gate has no public way to unmap a model, and the "no policy at
 * all" row is worth keeping: it is what a host gets after calling
 * Gate::policy() with something of its own that it later removes.
 */
function finCodexForgetPolicies(): void
{
    $gate = Gate::getFacadeRoot();

    (new ReflectionProperty($gate, 'policies'))->setValue($gate, []);
}

it('mirrors a policy that defines the custom ability', function (string $policy, bool $expected): void {
    finCodexAbilityUser();
    $article = Article::factory()->create();

    Gate::policy(Article::class, $policy);

    expect(ArticleAbility::allows('restore', $article))->toBe($expected);
})->with([
    'granted' => [FinCodexFullArticlePolicy::class, true],
    'refused' => [DenyAllArticlePolicy::class, false],
]);

it('never rescues a denial the policy actually wrote', function (): void {
    finCodexAbilityUser();
    $article = Article::factory()->create();

    Gate::policy(Article::class, FinCodexRestoreDeniedPolicy::class);

    expect(ArticleAbility::allows('update', $article))->toBeTrue()
        ->and(ArticleAbility::allows('restore', $article))->toBeFalse();
});

it('falls back to the standard ability the record-taking customs belong to', function (string $ability): void {
    finCodexAbilityUser();
    $article = Article::factory()->create();

    Gate::policy(Article::class, FinCodexStandardArticlePolicy::class);
    expect(ArticleAbility::allows($ability, $article))->toBeTrue();

    Gate::policy(Article::class, FinCodexStandardDenyPolicy::class);
    expect(ArticleAbility::allows($ability, $article))->toBeFalse();
})->with(['restore', 'convert']);

it('falls back to create for the class-level import check', function (): void {
    finCodexAbilityUser();

    Gate::policy(Article::class, FinCodexStandardArticlePolicy::class);
    expect(ArticleAbility::allows('import'))->toBeTrue();

    Gate::policy(Article::class, FinCodexStandardDenyPolicy::class);
    expect(ArticleAbility::allows('import'))->toBeFalse();
});

it('passes a standard ability straight through', function (): void {
    finCodexAbilityUser();
    $article = Article::factory()->create();

    Gate::policy(Article::class, FinCodexStandardArticlePolicy::class);
    expect(ArticleAbility::allows('update', $article))->toBe(Gate::allows('update', $article));

    Gate::policy(Article::class, FinCodexStandardDenyPolicy::class);
    expect(ArticleAbility::allows('update', $article))->toBe(Gate::allows('update', $article));
});

it('answers a bool with no policy registered at all', function (): void {
    finCodexAbilityUser();
    $article = Article::factory()->create();

    finCodexForgetPolicies();

    expect(Gate::getPolicyFor(Article::class))->toBeNull()
        ->and(ArticleAbility::allows('restore', $article))->toBeFalse()
        ->and(ArticleAbility::allows('import'))->toBeFalse();
});
