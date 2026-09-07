<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Policies;

use FinityLabs\LinCodex\Models\Article;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default answers for lin-codex's Article: yes to any authenticated panel
 * user.
 *
 * fin-codex ships a policy where fin-mail only maps one, because a panel with
 * strictAuthorization() throws rather than denies when a model has no policy
 * at all — a fresh install would 500 on the article list instead of rendering
 * it. Open by default keeps that install working and matches what every panel
 * without a policy already does.
 *
 * A host tightens by writing its own class at {policyNamespace}\ArticlePolicy
 * (App\Policies\ArticlePolicy unless the plugin was told otherwise), which
 * FinCodexServiceProvider::registerPolicies() registers instead of this one.
 * Extending this class is the shortest way there — it is not final and none of
 * its methods are static. Editing this file is not: an update overwrites it.
 *
 * A host policy that stops at the five standard abilities still works. The
 * three custom ones resolve through Auth\ArticleAbility, which falls back to
 * update (restore, convert) or create (import) when the registered policy has
 * no method for them.
 */
class ArticlePolicy
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

    /**
     * Rolling the article back to one of its revisions. Not soft-delete
     * restore — Article has no soft deletes.
     */
    public function restore(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    /**
     * Adopting a file article into the database.
     *
     * Takes the user alone, and must keep doing so: the check happens at class
     * level with no record in hand, and Laravel drops a class-string subject
     * before calling the policy method, so a second parameter here is an
     * ArgumentCountError rather than a denial.
     */
    public function import(Authenticatable $user): bool
    {
        return true;
    }

    /** Rewriting an HTML article's body as Markdown. */
    public function convert(Authenticatable $user, Article $article): bool
    {
        return true;
    }
}
