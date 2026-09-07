<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Search;

use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;

/**
 * A stand-in for a host's own global search provider: one category with one
 * result, so a test can prove that the wrapper delegates and that the Help
 * category is appended after whatever the host produced.
 */
final class HostGlobalSearchProvider implements GlobalSearchProvider
{
    public const CATEGORY = 'Shop';

    public function getResults(string $query): ?GlobalSearchResults
    {
        return GlobalSearchResults::make()->category(self::CATEGORY, [
            new GlobalSearchResult('Shop result for '.$query, '/shop'),
        ]);
    }
}
