<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Panel-capable user for the harness. Filament's Authenticate middleware
 * answers 403 for a user that is not a FilamentUser outside app.env=local,
 * so the fixture implements the contract; no $fillable guard so ::create()
 * works with plain arrays.
 *
 * password and remember_token default to null so the attributes are always
 * present: Laravel's AuthenticateSession middleware reads getAuthPassword()
 * on every request and SessionGuard::logout() writes the remember token,
 * actingAs() clears wasRecentlyCreated on the signed-in instance, and strict
 * models throw for an attribute a retrieved model does not carry.
 *
 * Notifiable because a real host's user model is, and the listener's database
 * notification needs notifyNow() and the notifications() relation on the
 * fixture; the base Foundation user carries neither.
 */
final class User extends Authenticatable implements FilamentUser
{
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
