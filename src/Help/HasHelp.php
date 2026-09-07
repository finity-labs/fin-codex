<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Help;

/**
 * Implemented by a Filament resource, resource page or custom page that wants
 * articles attached in code. Slugs come back best first and may differ per
 * panel; an empty list means nothing is declared here, stored contexts still
 * apply. Use the WithHelp trait for the common property-backed case.
 */
interface HasHelp
{
    /**
     * @return list<string> article slugs, best first; [] means nothing is declared for this panel
     */
    public static function getHelpArticles(string $panelId): array;
}
