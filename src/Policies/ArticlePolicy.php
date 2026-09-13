<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Policies;

use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Contracts\Auth\Access\Authorizable;
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
 * A host policy that stops at the five standard abilities still works. Three
 * of the four custom ones resolve through Auth\ArticleAbility, which falls
 * back to update (restore, convert) or create (import) when the registered
 * policy has no method for them. The fourth, viewAllPanels, has nothing to
 * fall back to and answers no when the registered policy has no method for it:
 * lifting the panel scope is a grant a host writes down rather than inherits.
 *
 * viewAllPanels is also the one ability this class does not answer for itself.
 * It is off by default, and on a host with Filament Shield it follows the
 * permission Shield generated for it — see the method for how the name is
 * found and for the two other ways a host says yes.
 */
class ArticlePolicy
{
    /**
     * Filament Shield's own container binding, which is also what its facade
     * resolves. A string, never a class: Shield is optional and this package
     * names none of its classes anywhere.
     */
    private const SHIELD_BINDING = 'filament-shield';

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

    /**
     * Whether the user may read every panel's help from inside one panel.
     *
     * Closed unless something says otherwise. The panel scope
     * (Scope\PanelScopeGate) shows a viewer general articles plus the current
     * panel's own, and this is the one ability that lifts it, per viewer.
     *
     * On a host with Filament Shield, the answer is the permission Shield
     * generated for this ability on the article resource — the row
     * fin-codex:install creates and an admin ticks on a role. Nothing else
     * changes: no Shield means no permission to read and so no lift, and a
     * Shield config that has never heard of the ability means the same.
     *
     * Two other ways to say yes are untouched. A host writing its own class at
     * {policyNamespace}\ArticlePolicy replaces this method outright, and a
     * Gate::before callback (Shield's super admin, when the host defines it
     * through the gate) still runs before any policy is asked.
     *
     * A class-level check like import(): the user alone, no record — and no
     * standard ability to fall back to either, so a policy without the method
     * answers no rather than inheriting viewAny.
     */
    public function viewAllPanels(Authenticatable $user): bool
    {
        $permission = $this->shieldPermission('viewAllPanels');

        return $permission !== null
            && $user instanceof Authorizable
            && $user->can($permission);
    }

    /**
     * Whether Filament Shield is installed.
     *
     * Answered by the container binding Shield registers, which is the thing
     * shieldPermission() goes on to resolve — so the question asked is exactly
     * the one that matters, and no vendor class name appears in this package.
     * Shield is never a hard dependency. Overridable alongside
     * shieldPermission(), which is how the tests prove this branch without
     * putting Shield in the class map.
     */
    protected function isShieldAvailable(): bool
    {
        return app()->bound(self::SHIELD_BINDING);
    }

    /**
     * What Shield calls the permission for one of the article resource's policy
     * actions, or null when there is no Shield or no permission for it.
     *
     * The name is asked for rather than built. Shield 4 makes the separator and
     * the case configurable and lets a host replace the key builder outright, so
     * any name spelled out here would be wrong for somebody — the same reason
     * fin-support's page trait asks Shield for a page's key. Shield is reached
     * through its container binding, which is a plain string, and both the
     * object and the method are checked before either is used.
     */
    protected function shieldPermission(string $ability): ?string
    {
        if (! $this->isShieldAvailable()) {
            return null;
        }

        $shield = app(self::SHIELD_BINDING);

        if (! is_object($shield) || ! method_exists($shield, 'getResourcePolicyActionsWithPermissions')) {
            return null;
        }

        $permissions = $shield->getResourcePolicyActionsWithPermissions(ArticleResource::class);
        $permission = is_array($permissions) ? ($permissions[$ability] ?? null) : null;

        return is_string($permission) ? $permission : null;
    }
}
