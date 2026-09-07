<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands;

use FinityLabs\FinCodex\Commands\Concerns\CanDeregisterPlugin;
use FinityLabs\FinCodex\Commands\Concerns\DiscoversPanelProviders;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Resources\ArticleResource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * fin-codex:uninstall reverses exactly what fin-codex:install did, and
 * nothing else.
 *
 * It removes the plugin registration from every panel provider that carries
 * it, drops the article resource entry from the Shield config, deletes the
 * Shield permission rows for the resource and the two pages, and offers to
 * delete the two publish groups this package has.
 *
 * It does NOT touch a codex_* table, a Codex setting, a media file or a
 * revision. All of those are lin-codex's, they survive removing the Filament
 * layer, and `codex:uninstall` is the command that removes them.
 *
 * It also leaves app/Policies/ArticlePolicy.php alone. shield:generate writes
 * that file, but a host that already owns an App\Models\Article owns a policy
 * at the very same path, and this command cannot tell the two apart.
 */
class UninstallCommand extends Command
{
    use CanDeregisterPlugin;
    use DiscoversPanelProviders;

    /**
     * Shield's facade, as a string: fin-codex does not depend on Shield and
     * must not import a class that is usually absent.
     */
    private const SHIELD_FACADE = 'BezhanSalleh\\FilamentShield\\Facades\\FilamentShield';

    protected $signature = 'fin-codex:uninstall';

    protected $description = 'Uninstall the Codex Filament plugin (run before composer remove).';

    public function handle(): int
    {
        $this->info('Uninstalling the Codex Filament plugin...');
        $this->newLine();

        $this->deregisterFromPanels();
        $this->removeShieldConfig();
        $this->cleanupPublishedViews();
        $this->cleanupPublishedTranslations();

        $this->newLine();
        $this->info('Codex Filament plugin uninstalled. You can now run: composer remove finity-labs/fin-codex');
        $this->newLine();

        $this->components->warn('Your articles, translations, contexts, revisions, media and Codex settings were NOT touched.');
        $this->line('  They belong to lin-codex and survive removing the Filament layer.');
        $this->line('  To remove those too, run: php artisan codex:uninstall');

        return self::SUCCESS;
    }

    protected function deregisterFromPanels(): void
    {
        $panelProviders = $this->discoverPanelProviders();

        if ($panelProviders === []) {
            $this->components->warn('No panel providers found. If you registered FinCodexPlugin manually, remove it before running composer remove.');

            return;
        }

        foreach ($panelProviders as $panelId => $path) {
            $content = file_get_contents($path);

            if ($content !== false && str_contains($content, 'FinCodexPlugin')) {
                $this->comment("Removing FinCodexPlugin from the {$panelId} panel...");
                $this->deregisterPlugin($path);
            }
        }
    }

    protected function removeShieldConfig(): void
    {
        $configPath = config_path('filament-shield.php');

        if (! file_exists($configPath)) {
            return;
        }

        $content = file_get_contents($configPath);

        if ($content === false || ! str_contains($content, 'FinityLabs\\FinCodex')) {
            $this->removeShieldPermissions();

            return;
        }

        $this->comment('Removing the Codex article resource from the Shield config...');

        $content = preg_replace(
            '#[ \t]*\\\\FinityLabs\\\\FinCodex\\\\[^\n]+::class\s*=>\s*\[\n(?:[ \t]+\'[^\']+\',?\n)*[ \t]*\],?\n#',
            '',
            $content,
        );

        if ($content !== null) {
            file_put_contents($configPath, $content);
            $this->info('  Codex article resource removed from the Shield config');
        }

        $this->removeShieldPermissions();
    }

    /**
     * Delete the permission rows Shield generated for the article resource and
     * the two pages.
     *
     * The names are asked of Shield rather than rebuilt, because Shield 4
     * changed both the separator and the case of every permission it writes
     * and both are configurable. When Shield cannot answer — it is already
     * gone, or its API moved — say so and stop, rather than guessing at names
     * and deleting a host's own permissions.
     */
    protected function removeShieldPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $names = $this->shieldPermissionNames();

        if ($names === []) {
            $this->components->warn('Could not ask Filament Shield for the Codex permission names; none were deleted.');
            $this->line('  Remove the Codex entries from the permissions table by hand if you no longer want them.');

            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $names)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        $deleted = DB::table('permissions')
            ->whereIn('name', $names)
            ->delete();

        $this->info("  Removed {$deleted} Codex Shield permissions from the database");
    }

    /**
     * @return list<string>
     */
    protected function shieldPermissionNames(): array
    {
        $facade = self::SHIELD_FACADE;

        if (! class_exists($facade)) {
            return [];
        }

        $names = [];

        if (is_callable([$facade, 'getPages'])) {
            $pages = call_user_func([$facade, 'getPages']);

            if (is_array($pages)) {
                foreach ([HelpSettings::class, HelpCoverage::class] as $pageClass) {
                    $names = [...$names, ...$this->permissionKeysOf($pages[$pageClass] ?? null)];
                }
            }
        }

        if (is_callable([$facade, 'getResources'])) {
            $resources = call_user_func([$facade, 'getResources']);

            if (is_array($resources)) {
                $names = [...$names, ...$this->permissionKeysOf($resources[ArticleResource::class] ?? null)];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Shield describes both a page and a resource as
     * ['permissions' => [$name => $label], ...]; only the keys are the
     * permission names.
     *
     * @return list<string>
     */
    protected function permissionKeysOf(mixed $entry): array
    {
        if (! is_array($entry) || ! is_array($entry['permissions'] ?? null)) {
            return [];
        }

        $keys = [];

        foreach (array_keys($entry['permissions']) as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    protected function cleanupPublishedViews(): void
    {
        $viewsPath = resource_path('views/vendor/fin-codex');

        if (! is_dir($viewsPath)) {
            return;
        }

        if (! $this->confirm('Delete the published view files? (resources/views/vendor/fin-codex/)', false)) {
            return;
        }

        $this->deleteDirectory($viewsPath);
        $this->info('  Deleted: resources/views/vendor/fin-codex/');
    }

    protected function cleanupPublishedTranslations(): void
    {
        $translationsPath = lang_path('vendor/fin-codex');

        if (! is_dir($translationsPath)) {
            return;
        }

        if (! $this->confirm('Delete the published translation files? (lang/vendor/fin-codex/)', false)) {
            return;
        }

        $this->deleteDirectory($translationsPath);
        $this->info('  Deleted: lang/vendor/fin-codex/');
    }

    protected function deleteDirectory(string $directory): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
