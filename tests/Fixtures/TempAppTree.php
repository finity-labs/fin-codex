<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures;

use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

/**
 * The throwaway host application the install and uninstall command tests run
 * against.
 *
 * Both commands do string surgery on files they find through app_path() and
 * config_path(), so proving them needs real files at those paths rather than a
 * mock: the Testbench skeleton has an app/Providers directory but no
 * app/Providers/Filament and no config/filament-shield.php, so everything this
 * class writes is something it created and can safely delete again.
 *
 * It is a class rather than a set of Pest helpers because Pest helpers are
 * global functions: two files needing the same helper would either redeclare
 * it fatally in a full run or duplicate it.
 */
final class TempAppTree
{
    /**
     * A host panel provider with no ->plugins([]) block, which is the shape a
     * fresh `php artisan filament:install --panels` writes and the harder of
     * the two insertion paths.
     */
    public const PANEL_PROVIDER = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;

class {{class}} extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('{{id}}')
            ->path('{{id}}')
            ->login()
            ->middleware([
                'web',
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
PHP;

    /**
     * The same provider with an existing, populated plugins block: the other
     * insertion path, and the one where a second registration must be refused
     * rather than appended.
     */
    public const PANEL_PROVIDER_WITH_PLUGINS = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;

class {{class}} extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('{{id}}')
            ->path('{{id}}')
            ->login()
            ->plugins([
                SomeOtherPlugin::make(),
            ])
            ->middleware([
                'web',
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
PHP;

    /**
     * A minimal Shield 4 config: only the resources.manage array the install
     * writes into, in the shape the shipped config uses. Keeping a fixture
     * here is what lets the Shield-present branch be proven without adding
     * bezhansalleh/filament-shield to require-dev.
     */
    public const SHIELD_CONFIG = <<<'PHP'
<?php

return [
    'resources' => [
        'subject' => 'model',
        'manage' => [
            \App\Filament\Resources\Users\UserResource::class => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
            ],
        ],
    ],

    'pages' => [
        'prefix' => 'view',
        'exclude' => [
            \Filament\Pages\Dashboard::class,
        ],
    ],

    'policies' => [
        'path' => app_path('Policies'),
        'merge' => true,
    ],
];
PHP;

    public static function providersDirectory(): string
    {
        return app_path('Providers/Filament');
    }

    public static function panelProviderPath(string $panelId = 'admin'): string
    {
        return self::providersDirectory().'/'.Str::studly($panelId).'PanelProvider.php';
    }

    public static function shieldConfigPath(): string
    {
        return config_path('filament-shield.php');
    }

    /**
     * Write a panel provider for $panelId and return its path.
     */
    public static function writePanelProvider(string $panelId = 'admin', ?string $stub = null): string
    {
        if (! is_dir(self::providersDirectory())) {
            mkdir(self::providersDirectory(), 0777, true);
        }

        $path = self::panelProviderPath($panelId);

        file_put_contents($path, str_replace(
            ['{{class}}', '{{id}}'],
            [Str::studly($panelId).'PanelProvider', $panelId],
            $stub ?? self::PANEL_PROVIDER,
        ));

        return $path;
    }

    public static function writeShieldConfig(): string
    {
        file_put_contents(self::shieldConfigPath(), self::SHIELD_CONFIG);

        return self::shieldConfigPath();
    }

    /**
     * Remove everything this class could have written. Safe to call when
     * nothing was written.
     */
    public static function cleanup(): void
    {
        if (file_exists(self::shieldConfigPath())) {
            unlink(self::shieldConfigPath());
        }

        if (! is_dir(self::providersDirectory())) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::providersDirectory(), RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir(self::providersDirectory());
    }

    /**
     * php -l on the rewritten file. Both commands cut and splice PHP source,
     * so "the plugin line is there" is only half the proof — the file also has
     * to still parse.
     */
    public static function lints(string $path): bool
    {
        $process = new Process([PHP_BINARY, '-l', $path]);
        $process->run();

        return $process->isSuccessful();
    }
}
