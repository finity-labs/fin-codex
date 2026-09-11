<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests;

use FinityLabs\FinCodex\Tests\Fixtures\UuidUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boots the panels against a users table keyed by a UUID string.
 *
 * The user model is set in defineEnvironment(), which runs before
 * defineDatabaseMigrations(), so lin-codex's migrations size created_by,
 * updated_by, user_id and uploaded_by from it and the foreign keys hold.
 */
abstract class UuidUserTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', UuidUser::class);
    }

    protected function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
