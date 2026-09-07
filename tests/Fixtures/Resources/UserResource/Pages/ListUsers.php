<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages;

use Filament\Resources\Pages\ListRecords;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;

/**
 * The list page of the fixture resource.
 */
final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
