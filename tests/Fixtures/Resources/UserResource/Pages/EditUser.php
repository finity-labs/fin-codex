<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages;

use Filament\Resources\Pages\EditRecord;
use FinityLabs\FinCodex\Help\HasHelp;
use FinityLabs\FinCodex\Help\WithHelp;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;

/**
 * The edit page of the fixture resource. In Phase 4 it declares editing-users
 * for itself alone, which becomes a route: context on its own route name.
 */
final class EditUser extends EditRecord implements HasHelp
{
    use WithHelp;

    protected static string $resource = UserResource::class;

    /** @var list<string> */
    protected static array $helpArticles = ['editing-users'];
}
