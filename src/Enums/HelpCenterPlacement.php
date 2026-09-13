<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Enums;

/**
 * Where a panel's Help Center is reachable from.
 *
 * UI-only placement; string-backed because it is never stored, the same rule
 * NavigationGroup follows. Four named states rather than two booleans, so
 * "both" and "neither" are things a host can say instead of things the reader
 * has to infer from a pair of flags.
 *
 * None withholds the two menu entries and nothing else: the page stays
 * registered and its route stays live, so the drawer footer, the field hints,
 * the global search rows and a bookmark all still reach it.
 *
 * No HasLabel: NavigationGroup implements it because Filament renders the
 * group name, but a placement is never displayed.
 */
enum HelpCenterPlacement: string
{
    case UserMenu = 'user_menu';

    case Navigation = 'navigation';

    case Both = 'both';

    case None = 'none';

    public function inUserMenu(): bool
    {
        return $this === self::UserMenu || $this === self::Both;
    }

    public function inNavigation(): bool
    {
        return $this === self::Navigation || $this === self::Both;
    }
}
