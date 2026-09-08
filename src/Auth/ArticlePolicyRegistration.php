<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Auth;

use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Policies\ArticlePolicy;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Registers the policy for lin-codex's Article: the host's class at
 * {namespace}\ArticlePolicy when it exists, the shipped one otherwise.
 *
 * It runs twice on purpose. FinCodexServiceProvider::packageBooted() calls it
 * with the best namespace it can find while no panel is current (the default
 * panel's, or App\Policies), so console commands, queue workers and requests
 * outside any panel have a policy. FinCodexPlugin::boot() calls it again when
 * a panel boots for a request, with that panel's own namespace. Filament boots
 * the current panel from the SetUpPanel middleware, after every provider has
 * booted, so the second call is what makes policyNamespace() a per-panel
 * option rather than one only the default panel can set.
 *
 * The explicit registration is not optional: Gate::guessPolicyName() walks the
 * model's own namespace, so for FinityLabs\LinCodex\Models\Article it never
 * tries App\Policies or FinityLabs\FinCodex\Policies.
 */
final class ArticlePolicyRegistration
{
    public const DEFAULT_NAMESPACE = 'App\\Policies';

    /**
     * @return class-string the policy now registered for Article
     */
    public static function register(string $namespace): string
    {
        $host = rtrim($namespace, '\\').'\\ArticlePolicy';
        $policy = class_exists($host) ? $host : ArticlePolicy::class;

        Gate::policy(Article::class, $policy);

        return $policy;
    }

    /**
     * The namespace to use while no panel is current: the default panel's
     * option when the plugin is on it, App\Policies otherwise.
     */
    public static function defaultNamespace(): string
    {
        try {
            return FinCodexPlugin::get()->getPolicyNamespace();
        } catch (Throwable) {
            return self::DEFAULT_NAMESPACE;
        }
    }
}
