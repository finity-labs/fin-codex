<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Pages;

use FinityLabs\FinCodex\Pages\HelpCenter;

/**
 * What a host's own Help Center looks like: a subclass, nothing more. A panel
 * names it through helpCenterPage(), so FinCodexPlugin::register() registers
 * this class instead of the package one.
 *
 * It has to be a real class: routes/web.php calls $page::registerRoutes($panel)
 * statically, so a fake class name on a registered page is a fatal error, not a
 * comparison. Deliberately NOT added to any fixture panel provider — a registry
 * change would move what every all-panels test counts, so the override rows
 * build a throwaway panel instead.
 */
final class AdminHelpCenter extends HelpCenter {}
