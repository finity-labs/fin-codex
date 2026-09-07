<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Pages;

use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Shield\ShieldStub;

/**
 * The settings page as it behaves on a host that HAS Shield installed.
 *
 * Only the trait's two protected seams are overridden — "is Shield here?" and
 * "what does Shield call this page's permission?". Everything the test then
 * exercises, canAccess() and shouldRegisterNavigation() included, is the
 * shipped trait's own code running against the shipped page.
 *
 * The seams are overridden rather than Shield's classes being stubbed into the
 * class map (08-CONTEXT Amendment 3's literal shape) because class_exists() is
 * process-global: defining BezhanSalleh\FilamentShield\FilamentShieldPlugin
 * anywhere would send EVERY later test's HelpSettings down the Shield branch
 * and make the Gate-fallback rows order-dependent. ShieldStub's docblock has
 * the long version.
 *
 * getPagePermission() deliberately does NOT memoise into the trait's
 * $pagePermissionKey. A static property declared in a trait is shared with
 * subclasses, so writing it here would also answer for the real HelpSettings.
 *
 * Not final: UnregisteredShieldedHelpSettings extends it to get the same two
 * seams with a class Shield has no permission for.
 */
class ShieldedHelpSettings extends HelpSettings
{
    protected static function isShieldAvailable(): bool
    {
        return true;
    }

    protected static function getPagePermission(): ?string
    {
        $page = ShieldStub::getPages()[static::class] ?? null;

        return $page ? array_key_first($page['permissions']) : null;
    }
}
