<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Pages;

use FinityLabs\FinCodex\Pages\HelpSettings;

/**
 * The staff panel's own settings page. A second, distinct subclass so the
 * plugin-option rows can keep asserting that every option differs between the
 * admin and the staff panel.
 */
final class StaffHelpSettings extends HelpSettings {}
