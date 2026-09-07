<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Policies;

use FinityLabs\LinCodex\Models\Article;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The other end of the shipped policy: every ability denied.
 *
 * Registering this is how the authorization tests prove a gate is real. A row
 * that only asserts "allowed" passes just as well when nothing is being
 * checked at all, so each one is paired with this policy in force.
 *
 * It defines all eight methods deliberately: a denial here is an answer, not a
 * missing method, so Auth\ArticleAbility's fallback must not rescue it.
 */
class DenyAllArticlePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return false;
    }

    public function view(Authenticatable $user, Article $article): bool
    {
        return false;
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, Article $article): bool
    {
        return false;
    }

    public function restore(Authenticatable $user, Article $article): bool
    {
        return false;
    }

    public function import(Authenticatable $user): bool
    {
        return false;
    }

    public function convert(Authenticatable $user, Article $article): bool
    {
        return false;
    }
}
