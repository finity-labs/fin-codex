<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands;

use FinityLabs\FinCodex\Commands\Concerns\CanRegisterPlugin;
use FinityLabs\FinCodex\Commands\Concerns\DiscoversPanelProviders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\select;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * fin-codex:install brings a host from "composer require" to a panel that
 * carries the help drawer, the article editor, the settings page and the
 * coverage page.
 *
 * It deliberately owns very little. Articles, media, revisions, settings and
 * the search index all belong to lin-codex, which ships its own installer, so
 * this command points at (or calls) `codex:install` and never publishes or
 * migrates a single core asset itself. What is left is genuinely fin-codex's:
 * the plugin registration in a panel provider, the two optional publish
 * groups this package has (translations and views), and the Filament Shield
 * wiring for the article resource.
 *
 * Every step is safe to repeat. The plugin registration detects an existing
 * `FinCodexPlugin::make()` and refuses to add a second, the Shield insertion
 * detects an existing `FinityLabs\FinCodex` entry, and vendor:publish skips
 * files that already exist unless --force is passed.
 */
class InstallCommand extends Command
{
    use CanRegisterPlugin;
    use DiscoversPanelProviders;

    /**
     * The abilities the article resource registers with Shield. The first five
     * are Filament's own; restore (a revision restore, not a soft delete),
     * import (adopting a file article into the database) and convert (HTML to
     * Markdown) are fin-codex's, and only exist in a generated policy because
     * they are listed here — `policies.merge` folds a resource's own methods
     * into Shield's default list.
     *
     * @var list<string>
     */
    private const RESOURCE_ABILITIES = [
        'viewAny',
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'import',
        'convert',
    ];

    protected ?string $panelId = null;

    protected bool $shieldConfigured = false;

    protected $signature = 'fin-codex:install
                            {--panel= : Panel ID to register the plugin in}
                            {--force : Overwrite existing published files}';

    protected $description = 'Install the Codex Filament plugin.';

    public function handle(): int
    {
        $this->info('Installing the Codex Filament plugin...');
        $this->newLine();

        $this->ensureCoreInstalled();
        $this->registerInPanel();
        $this->publishOptionalAssets();
        $this->configureShield();

        $this->newLine();
        $this->info('Codex Filament plugin installed.');
        $this->newLine();

        $nextSteps = [
            ['Write the first article', 'Help → Help articles → New, or php artisan codex:make intro --title="Introduction"'],
            ['Review the settings', 'Help → Help settings (languages, revisions, drawer)'],
            ['Find pages without help', 'Help → Help coverage'],
        ];

        if ($this->shieldConfigured) {
            $nextSteps[] = ['Assign permissions', 'Give the new Codex permissions to your roles in Shield'];
        }

        $this->table(['Next steps', 'Details'], $nextSteps);

        return self::SUCCESS;
    }

    /**
     * lin-codex owns the schema. When its articles table is missing, say so
     * and offer to run its installer; never publish or migrate a core asset
     * from here.
     */
    protected function ensureCoreInstalled(): void
    {
        $table = (string) config('lin-codex.table_names.articles', 'codex_articles');

        try {
            if (Schema::hasTable($table)) {
                return;
            }
        } catch (Throwable) {
            $this->components->warn('Could not reach the database to look for the Codex tables. Run php artisan codex:install once the connection is configured.');

            return;
        }

        $this->components->warn("The {$table} table is missing. lin-codex owns the Codex schema, settings and search index; fin-codex only adds the panel surfaces over them.");

        if (! $this->hasCommand('codex:install')) {
            $this->line('  Run php artisan codex:install first, then run this command again.');

            return;
        }

        if (! $this->confirm('Run lin-codex\'s own installer (php artisan codex:install) now?', true)) {
            $this->line('  Skipped. Run php artisan codex:install before writing an article.');

            return;
        }

        $this->call('codex:install');
    }

    protected function registerInPanel(): void
    {
        $panelProviders = $this->discoverPanelProviders();

        if ($panelProviders === []) {
            $this->components->warn('No panel providers found in app/Providers/Filament/. Register FinCodexPlugin::make() manually.');

            return;
        }

        $panelId = $this->option('panel');
        $this->panelId = is_string($panelId) && $panelId !== '' ? $panelId : null;

        if ($this->panelId === null) {
            $this->panelId = $this->input->isInteractive()
                ? select(
                    label: 'Which panel should the Codex plugin be registered in?',
                    options: array_keys($panelProviders),
                    required: true,
                )
                : (string) array_key_first($panelProviders);
        }

        if (! isset($panelProviders[$this->panelId])) {
            $this->components->error("Panel provider not found for: {$this->panelId}");
            $this->panelId = null;

            return;
        }

        $this->comment("Registering FinCodexPlugin in the {$this->panelId} panel...");
        $this->registerPlugin($panelProviders[$this->panelId]);
    }

