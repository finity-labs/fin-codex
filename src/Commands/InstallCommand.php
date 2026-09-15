<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands;

use FinityLabs\FinCodex\Ai\AiSettings;
use FinityLabs\FinCodex\Commands\Concerns\EditsCoreConfig;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Starter\StarterManifest;
use FinityLabs\FinSupport\Console\Concerns\DiscoversPanelProviders;
use FinityLabs\FinSupport\Console\Concerns\EditsPanelProviders;
use FinityLabs\FinSupport\Console\Concerns\EditsShieldConfig;
use FinityLabs\LinCodex\Ai\AiCallFailed;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Ai\Contracts\AiClient;
use FinityLabs\LinCodex\Ai\ProviderCatalog;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Data\TranslationData;
use FinityLabs\LinCodex\Enums\RevisionReason;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Revisions\RevisionManager;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use FinityLabs\LinCodex\Sync\ArticleImporter;
use FinityLabs\LinCodex\Sync\ImportOptions;
use FinityLabs\LinSupport\Console\Concerns\PromptsForLocales;
use FinityLabs\LinSupport\Locale\InstalledLocales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

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
 * this command points at (or calls) `codex:install` and never migrates a core
 * table or publishes a core asset itself. Its one reach into lin-codex is the
 * config file: since 0.5.0 the install switches the core's public help center
 * off, and publishing that file is the only way to write the value.
 * What is left is genuinely fin-codex's:
 * the plugin registration in a panel provider, the languages the editor
 * offers, the starter articles, the two optional publish groups this package
 * has (translations and views), and the Filament Shield wiring for the
 * article resource. The panel-provider and Shield edits are fin-support's,
 * the language prompt is lin-support's.
 *
 * The AI translation step is the one optional extra, behind --ai (answer
 * its question with yes) and --ai-only (run nothing else). It asks for the
 * same three things the settings page asks for - provider, model and key -
 * tests the connection once and saves them together, so a host can go from
 * composer require to a working Translate with AI button without opening the
 * panel. It offers to install the optional SDK when it is missing and then
 * stops, because the running process cannot see a package composer has just
 * written. A non-interactive run without either flag never mentions AI.
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
    use EditsCoreConfig;
    use EditsPanelProviders;
    use EditsShieldConfig;
    use PromptsForLocales;

    /**
     * The abilities the article resource registers with Shield. The first five
     * are Filament's own; restore (a revision restore, not a soft delete),
     * import (adopting a file article into the database), convert (HTML to
     * Markdown) and viewAllPanels (reading every panel's help from inside one
     * panel) are fin-codex's, and only exist in a generated policy because they
     * are listed here — `policies.merge` folds a resource's own methods into
     * Shield's default list.
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
        'viewAllPanels',
    ];

    /** The languages the starter articles are written in. */
    public const STARTER_LOCALES = ['en', 'de', 'hu'];

    protected ?string $panelId = null;

    protected bool $shieldConfigured = false;

    protected bool $aiConfigured = false;

    protected bool $publicHelpCenterSwitched = false;

    /** @var list<string> */
    protected array $languages = [];

    protected $signature = 'fin-codex:install
                            {--panel= : Panel ID to register the plugin in}
                            {--locales= : Comma-separated locale codes for the help articles (e.g. en,hu,de)}
                            {--skip-starter-articles : Do not import the starter articles about the help system}
                            {--ai : Set up AI translation without asking}
                            {--ai-only : Run only the AI translation step}
                            {--force : Overwrite existing published files}';

    protected $description = 'Install the Codex Filament plugin.';

    public function handle(): int
    {
        if ((bool) $this->option('ai-only')) {
            $this->configureAi();

            return self::SUCCESS;
        }

        $this->info('Installing the Codex Filament plugin...');
        $this->newLine();

        $this->ensureCoreInstalled();
        $this->switchPublicHelpCenterOff();
        $this->registerInPanel();
        $this->configureLanguages();
        $this->importStarterArticles();
        $this->publishOptionalAssets();
        $this->configureShield();
        $this->configureAi();

        $this->newLine();
        $this->info('Codex Filament plugin installed.');
        $this->newLine();

        $nextSteps = [
            ['Write the first article', 'Help → Help articles → New, or php artisan codex:make intro --title="Introduction"'],
            ['Review the settings', 'Help → Help settings (languages, revisions, drawer)'],
            ['Find pages without help', 'Help → Help coverage'],
        ];

        if ($this->publicHelpCenterSwitched) {
            $nextSteps[] = ['Public help center', '/help is off; help now lives at {panel}/help inside the panel'];
        }

        if ($this->shieldConfigured) {
            // The ability is named, the permission is not: the Shield config
            // this process loaded predates the edit just made to it, so a name
            // derived here would be right on a re-run and empty on a first
            // install. Naming the ability is what ends the dead end.
            $nextSteps[] = ['Assign permissions', 'Shield → Roles: tick the Codex permissions. viewAllPanels is the one that lets a role read every panel\'s help from inside one panel'];
        }

        if ($this->aiConfigured) {
            $nextSteps[] = ['Translate with AI', 'AI translation is on: open a non-default language tab in the editor and press Translate with AI'];
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

    /**
     * Switch the core's public help center off.
     *
     * Since 0.5.0 help lives at {panel}/help, inside the panel and behind its
     * login, so the bare public page is a second, unthemed copy of the same
     * articles sitting outside every panel. lin-codex reads its route prefix
     * once and registers neither help-center route when it is null, so writing
     * the null into the published config is the whole switch.
     *
     * It is a step of its own rather than something codex:install does, because
     * codex:install is only reached on a host that has no articles table yet —
     * an upgrading host would never see it.
     *
     * A host who set a prefix of their own is asked first. The default is yes,
     * so a non-interactive run takes the switch.
     */
    protected function switchPublicHelpCenterOff(): void
    {
        // Reset first, the way registerInPanel() resets $panelId: Symfony keeps
        // one command instance per application, so a second run in the same
        // process would otherwise inherit the first run's answer.
        $this->publicHelpCenterSwitched = false;

        $path = $this->coreConfigPath();

        if (! file_exists($path)) {
            $this->comment('Publishing the lin-codex config...');
            $this->callSilently('vendor:publish', ['--tag' => 'lin-codex-config']);
        }

        if (! file_exists($path)) {
            $this->components->warn('config/lin-codex.php is not published; set routes.help_center to null by hand.');

            return;
        }

        $current = config('lin-codex.routes.help_center');

        if ($current === null) {
            $this->info('  The public help center is already off (lin-codex.routes.help_center is null)');

            return;
        }

        if (! $this->confirm("Switch the public help center off? It is mounted at {$current}; the Help Center page inside the panel replaces it.", true)) {
            $this->line('  Left as it is. Both help centers will answer.');

            return;
        }

        if (! $this->setCoreRoutePrefix($path, null)) {
            $this->components->warn('Could not edit config/lin-codex.php; set routes.help_center to null by hand.');

            return;
        }

        $this->publicHelpCenterSwitched = true;

        $this->info('  Public help center switched off: /help now answers 404, the Help Center page inside the panel replaces it');

        if (app()->routesAreCached()) {
            $this->line('  Your routes are cached: run php artisan route:clear for this to take effect.');
        }
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
     * of the page it describes; the help section also carries the panel it
     * was installed on, while the account section is left on any panel so
     * every panel's login page gets it. Those pages have help from the first
     * day.
     *
     * Only the configured languages are kept, and only when at least one of
     * them is a language the articles exist in — an install in French alone
     * gets nothing rather than English it did not ask for. The core importer
     * does the writing (revisions attributed to the import, search text
     * filled), from the package's docs folder swapped in as the file source
     * for the duration; the rows are then cut loose from their files so the
     * editor treats them as its own and nothing in vendor/ shadows them.
     *
     * The second state, the one an upgrade lands in: the database already
     * holds these slugs, so the importer skips them and this version's
     * rewritten docs would never reach the reader. Those skipped slugs go to
     * refreshStarterArticles(), which corrects the ones that still carry a
     * text some version of this package shipped, fills in a language
     * configured after the install, and names — without touching — the ones
     * the host has edited.
     * That last refusal is absolute: there is no flag and no prompt that
     * overwrites an article the host has made their own, and --force on this
     * command means published files, nothing else. Only title, excerpt and
     * body move, so a refresh never changes where, whether or in what order
     * an article appears.
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

            // Read inside the same window: the docs folder is only the file
            // source in here, and the set is what the refresh below compares
            // an existing host's stored articles against.
            $shipped = app(FilesystemSource::class)->set()->articles;
        } finally {
            config()->set($configKey, $hostPaths);
            app()->forgetInstance(FilesystemSource::class);
        }

        $slugs = self::starterSlugs();
        $imported = Article::query()->whereIn('slug', $slugs)->whereNotNull('source_path')->pluck('id');

        if ($imported->isNotEmpty()) {
            ArticleTranslation::query()->whereIn('article_id', $imported)->whereNotIn('locale', $locales)->delete();
            Article::query()->whereIn('id', $imported)->update(['source_path' => null]);

            // The help section describes this panel's editor, so it belongs
            // to this panel: a second panel carrying the plugin — a customer
            // portal, say — must not offer its users the editor's manual. The
            // account section stays "any panel" on purpose: it is bound to
            // Filament's own sign-in, register, password and profile pages,
            // which every panel serves and no panel registers, and naming
            // one panel would hide it on every other panel's login page.
            // Without a registered panel everything stays "any panel"; an
            // admin widens or narrows a context in the editor either way.
            if ($this->panelId !== null) {
                $helpSection = Article::query()->whereIn('id', $imported)->where(function ($query): void {
                    $query->where('slug', 'help')->orWhere('slug', 'like', 'help/%');
                })->pluck('id');

                ArticleContext::query()->whereIn('article_id', $helpSection)->whereNull('panel_id')->update(['panel_id' => $this->panelId]);
            }
        }

        if ($report->hasFailures()) {
            foreach ($report->failures() as $key => $reason) {
                $this->components->warn("Starter article {$key} was not imported: {$reason}");
            }
        }

        $skipped = $report->skippedSlugs();

        // Both lines are about slugs the importer skipped, so neither can
        // appear on a first install — the "imported in" line below already
        // says everything true about that run. An article that is already
        // current says nothing at all: silence there is the point.
        ['refreshed' => $refreshed, 'edited' => $edited] = $this->refreshStarterArticles($shipped, $skipped, $locales);

        if ($refreshed !== []) {
            $refreshedIn = array_values(array_intersect($locales, array_unique(array_merge(...array_values($refreshed)))));

            $this->info('  Starter articles refreshed in '.implode(', ', $refreshedIn).': '.implode(', ', array_keys($refreshed)));
        }

        if ($edited !== []) {
            $names = [];

            foreach ($edited as $slug => $editedIn) {
                $names[] = $slug.' ('.implode(', ', $editedIn).')';
            }

            $this->line('  Starter articles you have edited, left as they are: '.implode(', ', $names));
        }

        $created = array_values(array_diff($slugs, $skipped));

        if ($imported->isNotEmpty() && $created !== []) {
            $this->info('  Starter articles imported in '.implode(', ', $locales).': '.implode(', ', $created));
        }
    }

    /**
     * Bring the starter articles a host already has back in line with the
     * files this version ships, without ever replacing words the host wrote.
     *
     * The importer leaves an existing slug alone, which is right — the
     * article belongs to the host once it is in the database — but it also
     * means a host who installed before a docs rewrite never sees the new
     * text. The question per slug and configured language is whether the
     * stored text is one this package shipped at some point, which is what
     * the starter manifest (Starter\StarterManifest) answers:
     *
     * 1. the row carries the text this version ships: nothing to do, and
     *    nothing to say about it;
     * 2. the row carries a text an earlier version shipped: refresh it;
     * 3. the row carries anything else: the host wrote it, so it is only
     *    named, never touched;
     * 4. there is no row in a configured language: fill it in, which is how
     *    a language added after the install finally gets the starter set.
     *
     * The manifest, not the timestamps, decides — a save moves updated_at,
     * the refresh's own included, so a timestamp rule could refresh a row
     * once and never again. Case 3 is deliberately generous: an AI
     * translation, a hand edit, even a wording change of one letter all
     * leave a text the package never shipped, and all are left alone.
     *
     * An article whose rows carry no shipped text in any language is not a
     * starter article at all as far as this command can tell — a host may
     * have written an article of their own under one of these slugs before
     * installing — so it is named and left whole, and no language is filled
     * in beside it.
     *
     * The comparison is byte-exact against the core's own read of the shipped
     * Markdown, which is safe because the importer stores those three values
     * verbatim. The one step in the file read that depends on host config
     * rewrites image paths, and the starter docs carry no images.
     *
     * Only title, excerpt and body move. Nothing here writes the article row,
     * its contexts or its panel, so where, whether and in what order a host's
     * articles appear is left exactly as they arranged it — a docs refresh
     * changes the reader's text and nothing else.
     *
     * @param  array<string, ArticleData>  $shipped  the files this version ships, keyed by slug
     * @param  list<string>  $slugs  the slugs the importer skipped because they already exist
     * @param  list<string>  $locales  the configured languages the starter set exists in
     *
     * @return array{refreshed: array<string, list<string>>, edited: array<string, list<string>>} slug => languages
     */
    protected function refreshStarterArticles(array $shipped, array $slugs, array $locales): array
    {
        $refreshed = [];
        $edited = [];
        $manifest = app(StarterManifest::class);

        $articles = Article::query()->whereIn('slug', $slugs)->get()->keyBy('slug');

        foreach ($slugs as $slug) {
            $file = $shipped[$slug] ?? null;
            $article = $articles->get($slug);

            if ($file === null || ! $article instanceof Article) {
                continue;
            }

            $rows = ArticleTranslation::query()
                ->where('article_id', $article->id)
                ->get()
                ->keyBy('locale');

            $isStarter = $rows->contains(fn (ArticleTranslation $row): bool => $manifest->knows($slug, $row->locale, StarterManifest::hash($row)));

            /** @var array<string, TranslationData> $stale */
            $stale = [];
            $theirs = [];

            foreach ($locales as $locale) {
                $translation = $file->translation($locale);

                if ($translation === null) {
                    continue;
                }

                $row = $rows->get($locale);

                if (! $row instanceof ArticleTranslation) {
                    $stale[$locale] = $translation;

                    continue;
                }

                $hash = StarterManifest::hash($row);

                if ($hash === StarterManifest::hash($translation)) {
                    continue;
                }

                if ($isStarter && $manifest->knows($slug, $locale, $hash)) {
                    $stale[$locale] = $translation;
                } else {
                    $theirs[] = $locale;
                }
            }

            if ($theirs !== []) {
                $edited[$slug] = $theirs;
            }

            if ($stale === [] || ! $isStarter) {
                continue;
            }

            try {
                app(RevisionManager::class)->attributing(RevisionReason::Import, null, function () use ($article, $stale): void {
                    DB::transaction(function () use ($article, $stale): void {
                        foreach ($stale as $locale => $translation) {
                            $this->writeStarterTranslation($article, $locale, $translation);
                        }
                    });
                });

                $refreshed[$slug] = array_keys($stale);
            } catch (Throwable $e) {
                $this->components->warn("Starter article {$slug} could not be refreshed: {$e->getMessage()}");
            }
        }

        return ['refreshed' => $refreshed, 'edited' => $edited];
    }

    /**
     * The refresh's one write: the three columns a docs update moves, saved
     * through the model so the core's own hooks run — the replaced content is
     * kept as a revision the host can restore when they have revisions on,
     * and the search text is rebuilt from the new body.
     *
     * The article is handed to the row rather than looked up from it, which
     * saves a query and keeps the write working where lazy loading is turned
     * off, as it is throughout this package's suite.
     */
    protected function writeStarterTranslation(Article $article, string $locale, TranslationData $translation): void
    {
        $row = ArticleTranslation::query()->firstOrNew([
            'article_id' => $article->id,
            'locale' => $locale,
        ]);

        $row->setRelation('article', $article);

        $row->fill([
            'title' => $translation->title,
            'excerpt' => $translation->excerpt,
            'body' => $translation->body,
        ])->save();
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
     * list. The three pages need nothing here: Shield 4 auto-discovers pages
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
            $this->line("  php artisan shield:generate{$panelFlag} --resource=ArticleResource --option=policies_and_permissions --ignore-existing-policies");
            $this->line("  php artisan shield:generate{$panelFlag} --page=HelpSettings,HelpCoverage,HelpCenter");

            return;
        }

        $this->comment('Generating Shield permissions and policies for the Codex article resource...');

        // Shield 4 generates nothing unless it is told what for: every one of
        // its generators is gated on --resource, --page, --widget or --all, and
        // a run that names none exits 0 with an empty summary. Naming the
        // resource is what makes this run produce the article permissions.
        $args = [
            PHP_BINARY, 'artisan', 'shield:generate',
            '--resource=ArticleResource',
            '--option=policies_and_permissions',
            '--ignore-existing-policies',
            '--no-interaction',
        ];

        if ($this->panelId !== null) {
            $args[] = "--panel={$this->panelId}";
        }

        [$exitCode, $output] = $this->runShieldGenerate($args);

        $this->printShieldOutput($output);

        if ($exitCode === 0) {
            $this->shieldConfigured = true;
            $this->info('  Shield permissions and policies generated');

            if ($this->reportsSkippedPolicy($output)) {
                $this->components->info('Shield skipped the Article policy because fin-codex registers its own. That is expected and there is nothing to fix: the shipped policy reads Shield\'s permissions itself, so ticking them on a role is all that is left.');
            }
        } else {
            $this->components->warn('Could not generate the Shield permissions automatically. Run manually:');
            $this->line("  php artisan shield:generate{$panelFlag} --resource=ArticleResource --option=policies_and_permissions --ignore-existing-policies");
        }

        // Pages are discovered, not configured, so they are a separate run. The
        // help center is on this line with the two editor screens: it asks
        // Shield for its permission the same way, so on a Shield host it is
        // closed to every role until this has run.
        $this->line("  Help settings, Help coverage and the Help center are discovered by Shield: php artisan shield:generate{$panelFlag} --page=HelpSettings,HelpCoverage,HelpCenter");
    }

    /**
     * Run shield:generate and hand back its exit code and everything it said,
     * standard and error output together.
     *
     * A seam, and the only reason this is a method of its own: a fresh PHP
     * process cannot be handed a fake Artisan command, so a test that wants to
     * prove what this command does with Shield's answer has to replace the run
     * itself. The package test harness has no Shield at all.
     *
     * @param  list<string>  $args
     *
     * @return array{0: int, 1: string}
     */
    protected function runShieldGenerate(array $args): array
    {
        $process = new Process($args, base_path());
        $process->setTimeout(60);
        $process->run();

        return [
            $process->getExitCode() ?? 1,
            trim($process->getOutput()."\n".$process->getErrorOutput()),
        ];
    }

    /**
     * Echo what the other process said, indented, before this command judges
     * it. Shield's skipped-policy line lives in here and used to be swallowed.
     */
    protected function printShieldOutput(string $output): void
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) !== '') {
                $this->line('  '.trim($line));
            }
        }
    }

    /**
     * Whether Shield reported skipping a policy.
     *
     * It always does for the article: fin-codex binds a policy for the model
     * itself, so Shield's generator decides the model is provided for and writes
     * no host class. Read off the output rather than assumed, so a Shield that
     * ever stops skipping stops being explained.
     */
    protected function reportsSkippedPolicy(string $output): bool
    {
        $output = strtolower($output);

        return str_contains($output, 'skipped') && str_contains($output, 'policy');
    }

    /**
     * The AI translation step: the version gate, the offer to install the
     * optional SDK, and then the provider, model and key prompts.
     *
     * The order of the guards is the point. A run that never asked for AI -
     * no flag and no interactive input to ask in - returns before anything is
     * printed, so a scripted install reads exactly as it did before this step
     * existed, on every PHP version. Whether the SDK is there is the seam's
     * answer, never a question this package asks Composer itself: fin-codex
     * names no SDK symbol anywhere, which is also what lets every row of the
     * test suite run this step.
     */
    protected function configureAi(): void
    {
        $requested = (bool) $this->option('ai') || (bool) $this->option('ai-only');

        if (! $requested && ! $this->input->isInteractive()) {
            return;
        }

        if (! $this->aiVersionRequirementsMet()) {
            return;
        }

        if (! $requested && ! confirm(label: 'Set up AI translation?', default: false)) {
            return;
        }

        /*
         * The settings live in lin-codex's table, which codex:install creates;
         * without it the save at the end would throw a QueryException after
         * three prompts and a round trip, so the check comes first.
         */
        if (! $this->hasArticlesTable()) {
            $this->components->warn('The Codex settings are not reachable yet; run php artisan codex:install, then php artisan fin-codex:install --ai-only.');

            return;
        }

        if (! app(AiClient::class)->installed()) {
            $this->comment('The laravel/ai SDK is not installed; running composer require laravel/ai:^0.11 ...');

            if (! $this->runComposerRequire('laravel/ai:^0.11')) {
                return;
            }

            /*
             * This process's autoloader cannot see a package Composer wrote a
             * second ago, so the prompts belong to the next run.
             */
            $this->components->info('SDK installed.');
            $this->line('  Run php artisan fin-codex:install --ai-only to choose the provider, model and key.');

            return;
        }

        if (! $this->input->isInteractive()) {
            $this->line('  AI translation needs an interactive run to choose the provider, model and key: php artisan fin-codex:install --ai-only');

            return;
        }

        $this->promptAndSaveAi();
    }

    /**
     * The three questions the settings page also asks - provider, model and
     * key - one connection test, and one save.
     *
     * The tier list is the seam's, so the choices carry the concrete model
     * ids rather than the word "Default", and a provider that answers with
     * the same id for two tiers is offered once. A provider the SDK reaches
     * over a URL is never asked for a key, and one that already has a key -
     * stored here or in the SDK's own config - is asked for one it may leave
     * blank, with the hint saying which of the two a blank answer keeps.
     *
     * Nothing is written before the connection answers: a run that fails the
     * test leaves the host exactly as it found it.
     */
    private function promptAndSaveAi(): void
    {
        $client = app(AiClient::class);
        $providers = $client->providers();

        if ($providers === []) {
            $this->components->error('The installed SDK offers no provider.');

            return;
        }

        $provider = (string) select(
            label: 'Which provider should translate the articles?',
            options: $providers,
            required: true,
        );

        try {
            $tiers = $client->tierModels($provider);
        } catch (AiCallFailed) {
            $tiers = [];
        }

        $models = [];
        $options = [];

        foreach ($tiers as $tier => $id) {
            if (in_array($id, $models, true)) {
                continue;
            }

            $models[$tier] = $id;
            $options[$tier] = ucfirst($tier)." ({$id})";
        }

        $options['custom'] = 'Custom model id';

        $choice = (string) select(
            label: 'Which model?',
            options: $options,
            default: $models === [] ? 'custom' : 'default',
        );

        $model = $choice === 'custom'
            ? trim((string) text(label: 'Model id', required: true))
            : $models[$choice];

        $label = $providers[$provider];
        $envConfigured = ProviderCatalog::envConfigured($provider);
        $stored = AiSettings::storedApiKey();

        $key = ProviderCatalog::isKeyless($provider) ? '' : (string) password(
            label: "API key for {$label}",
            // Asked for only when there is nothing else to reach the provider
            // with: no key of its own in storage, and none in the SDK's own
            // config for it. The settings page's own rule.
            required: $stored === null && ! $envConfigured,
            hint: $this->keyHint($stored !== null, $envConfigured),
        );

        /*
         * A blank answer KEEPS the stored key, which is what the settings
         * page's blank save does and what the prompt now says. Only a host
         * with nothing stored writes null, and there null means "use the
         * SDK's own credential". Re-running this step to change the model
         * must not cost the host the key it is already translating with.
         */
        $apiKey = $key !== '' ? $key : $stored;

        $this->comment('Testing the connection...');

        $reason = $client->testConnection($provider, $model, $apiKey);

        if ($reason !== null) {
            $this->components->error('AI connection test failed: '.AiReason::label($reason));
            $this->line('  Nothing was saved. Run php artisan fin-codex:install --ai-only to try again.');

            return;
        }

        AiSettings::write([
            'enabled' => true,
            'provider' => $provider,
            'model' => $model,
            'api_key' => $apiKey,
        ]);

        $this->aiConfigured = true;
        $this->info("  AI translation configured: {$label}, {$model}");
        $this->line('  Open a non-default language tab in the editor and press Translate with AI.');
    }

    /**
     * What a blank answer to the key prompt does, in the order the value is
     * resolved in: keep the key already stored, else use the one the SDK's
     * config carries for this provider. With neither, there is no hint - the
     * prompt is required instead.
     */
    private function keyHint(bool $stored, bool $envConfigured): string
    {
        if ($stored) {
            return 'Leave blank to keep the key already stored';
        }

        return $envConfigured ? 'Leave blank to use the key from config/ai.php' : '';
    }

    /**
     * The optional SDK's floor, checked without touching the SDK. fin-codex
     * itself runs on PHP 8.2 and Laravel 11, so this is a per-host answer.
     */
    private function aiVersionRequirementsMet(): bool
    {
        if (PHP_VERSION_ID >= 80300 && (int) explode('.', app()->version())[0] >= 12) {
            return true;
        }

        $this->components->warn(sprintf(
            'AI translation needs PHP 8.3+ and Laravel 12+. You are on PHP %s / Laravel %s.',
            PHP_VERSION,
            app()->version(),
        ));
        $this->line('  Skipped. Upgrade, then run php artisan fin-codex:install --ai-only.');

        return false;
    }

    /**
     * Install a package into the host through Composer, streaming its output.
     * Protected because the command tests replace it: nothing in a test run
     * may shell out to Composer.
     */
    protected function runComposerRequire(string $package): bool
    {
        $process = new Process(['composer', 'require', $package, '--no-interaction', '--ansi'], base_path());
        $process->setTimeout(120);

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $firstLine = strtok($process->getErrorOutput(), "\n") ?: 'unknown error';
            $this->components->error("Composer require failed: {$firstLine}");
            $this->line("  Run it yourself, then php artisan fin-codex:install --ai-only: composer require {$package}");

            return false;
        }

        return true;
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
