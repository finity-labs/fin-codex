<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Enums;

use Filament\Support\Contracts\HasLabel;

/** UI-only navigation group; string-backed because it is never stored. */
enum NavigationGroup: string implements HasLabel
{
    case Help = 'help';

    public function getLabel(): string
    {
        return (string) __('fin-codex::fin-codex.navigation.group');
    }
}
