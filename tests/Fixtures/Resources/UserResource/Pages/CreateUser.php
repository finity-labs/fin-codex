<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;

/**
 * The create page of the fixture resource.
 */
final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
