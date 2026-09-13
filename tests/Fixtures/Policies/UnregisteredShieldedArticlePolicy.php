<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Policies;

use FinityLabs\FinCodex\Tests\Fixtures\Shield\ShieldStub;

/**
 * Shield is installed, but it has no permission for this ability — the state a
 * host upgraded from an earlier fin-codex is in until it re-runs the installer,
 * because its Shield entry still lists the eight abilities that existed before
 * viewAllPanels did.
 *
 * Same two seams as the parent; the difference is only that the map has no
 * entry under the action, so shieldPermission() answers null. The scope must
 * stay on for that host rather than opening up on a permission nobody has.
 */
final class UnregisteredShieldedArticlePolicy extends ShieldedArticlePolicy
{
    protected function shieldPermission(string $ability): ?string
    {
        return ShieldStub::getResourcePermissionsWithoutViewAllPanels()[$ability] ?? null;
    }
}
