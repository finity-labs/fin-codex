<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands;

use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinSupport\Console\Concerns\DiscoversPanelProviders;
use FinityLabs\FinSupport\Console\Concerns\EditsPanelProviders;
use FinityLabs\FinSupport\Console\Concerns\EditsShieldConfig;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use FinityLabs\LinCodex\Sync\ArticleImporter;
use FinityLabs\LinCodex\Sync\ImportOptions;
use FinityLabs\LinSupport\Console\Concerns\PromptsForLocales;
use FinityLabs\LinSupport\Locale\InstalledLocales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\select;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * fin-codex:install brings a host from "composer require" to a panel that
 * carries the help drawer, the article editor, the settings page and the
 * coverage page, with its languages configured and a first set of articles
 * about the help system itself.
 *
 * It deliberately owns very little. Articles, media, revisions, settings and
 * the search index all belong to lin-codex, which ships its own installer, so
 * this command points at (or calls) `codex:install` and never publishes or
 * migrates a single core asset itself. What is left is genuinely fin-codex's:
 * the plugin registration in a panel provider, the languages the editor
 * offers, the starter articles, the two optional publish groups this package
 * has (translations and views), and the Filament Shield wiring for the
 * article resource. The panel-provider and Shield edits are fin-support's,
 * the language prompt is lin-support's.
 *
 * Every step is safe to repeat. The plugin registration refuses a second
 * `FinCodexPlugin::make()`, the Shield insertion refuses a second
 * `FinityLabs\FinCodex` entry, the starter import skips an article whose
 * slug already exists, and vendor:publish skips files that already exist
 * unless --force is passed.
 */
class InstallCommand extends Command
{
    use DiscoversPanelProviders;
    use EditsPanelProviders;
    use EditsShieldConfig;
    use PromptsForLocales;

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
    public const RESOURCE_ABILITIES = [
        'viewAny',
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'import',
        'convert',
    ];

    /** The languages the starter articles are written in. */
    public const STARTER_LOCALES = ['en', 'de', 'hu'];

    protected ?string $panelId = null;

    protected bool $shieldConfigured = false;

    /** @var list<string> */
    protected array $languages = [];

    protected $signature = 'fin-codex:install
                            {--panel= : Panel ID to register the plugin in}
                            {--locales= : Comma-separated locale codes for the help articles (e.g. en,hu,de)}
                            {--skip-starter-articles : Do not import the starter articles about the help system}
                            {--force : Overwrite existing published files}';

    protected $description = 'Install the Codex Filament plugin.';

    public function handle(): int
    {
        $this->info('Installing the Codex Filament plugin...');
        $this->newLine();

        $this->ensureCoreInstalled();
        $this->registerInPanel();
        $this->configureLanguages();
        $this->importStarterArticles();
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
        if ($this->hasArticlesTable()) {
            return;
        }

        $this->components->warn("The {$this->articlesTable()} table is missing. lin-codex owns the Codex schema, settings and search index; fin-codex only adds the panel surfaces over them.");

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
        $this->panelId = null;
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
        $this->registerPlugin($panelProviders[$this->panelId], FinCodexPlugin::class);
    }

    /**
     * The languages the editor offers, written to lin-codex's settings. The
     * --locales option answers outright; an interactive run is asked, with
     * the application's installed locales pre-selected; a non-interactive run
     * without the option takes those installed locales as they are. The
     * default language stays the application locale when it is among them,
     * and becomes the first chosen one otherwise. Skipped, with a note, when
     * the settings cannot be reached (the core installer has not run).
     */
    protected function configureLanguages(): void
    {
        try {
            $settings = app(CodexSettings::class);
            $current = array_column($settings->languages, 'code');
        } catch (Throwable) {
            $this->components->warn('The Codex settings are not reachable yet; run php artisan codex:install, then set the languages under Help → Help settings.');

            return;
        }

        $option = $this->option('locales');
        $hasOption = is_string($option) && trim($option) !== '';

        $this->languages = ! $hasOption && ! $this->input->isInteractive()
            ? InstalledLocales::detect()
            : $this->resolveLocales('Which languages should the help articles be written in?');

        $appLocale = (string) config('app.locale', 'en');
        $default = in_array($appLocale, $this->languages, true) ? $appLocale : $this->languages[0];

        try {
            $settings->languages = $this->localeEntries($this->languages);
            $settings->default_locale = $default;
            $settings->save();

            $this->info('  Languages configured: '.implode(', ', $this->languages).' (default '.$default.')'.($current === $this->languages ? '' : ', replacing '.implode(', ', $current)));
        } catch (Throwable $e) {
            $this->components->warn('Could not save the languages: '.$e->getMessage().'. Set them under Help → Help settings.');
        }
    }

