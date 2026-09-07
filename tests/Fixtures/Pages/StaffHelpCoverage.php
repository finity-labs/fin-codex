<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Pages;

use FinityLabs\FinCodex\Pages\HelpCoverage;

/**
 * The staff panel's own coverage page. A second, distinct subclass so the
 * plugin-option rows can keep asserting that every option differs between the
 * admin and the staff panel.
 */
final class StaffHelpCoverage extends HelpCoverage {}
