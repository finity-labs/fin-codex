<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Policies;

use FinityLabs\FinCodex\Policies\ArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\Shield\ShieldStub;

/**
 * The shipped policy as it behaves on a host that HAS Shield installed.
 *
 * Only the two protected seams are overridden — "is Shield here?" and "what
 * does Shield call this ability's permission?". Everything the rows then
 * exercise, viewAllPanels() itself included, is the shipped policy's own code.
 *
 * The seams are overridden rather than Shield's classes being stubbed into the
 * class map because class_exists() is process-global: defining
 * BezhanSalleh\FilamentShield\FilamentShieldPlugin anywhere would send every
 * later row's shipped policy down the Shield branch and make the default-closed
 * rows order-dependent. ShieldedHelpSettings does the same thing for the page
 * trait, and ShieldStub's docblock has the long version.
 *
 * Not final: UnregisteredShieldedArticlePolicy extends it to get the same two
 * seams with a Shield that carries no permission for the ability.
 */
class ShieldedArticlePolicy extends ArticlePolicy
{
    protected function isShieldAvailable(): bool
    {
        return true;
    }

    protected function shieldPermission(string $ability): ?string
    {
        return ShieldStub::getResourcePermissions()[$ability] ?? null;
    }
}
