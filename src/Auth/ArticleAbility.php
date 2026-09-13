<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Auth;

use FinityLabs\LinCodex\Models\Article;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The one place that knows fin-codex's custom abilities and what each falls
 * back to when the registered policy has never heard of it.
 *
 * A host should not have to learn our vocabulary to keep the editor working.
 * Write the five standard methods and restore, convert and import keep
 * answering — as the article's update or create ability, which is what all
 * three really are. Write them and yours wins, in both directions: the
 * fallback fills a missing method, it never overturns a no.
 *
 * viewAllPanels is the fourth custom ability and the one with no fallback: it
 * asks whether a viewer may read every panel's help from inside one panel,
 * which no standard ability is a stand-in for, so a policy without the method
 * answers no.
 *
 * "Missing method" is a real signal rather than a guess. Laravel's Gate
 * returns null (not false) for an ability its policy has no method for, so
 * method_exists() on the resolved policy asks exactly the right question.
 *
 * import falls back to create, not update, deviating from the phase's original
 * "everything falls back to update" wording. The import check happens at class
 * level, with no article in hand, and Laravel drops a class-string subject
 * before calling the policy method — so a normally-written
 * update($user, Article $article) receives one argument and raises an
 * ArgumentCountError instead of answering. create() takes the user alone and
 * is the honest question anyway: adopting a file article creates a database
 * row.
 *
 * A Gate::after() callback would cover host code calling Gate::allows()
 * directly, and it was rejected for it: it is global state, it runs on every
 * gate check in the application, and it still could not special-case the
 * class-string subject that import needs.
 */
final class ArticleAbility
{
    /**
     * Our abilities and the standard one each resolves to.
     *
     * An ability absent from this map — viewAllPanels is the only one today —
     * is asked of the Gate under its own name, which Laravel answers false for
     * when the registered policy has no method for it. That is the intended
     * behaviour, not an oversight: a host policy without viewAllPanels denies
     * rather than inheriting viewAny. A host Gate::before callback (Shield's
     * super_admin) still runs first and can say yes.
     *
     * @var array<string, string>
     */
    private const FALLBACKS = [
        'restore' => 'update',
        'import' => 'create',
        'convert' => 'update',
    ];

    /**
     * Whether $user may do $ability to $subject — an article, or the Article
     * class itself for the checks that come before a record exists.
     *
     * $user is for callers holding a viewer the core already resolved on a
     * panel guard (the panel scope gate): the Gate's own resolver reads the
     * default guard, which is not necessarily the one that answered for the
     * panel. The two-argument form keeps asking that default resolver, which
     * is what every call from inside the editor wants.
     */
    public static function allows(string $ability, Article|string $subject = Article::class, ?Authenticatable $user = null): bool
    {
        /** @var GateContract $gate */
        $gate = $user === null ? Gate::getFacadeRoot() : Gate::forUser($user);

        $policy = $gate->getPolicyFor(is_string($subject) ? $subject : $subject::class);

        if ($policy !== null && method_exists($policy, $ability)) {
            return $gate->allows($ability, $subject);
        }

        return $gate->allows(self::FALLBACKS[$ability] ?? $ability, $subject);
    }
}
