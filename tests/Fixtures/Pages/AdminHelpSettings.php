<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Pages;

use FinityLabs\FinCodex\Pages\HelpSettings;

/**
 * What a host's own settings page looks like: a subclass, nothing more. The
 * admin panel names it through settingsPage(), so FinCodexPlugin::register()
 * registers this class instead of the package one.
 *
 * It has to be a real class: routes/web.php calls $page::registerRoutes($panel)
 * and NavigationManager calls $page::registerNavigationItems() statically, so a
 * fake class name on a registered page is a fatal error, not a comparison.
 */
final class AdminHelpSettings extends HelpSettings {}
