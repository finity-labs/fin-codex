<?php

use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\ViewAllPanelsArticlePolicy;
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

/**
 * One person may read every panel's help, and that same person is the only one
 * who may write an article at all: the rows that hand an explicit user in ask
 * this policy about somebody who is not the one signed in.
 *
 * It stops at the five standard abilities otherwise, so import still has to
 * find its way to create — the user parameter and the fallback have to
 * compose.
 */
class FinCodexOneUserArticlePolicy extends FinCodexStandardArticlePolicy
{
    public function create(Authenticatable $user): bool
    {
        return $user->email === 'all@example.com';
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return $user->email === 'all@example.com';
    }

    public function viewAllPanels(Authenticatable $user): bool
    {
        return $user->email === 'all@example.com';
    }
}

/** Sign a user in on the default guard, which is the admin panel's. */
function finCodexAbilityUser(string $email = 'ability@example.com'): User
{
    $user = User::create(['name' => 'Ability', 'email' => $email]);

    test()->actingAs($user);

    return $user;
}

/** A user nobody signs in; the explicit-user rows ask the Gate about this one. */
function finCodexLiftedUser(): User
{
    return User::create(['name' => 'All panels', 'email' => 'all@example.com']);
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

/*
 * viewAllPanels is the exception to the paragraph above: it has no standard
 * ability behind it. A policy that never heard of it answers no, however
 * generous it is about everything else, because lifting the panel scope is a
 * decision the host has to write down rather than inherit.
 */
it('never falls back to a standard ability for viewAllPanels', function (string $policy, bool $expected): void {
    finCodexAbilityUser();

    Gate::policy(Article::class, $policy);

    expect(ArticleAbility::allows('viewAllPanels'))->toBe($expected);
})->with([
    'the five standard abilities, granted' => [FinCodexStandardArticlePolicy::class, false],
    'every ability we ship, granted' => [FinCodexFullArticlePolicy::class, false],
    'deny-all' => [DenyAllArticlePolicy::class, false],
    'a host that wrote the method' => [ViewAllPanelsArticlePolicy::class, true],
]);

it('asks the Gate about the user it is given, not the one signed in', function (): void {
    $lifted = finCodexLiftedUser();
    finCodexAbilityUser();

    Gate::policy(Article::class, FinCodexOneUserArticlePolicy::class);

    expect(ArticleAbility::allows('viewAllPanels', Article::class, $lifted))->toBeTrue()
        ->and(ArticleAbility::allows('viewAllPanels'))->toBeFalse();
});

it('resolves a standard ability for the user it is given', function (): void {
    $lifted = finCodexLiftedUser();
    $signedIn = finCodexAbilityUser();
    $article = Article::factory()->create();

    Gate::policy(Article::class, FinCodexOneUserArticlePolicy::class);

    expect(ArticleAbility::allows('update', $article, $lifted))->toBeTrue()
        ->and(ArticleAbility::allows('update', $article, $signedIn))->toBeFalse()
        ->and(ArticleAbility::allows('update', $article))->toBeFalse();
});

it('falls back for the user it is given', function (): void {
    $lifted = finCodexLiftedUser();
    finCodexAbilityUser();

    Gate::policy(Article::class, FinCodexOneUserArticlePolicy::class);

    expect(ArticleAbility::allows('import', Article::class, $lifted))->toBeTrue()
        ->and(ArticleAbility::allows('import'))->toBeFalse();
});

it('answers a bool with no policy registered at all', function (): void {
    finCodexAbilityUser();
    $article = Article::factory()->create();

    finCodexForgetPolicies();

    expect(Gate::getPolicyFor(Article::class))->toBeNull()
        ->and(ArticleAbility::allows('restore', $article))->toBeFalse()
        ->and(ArticleAbility::allows('import'))->toBeFalse();
});
