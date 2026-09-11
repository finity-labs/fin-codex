<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The harness User keyed by a string UUID, as apps using HasUuids ship it.
 * Same shape as User for the same reasons: FilamentUser for the Authenticate
 * middleware, Notifiable for the translation listener's database
 * notification, and the two null attributes strict models need present.
 */
final class UuidUser extends Authenticatable implements FilamentUser
{
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['password' => null, 'remember_token' => null];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
