<?php

use FinityLabs\FinCodex\Commands\InstallCommand;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Commands\DecliningInstallCommand;
use FinityLabs\FinCodex\Tests\Fixtures\Commands\ShieldStubInstallCommand;
use FinityLabs\FinCodex\Tests\Fixtures\TempAppTree;
use FinityLabs\LinCodex\Enums\RevisionReason;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use FinityLabs\LinSupport\Locale\InstalledLocales;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * CLI-01, the install half.
 *
 * fin-codex:install rewrites two files it does not own: the host's panel
 * provider and, when Shield is installed, config/filament-shield.php. Every
 * row here runs the real command over real files at app_path() and
 * config_path() and reads them back, because the failure mode that matters is
 * a syntactically broken provider, which no mock can show.
 *
 * The console output is deliberately NOT mocked. Laravel's PendingCommand
 * replaces OutputStyle with a Mockery double that throws on any un-expected
 * askQuestion(), which would make every confirm() in the command a test-side
 * expectation and would prove nothing about --no-interaction. Running the
 * command for real and reading Artisan::output() back is what actually
 * proves the plan's "runs to completion with --no-interaction, non-zero only
 * on a real failure".
 */

afterEach(function () {
    TempAppTree::cleanup();
    ShieldStubInstallCommand::reset();
});

/**
 * Run a fin-codex command non-interactively and hand back its exit code and
 * its real output.
 *
 * @param  array<string, mixed>  $parameters
 *
 * @return array{0: int, 1: string}
 */
function finCodexRunCommand(string $command, array $parameters = []): array
{
    test()->withoutMockingConsoleOutput();

    $exitCode = test()->artisan($command, [...$parameters, '--no-interaction' => true]);

    return [$exitCode, Artisan::output()];
}

/**
 * The body one starter article's file carries in this version, read through
 * the core's own file source the way the command reads it, so an assertion
 * about a refreshed article cannot drift when the docs are rewritten again.
 */
function finCodexShippedBody(string $slug, string $locale): string
{
    $key = 'lin-codex.sources.filesystem.paths';
    $hostPaths = config($key, []);

    config()->set($key, [InstallCommand::starterDocsPath()]);
    app()->forgetInstance(FilesystemSource::class);

    try {
        $article = app(FilesystemSource::class)->set()->articles[$slug] ?? null;
    } finally {
        config()->set($key, $hostPaths);
        app()->forgetInstance(FilesystemSource::class);
    }

    return (string) $article?->translation($locale)?->body;
}

it('registers the plugin in the chosen panel provider', function () {
    $path = TempAppTree::writePanelProvider('admin');

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents($path);

    expect($exitCode)->toBe(0)
        ->and($content)->toContain('FinCodexPlugin::make()')
        ->and($content)->toContain('use FinityLabs\FinCodex\FinCodexPlugin;')
        ->and($content)->toContain('->plugins([')
        ->and(TempAppTree::lints($path))->toBeTrue();
});

it('appends to an existing plugins block instead of creating a second one', function () {
    $path = TempAppTree::writePanelProvider('admin', TempAppTree::PANEL_PROVIDER_WITH_PLUGINS);

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents($path);

    expect($exitCode)->toBe(0)
        ->and(substr_count($content, '->plugins(['))->toBe(1)
        ->and($content)->toContain('SomeOtherPlugin::make()')
        ->and($content)->toContain('FinCodexPlugin::make()')
        ->and(TempAppTree::lints($path))->toBeTrue();
});

it('does not register the plugin twice', function () {
    $path = TempAppTree::writePanelProvider('admin');

    [$first] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);
    [$second, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents($path);

    expect($first)->toBe(0)
        ->and($second)->toBe(0)
        ->and($output)->toContain('already registered')
        ->and(substr_count($content, 'FinCodexPlugin::make()'))->toBe(1)
        ->and(substr_count($content, 'use FinityLabs\FinCodex\FinCodexPlugin;'))->toBe(1)
        ->and(TempAppTree::lints($path))->toBeTrue();
});

it('picks the panel named by --panel and leaves the others alone', function () {
    TempAppTree::writePanelProvider('admin');
    $staff = TempAppTree::writePanelProvider('staff');

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'staff']);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($staff))->toContain('FinCodexPlugin::make()')
        ->and((string) file_get_contents(TempAppTree::panelProviderPath('admin')))
        ->not->toContain('FinCodexPlugin::make()');
});

