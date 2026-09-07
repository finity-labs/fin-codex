<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Auth;

use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;

/**
 * The one place that knows fin-codex's three extra abilities and what each
 * falls back to when the registered policy has never heard of it.
 *
 * A host should not have to learn our vocabulary to keep the editor working.
 * Write the five standard methods and restore, convert and import keep
 * answering — as the article's update or create ability, which is what all
 * three really are. Write them and yours wins, in both directions: the
 * fallback fills a missing method, it never overturns a no.
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
     * @var array<string, string>
     */
    private const FALLBACKS = [
        'restore' => 'update',
        'import' => 'create',
        'convert' => 'update',
    ];

    /**
     * Whether the current user may do $ability to $subject — an article, or
     * the Article class itself for the checks that come before a record
     * exists.
     */
    public static function allows(string $ability, Article|string $subject = Article::class): bool
    {
        $policy = Gate::getPolicyFor(is_string($subject) ? $subject : $subject::class);

        if ($policy !== null && method_exists($policy, $ability)) {
            return Gate::allows($ability, $subject);
        }

        return Gate::allows(self::FALLBACKS[$ability] ?? $ability, $subject);
    }
}