    /**
     * hasTranslations() and hasViews() are the only publishable groups this
     * package has: there is no fin-codex config file and no fin-codex
     * migration. Both default to no, because a published copy stops receiving
     * upstream changes.
     */
    protected function publishOptionalAssets(): void
    {
        if ($this->confirm('Publish the translation files for customisation?', false)) {
            $this->callSilently('vendor:publish', [
                '--tag' => 'fin-codex-translations',
                '--force' => (bool) $this->option('force'),
            ]);
            $this->info('  Translations published to lang/vendor/fin-codex/');
        }

        if ($this->confirm('Publish the view files for customisation?', false)) {
            $this->callSilently('vendor:publish', [
                '--tag' => 'fin-codex-views',
                '--force' => (bool) $this->option('force'),
            ]);
            $this->info('  Views published to resources/views/vendor/fin-codex/');
        }
    }

    /**
     * Write the article resource into filament-shield.php's resources.manage
     * list. The two pages need nothing here: Shield 4 auto-discovers pages
     * from the panel and only reads pages.exclude from config, so they get
     * their permissions the moment the plugin is registered.
     */
    protected function configureShield(): void
    {
        $configPath = config_path('filament-shield.php');

        if (! file_exists($configPath)) {
            $this->components->info('Filament Shield is not installed (no config/filament-shield.php); skipping the permission wiring.');

            return;
        }

        if (! $this->confirm('Register the Codex article resource in the Filament Shield config?', true)) {
            return;
        }

        $content = file_get_contents($configPath);

        if ($content === false) {
            $this->components->warn('Could not read the Shield config file.');

            return;
        }

        if (str_contains($content, 'FinityLabs\\FinCodex')) {
            $this->components->warn('The Codex article resource is already registered in the Shield config.');

            return;
        }

        $entries = "            \\FinityLabs\\FinCodex\\Resources\\ArticleResource::class => [\n";

        foreach (self::RESOURCE_ABILITIES as $ability) {
            $entries .= "                '{$ability}',\n";
        }

        $entries .= "            ],\n";

        $insertPos = $this->manageArrayInsertPosition($content);

        if ($insertPos === null) {
            return;
        }

        $content = substr($content, 0, $insertPos).$entries.substr($content, $insertPos);

        file_put_contents($configPath, $content);
        $this->info('  Codex article resource registered in the Shield config');

        $this->generateShieldPermissions();
    }

    /**
     * The byte offset of the line that closes the resources.manage array,
     * found by counting brackets from its opening one.
     */
    protected function manageArrayInsertPosition(string $content): ?int
    {
        $managePos = strpos($content, "'manage' => [");

        if ($managePos === false) {
            $this->components->warn('Could not find the manage array in the Shield config. Add the Codex article resource manually.');

            return null;
        }

        $openBracket = strpos($content, '[', $managePos + strlen("'manage' => "));

        if ($openBracket === false) {
            $this->components->warn('Could not parse the Shield config. Add the Codex article resource manually.');

            return null;
        }

        $depth = 1;
        $pos = $openBracket + 1;
        $len = strlen($content);

        while ($pos < $len && $depth > 0) {
            if ($content[$pos] === '[') {
                $depth++;
            } elseif ($content[$pos] === ']') {
                $depth--;
            }

            if ($depth > 0) {
                $pos++;
            }
        }

        $insertPos = strrpos(substr($content, 0, $pos), "\n");

        if ($insertPos === false) {
            $this->components->warn('Could not parse the Shield config. Add the Codex article resource manually.');

            return null;
        }

        return $insertPos + 1;
    }

    /**
     * Run shield:generate in a fresh process, so it reads the config file this
     * command just wrote rather than the one already loaded here. Skipped
     * entirely when the command is absent (Shield's config file can outlive an
     * uninstalled Shield, and the package test harness has no Shield at all),
     * in which case the manual line is printed instead.
     */
    protected function generateShieldPermissions(): void
    {
        $panelFlag = $this->panelId !== null ? " --panel={$this->panelId}" : '';

        if (! $this->hasCommand('shield:generate')) {
            $this->components->warn('shield:generate is not available. Run it yourself once Shield is installed:');
            $this->line("  php artisan shield:generate{$panelFlag} --option=policies_and_permissions --ignore-existing-policies");
            $this->line("  php artisan shield:generate{$panelFlag} --page=HelpSettings,HelpCoverage");

            return;
        }

        $this->comment('Generating Shield permissions and policies for the Codex article resource...');

        $args = [
            PHP_BINARY, 'artisan', 'shield:generate',
            '--option=policies_and_permissions',
            '--ignore-existing-policies',
            '--no-interaction',
        ];

        if ($this->panelId !== null) {
            $args[] = "--panel={$this->panelId}";
        }

        $process = new Process($args, base_path());
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            $this->shieldConfigured = true;
            $this->info('  Shield permissions and policies generated');
        } else {
            $this->components->warn('Could not generate the Shield permissions automatically. Run manually:');
            $this->line("  php artisan shield:generate{$panelFlag} --option=policies_and_permissions --ignore-existing-policies");
        }

        // Pages are discovered, not configured, so they are a separate run.
        $this->line("  Help settings and Help coverage are discovered by Shield: php artisan shield:generate{$panelFlag} --page=HelpSettings,HelpCoverage");
    }

    protected function hasCommand(string $name): bool
    {
        $application = $this->getApplication();

        return $application !== null && $application->has($name);
    }
}