it('succeeds and says so when there is no panel provider to write to', function () {
    [$exitCode, $output] = finCodexRunCommand('fin-codex:install');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No panel providers found');
});

it('succeeds and says Shield was not found when it is not installed', function () {
    TempAppTree::writePanelProvider('admin');

    expect(file_exists(TempAppTree::shieldConfigPath()))->toBeFalse();

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Filament Shield is not installed');
});

it('writes the article resource into the Shield manage array with all nine abilities', function () {
    TempAppTree::writePanelProvider('admin');
    $shield = TempAppTree::writeShieldConfig();

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents($shield);

    expect($exitCode)->toBe(0)
        ->and($content)->toContain('\FinityLabs\FinCodex\Resources\ArticleResource::class => [')
        ->and(TempAppTree::lints($shield))->toBeTrue();

    // The written file still parses into the shape Shield reads, the host's
    // own entry survives, and all nine abilities are on ours.
    $config = require $shield;

    expect($config['resources']['manage'])->toHaveCount(2)
        ->and($config['resources']['manage']['FinityLabs\FinCodex\Resources\ArticleResource'])
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'import', 'convert', 'viewAllPanels']);
});

it('does not add a second Shield entry on a repeated install', function () {
    TempAppTree::writePanelProvider('admin');
    $shield = TempAppTree::writeShieldConfig();

    [$first] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);
    [$second, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents($shield);

    expect($first)->toBe(0)
        ->and($second)->toBe(0)
        ->and($output)->toContain('already registered in the Shield config')
        ->and(substr_count($content, 'FinCodex\\Resources\\ArticleResource'))->toBe(1)
        ->and(TempAppTree::lints($shield))->toBeTrue();
});

it('prints the page nudge, because Shield discovers pages rather than reading them from config', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeShieldConfig();

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    // All three pages, Help center included: it carries HasPageShieldSupport
    // like the other two, so on a Shield host its permission has to be
    // generated before any role can be given the release's reading surface.
    expect($exitCode)->toBe(0)
        ->and($output)->toContain('--page=HelpSettings,HelpCoverage,HelpCenter');
});

/*
 * What the install does with shield:generate's answer.
 *
 * UAT test 7 found the install reporting "Shield permissions and policies
 * generated" over a run that had in fact skipped the Article policy — the one
 * message that would have told the user why ticking the permission changed
 * nothing. The harness has no Shield, so the run is canned through a seam on a
 * fixture subclass registered over the shipped command's own name.
 */

/** Register the fixture over fin-codex:install and can shield:generate's answer. */
function finCodexShieldStubInstall(int $exitCode, string $output): void
{
    ShieldStubInstallCommand::$shieldExitCode = $exitCode;
    ShieldStubInstallCommand::$shieldOutput = $output;

    Artisan::registerCommand(new ShieldStubInstallCommand);
}

it('prints what shield:generate said', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeShieldConfig();

    finCodexShieldStubInstall(0, 'Permissions generated for the article resource'."\n".ShieldStubInstallCommand::SKIP_LINE);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Permissions generated for the article resource')
        ->and($output)->toContain(ShieldStubInstallCommand::SKIP_LINE)
        ->and($output)->toContain('Shield permissions and policies generated')
        // The pages are a separate run whatever shield:generate said about the
        // resource, and that second nudge names the same three pages.
        ->and($output)->toContain('--page=HelpSettings,HelpCoverage,HelpCenter');
});

it('says in plain words that the skipped Article policy is expected', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeShieldConfig();

    finCodexShieldStubInstall(0, ShieldStubInstallCommand::SKIP_LINE);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('fin-codex registers its own')
        ->and($output)->toContain('nothing to fix');
});

it('adds no note when shield skipped nothing', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeShieldConfig();

    finCodexShieldStubInstall(0, 'Permissions generated for the article resource');

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('fin-codex registers its own');
});

it('prints the output of a failed run and still falls back to the manual command', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeShieldConfig();

    finCodexShieldStubInstall(1, 'SQLSTATE[42S02]: Base table or view not found: permissions');

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('SQLSTATE[42S02]')
        ->and($output)->toContain('Could not generate the Shield permissions automatically')
        ->and($output)->toContain('php artisan shield:generate --panel=admin --option=policies_and_permissions')
        // A run that failed configured nothing, so the next step that sends the
        // user to Shield's role screen is not offered.
        ->and($output)->not->toContain('Assign permissions');
});

