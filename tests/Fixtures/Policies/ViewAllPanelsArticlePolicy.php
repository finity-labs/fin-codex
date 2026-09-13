<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Policies;

use FinityLabs\FinCodex\Policies\ArticlePolicy;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A host that lifts the panel scope: everything the shipped policy answers,
 * plus a yes to viewAllPanels.
 *
 * This is the shape a real application writes at {policyNamespace}\ArticlePolicy
 * when its support staff should read every panel's help from wherever they
 * happen to be. The ability rows use it to prove the override wins, and the
 * panel scope tests use it to prove an allowed viewer sees the unscoped tree.
 */
class ViewAllPanelsArticlePolicy extends ArticlePolicy
{
    public function viewAllPanels(Authenticatable $user): bool
    {
        return true;
    }
}
