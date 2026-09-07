<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Pages;

use Filament\Pages\Page;
use FinityLabs\FinCodex\Help\HasHelp;
use FinityLabs\FinCodex\Help\WithHelp;

/**
 * A custom page on the app layout. Phase 3 identifies it by its own class,
 * unlike resource pages. In Phase 4 it declares reports, which becomes a
 * class: context on this class in every panel that registers it.
 */
final class Reports extends Page implements HasHelp
{
    use WithHelp;

    protected static ?string $slug = 'reports';

    /** @var list<string> */
    protected static array $helpArticles = ['reports'];
}