it('names the ability that lifts the panel scope in its next steps', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeShieldConfig();

    finCodexShieldStubInstall(0, ShieldStubInstallCommand::SKIP_LINE);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Assign permissions')
        ->and($output)->toContain('viewAllPanels');
});

it('publishes no core migration, and of lin-codex only the config it has to edit', function () {
    TempAppTree::writePanelProvider('admin');

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $published = is_dir(database_path('migrations'))
        ? glob(database_path('migrations').'/*codex*')
        : [];

    expect($exitCode)->toBe(0)
        ->and($published)->toBe([])
        // Since 0.5.0 the one exception: the install switches the public help
        // center off, and the only place that value can be written is the
        // published config file.
        ->and((string) file_get_contents(TempAppTree::linCodexConfigPath()))
        ->toContain("'help_center' => null,");
});

/*
 * The two steps that arrived with fin-support and lin-support: the
 * languages, and the starter articles about the help system.
 */

it('configures the languages from --locales, with the application locale as the default', function () {
    TempAppTree::writePanelProvider('admin');
    config()->set('app.locale', 'de');

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en,de,hu', '--skip-starter-articles' => true]);

    $settings = app(CodexSettings::class)->refresh();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Languages configured: en, de, hu (default de)')
        ->and(array_column($settings->languages, 'code'))->toBe(['en', 'de', 'hu'])
        ->and($settings->languages[2])->toBe(['code' => 'hu', 'display' => 'Magyar', 'flag-icon' => 'hu'])
        ->and($settings->default_locale)->toBe('de');
});

it('takes the installed locales without a prompt when --locales is absent and the run is not interactive', function () {
    TempAppTree::writePanelProvider('admin');

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--skip-starter-articles' => true]);

    expect($exitCode)->toBe(0)
        ->and(array_column(app(CodexSettings::class)->refresh()->languages, 'code'))->toBe(InstalledLocales::detect());
});

it('imports the starter articles in the configured languages only, as database articles', function () {
    TempAppTree::writePanelProvider('admin');

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en,de']);

    $articles = Article::query()->orderBy('slug')->get();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Starter articles imported in en, de')
        ->and($articles->pluck('slug')->all())->toBe(InstallCommand::starterSlugs())
        ->and($articles->pluck('source_path')->unique()->all())->toBe([null])
        ->and(ArticleTranslation::query()->distinct()->pluck('locale')->sort()->values()->all())->toBe(['de', 'en'])
        ->and(ArticleTranslation::query()->count())->toBe(24)
        ->and($articles->firstWhere('slug', 'help/settings')?->contexts()->value('key'))->toBe(HelpSettings::class)
        // The package's docs folder is not left behind as a content source.
        ->and(config('lin-codex.sources.filesystem.paths'))->not->toContain(InstallCommand::starterDocsPath());
});

