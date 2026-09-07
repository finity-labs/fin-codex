<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use FinityLabs\FinCodex\FinCodexServiceProvider;
use FinityLabs\FinCodex\Tests\Fixtures\AdminPanelProvider;
use FinityLabs\FinCodex\Tests\Fixtures\PlainPanelProvider;
use FinityLabs\FinCodex\Tests\Fixtures\PortalPanelProvider;
use FinityLabs\FinCodex\Tests\Fixtures\StaffPanelProvider;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\LinCodexServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;

/**
 * Package test harness: real Filament panels over lin-codex.
 *
 * Testbench runs no package discovery, so every provider is listed, Filament's
 * before Livewire's (Filament rebinds Livewire's DataStore; Livewire's own
 * registration must come last to make it a shared singleton). The schema is
 * created in defineDatabaseMigrations(), after the application boots, so the
 * listeners lin-codex's models register in booted() survive; clearBootedModels()
 * in setUp() re-arms them as belt and braces (fin-mail's lesson).
 *
 * Four fixture panels are registered: admin (default, web guard) and staff
 * (staff guard) carry FinCodexPlugin with distinct option values; portal (web
 * guard, no topbar, no dark mode) carries the plugin with every default; plain
 * carries no plugin. Panel pages are requested one panel per test:
 * FilamentManager is scoped and boots only the first panel of a PHP request
 * cycle.
 */
class TestCase extends Orchestra
{
    /**
     * lin-codex's schema migrations in dependency order (mirrors lin-codex
     * tests/TestCase.php PACKAGE_MIGRATIONS and the provider's hasMigrations()).
     *
     * @var list<string>
     */
    public const PACKAGE_MIGRATIONS = [
        'create_codex_articles_table',
        'create_codex_article_translations_table',
        'create_codex_article_contexts_table',
        'create_codex_article_revisions_table',
        'create_codex_media_table',
    ];

    /**
     * The whole suite runs under strict models: the editor's write path has
     * to survive them, and a Phase 3/4 path that lazy-loads a relation on a
     * collection or silently discards an attribute shows up here instead of
     * in a host application.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Model::clearBootedModels();
        Model::shouldBeStrict();
    }

    /**
     * Filament before Livewire (DataStore rebinding); Notifications is required
     * by the base layout, Widgets is included for realism. Then Livewire,
     * settings, lin-codex, fin-codex and the fixture panels.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            LaravelSettingsServiceProvider::class,
            LinCodexServiceProvider::class,
            FinCodexServiceProvider::class,
            AdminPanelProvider::class,
            StaffPanelProvider::class,
            PlainPanelProvider::class,
            PortalPanelProvider::class,
        ];
    }

    /**
     * In-memory SQLite only; the fixture User is the auth model and the staff
     * guard is a second session guard over the same users provider.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    }

    /**
     * Runs after the providers boot, so model listeners registered during the
     * migrations survive. Host tables first (users, settings), then lin-codex's
     * migrations by include()->up() in dependency order, then the settings seed.
     * The users table carries a nullable password column because Laravel's
     * AuthenticateSession middleware reads getAuthPassword() on every panel
     * request, and a remember_token because SessionGuard::logout() writes
     * one; strict models turn either missing attribute into a
     * MissingAttributeException.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['group', 'name']);
        });

        $database = dirname(__DIR__).'/vendor/finity-labs/lin-codex/database';

        foreach (self::PACKAGE_MIGRATIONS as $file) {
            (include $database.'/migrations/'.$file.'.php')->up();
        }

        (include $database.'/settings/create_codex_settings.php')->up();
    }

    /**
     * Make $id the current, serving panel and sign $user in on that panel's
     * guard. For Livewire::test() and unit-style tests; HTTP request tests get
     * their current panel from Filament's SetUpPanel middleware. The panel is
     * booted here as SetUpPanel would on an HTTP request, so its render hooks
     * are flushed and Livewire::test() of a panel component (the Topbar)
     * renders them.
     */
    protected function usesPanel(string $id, ?Authenticatable $user = null): Panel
    {
        $panel = Filament::getPanel($id);

        Filament::setCurrentPanel($panel);
        Filament::setServingStatus();
        Filament::bootCurrentPanel();

        if ($user !== null) {
            $this->actingAs($user, $panel->getAuthGuard());
        }

        return $panel;
    }
}