    /**
     * The articles shipped with this package in en, de and hu, imported as
     * ordinary database articles the admin can edit or delete: about the help
     * system itself (opening the drawer, writing articles, the coverage and
     * settings pages, declaring help in code) under an authenticated "help"
     * section, and about Filament's own screens (signing in, registering, a
     * forgotten password, email verification, the profile) under a public
     * "account" section — public because the core hides an article whose
     * ancestor the viewer may not read, so a guest on the sign-in page only
     * sees an article whose whole path is public. Each carries the context
     * of the page it describes and the panel it was installed on, so those
     * pages have help from the first day.
     *
     * Only the configured languages are kept, and only when at least one of
     * them is a language the articles exist in — an install in French alone
     * gets nothing rather than English it did not ask for. The core importer
     * does the writing (revisions attributed to the import, search text
     * filled), from the package's docs folder swapped in as the file source
     * for the duration; the rows are then cut loose from their files so the
     * editor treats them as its own and nothing in vendor/ shadows them.
     */
    protected function importStarterArticles(): void
    {
        if ((bool) $this->option('skip-starter-articles') || ! $this->hasArticlesTable()) {
            return;
        }

        $locales = array_values(array_intersect($this->languages, self::STARTER_LOCALES));

        if ($locales === []) {
            $this->line('  The starter articles exist in '.implode(', ', self::STARTER_LOCALES).' only; none of the configured languages match, so none were imported.');

            return;
        }

        if (! $this->confirm('Import the starter articles about using the help system?', true)) {
            return;
        }

        // The core reads an article's shared keys — contexts, order, icon,
        // visibility — from its default-language file only, so every
        // language file of the starter set carries them: a host whose default
        // is Hungarian gets the same pages attached as one whose default is
        // English. The package docs folder stands in as the file source for
        // the duration of the import, then the host's own paths are back.
        $configKey = 'lin-codex.sources.filesystem.paths';
        $hostPaths = config($configKey, []);

        config()->set($configKey, [self::starterDocsPath()]);
        app()->forgetInstance(FilesystemSource::class);

        try {
            $report = app(ArticleImporter::class)->import(new ImportOptions);
        } finally {
            config()->set($configKey, $hostPaths);
            app()->forgetInstance(FilesystemSource::class);
        }

        $slugs = self::starterSlugs();
        $imported = Article::query()->whereIn('slug', $slugs)->whereNotNull('source_path')->pluck('id');

        if ($imported->isNotEmpty()) {
            ArticleTranslation::query()->whereIn('article_id', $imported)->whereNotIn('locale', $locales)->delete();
            Article::query()->whereIn('id', $imported)->update(['source_path' => null]);

            // The articles describe this panel's screens, so they belong to
            // this panel: a second panel carrying the plugin — a customer
            // portal, say — must not offer its users the editor's manual.
            // Without a registered panel they stay "any panel"; an admin
            // widens or narrows a context in the editor either way.
            if ($this->panelId !== null) {
                ArticleContext::query()->whereIn('article_id', $imported)->whereNull('panel_id')->update(['panel_id' => $this->panelId]);
            }
        }

        if ($report->hasFailures()) {
            foreach ($report->failures() as $key => $reason) {
                $this->components->warn("Starter article {$key} was not imported: {$reason}");
            }
        }

        $skipped = $report->skippedSlugs();

        if ($skipped !== []) {
            $this->line('  Starter articles already present, left as they are: '.implode(', ', $skipped));
        }

        $created = array_values(array_diff($slugs, $skipped));

        if ($imported->isNotEmpty() && $created !== []) {
            $this->info('  Starter articles imported in '.implode(', ', $locales).': '.implode(', ', $created));
        }
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
        if (! $this->hasShieldConfig()) {
            $this->components->info('Filament Shield is not installed (no config/filament-shield.php); skipping the permission wiring.');

            return;
        }

        if (! $this->confirm('Register the Codex article resource in the Filament Shield config?', true)) {
            return;
        }

        if (! $this->registerShieldResources([ArticleResource::class => self::RESOURCE_ABILITIES], 'FinityLabs\\FinCodex')) {
            return;
        }

        $this->info('  Codex article resource registered in the Shield config');
        $this->generateShieldPermissions();
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

    /** The package's own docs folder, one sub-folder per starter language. */
    public static function starterDocsPath(): string
    {
        return dirname(__DIR__, 2).'/resources/docs';
    }

    /**
     * The starter article slugs, from the English files: every Markdown file
     * under the English docs folder, `index.md` standing for its folder.
     *
     * @return list<string>
     */
    public static function starterSlugs(): array
    {
        $root = self::starterDocsPath().'/'.self::STARTER_LOCALES[0];
        $slugs = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -3);
            $slug = str_ends_with($relative, '/index') ? substr($relative, 0, -6) : ($relative === 'index' ? '' : $relative);

            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        return $slugs;
    }

    protected function articlesTable(): string
    {
        return (string) config('lin-codex.table_names.articles', 'codex_articles');
    }

    protected function hasArticlesTable(): bool
    {
        try {
            return Schema::hasTable($this->articlesTable());
        } catch (Throwable) {
            return false;
        }
    }

    protected function hasCommand(string $name): bool
    {
        $application = $this->getApplication();

        return $application !== null && $application->has($name);
    }
}