it('refreshes a stale starter article on a repeated install and leaves an edited one alone', function () {
    TempAppTree::writePanelProvider('admin');

    finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en']);
    enableRevisions(true);

    // The install was yesterday. Both stamps go back together, which is what
    // the import leaves behind, and it puts the host's edit below a
    // measurable distance later — the two columns hold whole seconds, so an
    // edit made in the same second as the install would be unprovable.
    ArticleTranslation::query()->update(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

    // The state an upgrade lands in, in one database: an article the host
    // has made their own, and an article nobody has touched since it was
    // imported that still carries the text an older version shipped.
    Article::query()->where('slug', 'help')->sole()->translations()->where('locale', 'en')->update(['title' => 'Edited by the admin']);

    $stale = Article::query()->where('slug', 'help/writing-articles')->sole();
    $oldBody = "# Writing articles\n\nThe text an older version shipped.";

    ArticleTranslation::query()
        ->where('article_id', $stale->id)
        ->where('locale', 'en')
        // updated_at is not fillable, and it has to go back to created_at:
        // that pair is what says nothing has been written to the row since
        // the import that created it.
        ->update(['body' => $oldBody, 'updated_at' => DB::raw('created_at')]);

    $before = [$stale->is_published, $stale->visibility, $stale->sort_order, $stale->contexts()->orderBy('id')->pluck('key')->all()];

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en']);

    $stale->refresh();
    $after = [$stale->is_published, $stale->visibility, $stale->sort_order, $stale->contexts()->orderBy('id')->pluck('key')->all()];
    $revision = ArticleRevision::query()->where('article_id', $stale->id)->where('locale', 'en')->sole();

    expect($exitCode)->toBe(0)
        // Naming one slug and no other is the differential: the ten articles
        // that already carry the shipped text are reported nowhere.
        ->and($output)->toContain('  Starter articles refreshed in en: help/writing-articles'.PHP_EOL)
        ->and($output)->toContain('  Starter articles you have edited, left as they are: help (en)'.PHP_EOL)
        ->and($output)->not->toContain('already present')
        ->and(Article::query()->count())->toBe(12)
        ->and($stale->translations()->where('locale', 'en')->value('body'))->toBe(finCodexShippedBody('help/writing-articles', 'en'))
        ->and(ArticleTranslation::query()->where('locale', 'en')->where('title', 'Edited by the admin')->count())->toBe(1)
        // The replaced body is recoverable, attributed to the import.
        ->and($revision->reason)->toBe(RevisionReason::Import)
        ->and($revision->body)->toBe($oldBody)
        // A docs refresh moves the text and nothing else: not where the
        // article appears, not whether it appears, not in what order.
        ->and($after)->toBe($before);
});

it('fills in a language configured after the install', function () {
    TempAppTree::writePanelProvider('admin');

    finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en']);

    expect(ArticleTranslation::query()->distinct()->pluck('locale')->all())->toBe(['en']);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en,de']);

    expect($exitCode)->toBe(0)
        // Every slug exists already, so the importer skips all twelve and the
        // refresh is the only thing that can write the new language.
        ->and($output)->toContain('  Starter articles refreshed in de: '.implode(', ', InstallCommand::starterSlugs()).PHP_EOL)
        ->and($output)->not->toContain('you have edited')
        ->and(Article::query()->count())->toBe(12)
        ->and(ArticleTranslation::query()->where('locale', 'de')->count())->toBe(12)
        ->and(Article::query()->where('slug', 'help')->sole()->translations()->where('locale', 'de')->value('body'))->toBe(finCodexShippedBody('help', 'de'));
});

it('imports nothing with --skip-starter-articles, and nothing when no configured language has starter articles', function () {
    TempAppTree::writePanelProvider('admin');

    finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en', '--skip-starter-articles' => true]);

    expect(Article::query()->count())->toBe(0);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'fr']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('none of the configured languages match')
        ->and(Article::query()->count())->toBe(0);
});

it('attaches the starter articles to their pages whatever the default language is', function () {
    TempAppTree::writePanelProvider('admin');
    config()->set('app.locale', 'hu');

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'hu,en']);

    $settings = app(CodexSettings::class)->refresh();
    $contexts = fn (string $slug): array => Article::query()->where('slug', $slug)->sole()->contexts()->pluck('key')->all();

    expect($exitCode)->toBe(0)
        // The host's own default is untouched by the import.
        ->and($settings->default_locale)->toBe('hu')
        ->and($contexts('help'))->toBe(['Filament\\Pages\\Dashboard'])
        ->and($contexts('help/writing-articles'))->toBe([ArticleResource::class])
        ->and($contexts('help/coverage'))->toBe([HelpCoverage::class])
        // Proven from the database as well as from the files: this is the
        // context the Help Center's own coverage row is closed by.
        ->and($contexts('help/help-center'))->toBe([HelpCenter::class])
        ->and($contexts('help/settings'))->toBe([HelpSettings::class])
        ->and($contexts('help/help-in-code'))->toBe([ArticleResource::class])
        ->and(Article::query()->where('slug', 'help')->sole()->sort_order)->toBe(1)
        ->and(Article::query()->where('slug', 'help/help-in-code')->sole()->sort_order)->toBe(6);
});

