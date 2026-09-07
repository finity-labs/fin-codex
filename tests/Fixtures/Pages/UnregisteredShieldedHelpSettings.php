<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Pages;

/**
 * Shield is installed, but it has no permission registered for this page —
 * the state a host is in between adding a page and running shield:generate.
 *
 * Same two seams as the parent; the difference is only that ShieldStub's map
 * has no entry under this class name, so getPagePermission() answers null and
 * the trait falls through to parent::canAccess(). A page Shield has never
 * heard of must stay reachable, not lock everybody out.
 */
final class UnregisteredShieldedHelpSettings extends ShieldedHelpSettings {}