it('stamps the installed panel onto the starter articles\' pages, and leaves them panel-less without one', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writePanelProvider('staff');

    finCodexRunCommand('fin-codex:install', ['--panel' => 'staff', '--locales' => 'en']);

    $panels = ArticleContext::query()->pluck('panel_id')->unique()->all();

    expect(Article::query()->count())->toBe(12)
        ->and($panels)->toBe(['staff'])
        // The guest pages' articles are public, so a visitor to the staff login sees them.
        ->and(Article::query()->where('slug', 'account/signing-in')->sole()->visibility)->toBe(Visibility::Public);

    // Through the models, so the core's hooks cascade the contexts with the rows.
    Article::query()->get()->each->delete();
    TempAppTree::cleanup();

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--locales' => 'en']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No panel providers found')
        ->and(Article::query()->count())->toBe(12)
        ->and(ArticleContext::query()->whereNotNull('panel_id')->count())->toBe(0);
});

it('gives a visitor on the sign-in page the sign-in article, and nothing from the authenticated section', function () {
    TempAppTree::writePanelProvider('admin');

    finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en']);

    // The harness's own admin panel, signed out: the drawer under the login
    // form lists exactly the public article attached to Filament's login page.
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain('data-codex-page-count="1"')
        ->toContain('data-codex-page-article="account/signing-in"')
        ->not->toContain('data-codex-page-article="help');
});

/*
 * PLACE-04. The public help center goes off at install time.
 *
 * Every row reads config/lin-codex.php back from disk rather than asking
 * config(): the test application loaded its configuration at bootstrap and the
 * command wrote a file, so config() would still be answering with the value the
 * run started from. Where a row needs the command to see a published value, it
 * sets the config key too — that is what a real host's bootstrap would have
 * done with the file already on disk.
 */

it('publishes the core config and switches the public help center off', function () {
    TempAppTree::writePanelProvider('admin');

    expect(file_exists(TempAppTree::linCodexConfigPath()))->toBeFalse();

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents(TempAppTree::linCodexConfigPath());

    expect($exitCode)->toBe(0)
        ->and($content)->toContain("'help_center' => null,")
        ->and($output)->toContain('Public help center switched off');
});

it('leaves the core config byte for byte when the public help center is already off', function () {
    TempAppTree::writePanelProvider('admin');
    $path = TempAppTree::writeLinCodexConfig(null);
    config(['lin-codex.routes.help_center' => null]);

    $before = (string) file_get_contents($path);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('already off')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('takes the default and switches over a prefix the host chose, leaving the rest of the file alone', function () {
    TempAppTree::writePanelProvider('admin');
    $path = TempAppTree::writeLinCodexConfig('/manual');
    config(['lin-codex.routes.help_center' => '/manual']);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents($path);

    // The question itself is never echoed: confirm() under --no-interaction
    // takes its default without asking, which is the behaviour this row is
    // here for. What the prompt says is proven by the declined row below.
    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Public help center switched off')
        ->and($content)->toContain("'help_center' => null,")
        ->and($content)->not->toContain('/manual')
        // The longer key two lines below keeps its own line, and the comment
        // block a host is meant to read survives the edit.
        ->and($content)->toContain("'help_center_layout' => null,")
        ->and($content)->toContain("'assets' => '/codex/assets',")
        ->and($content)->toContain('The prefix the public help center is mounted under.');
});

it('declines a core config it does not recognise instead of guessing at it', function () {
    TempAppTree::writePanelProvider('admin');

    $path = TempAppTree::linCodexConfigPath();
    file_put_contents($path, str_replace(
        "        'help_center' => {{help_center}},\n",
        '',
        TempAppTree::LIN_CODEX_CONFIG,
    ));

    $before = (string) file_get_contents($path);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Could not edit config/lin-codex.php')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('leaves the core config byte for byte when the switch is declined', function () {
    TempAppTree::writePanelProvider('admin');
    $path = TempAppTree::writeLinCodexConfig('/help');
    $before = (string) file_get_contents($path);

    Artisan::registerCommand(new DecliningInstallCommand);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Left as it is')
        ->and($output)->not->toContain('Public help center switched off')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('names the public help center in its next steps only when the switch happened', function () {
    TempAppTree::writePanelProvider('admin');

    [$first, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($first)->toBe(0)
        ->and($output)->toContain('/help is off; help now lives at {panel}/help inside the panel');

    TempAppTree::cleanup();
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeLinCodexConfig(null);
    config(['lin-codex.routes.help_center' => null]);

    [$second, $again] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    expect($second)->toBe(0)
        ->and($again)->not->toContain('/help is off; help now lives at');
});
