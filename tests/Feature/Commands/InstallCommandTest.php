<?php

use FinityLabs\FinCodex\Commands\InstallCommand;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\TempAppTree;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinSupport\Locale\InstalledLocales;
use Illuminate\Support\Facades\Artisan;

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

it('writes the article resource into the Shield manage array with all eight abilities', function () {
    TempAppTree::writePanelProvider('admin');
    $shield = TempAppTree::writeShieldConfig();

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $content = (string) file_get_contents($shield);

    expect($exitCode)->toBe(0)
        ->and($content)->toContain('\FinityLabs\FinCodex\Resources\ArticleResource::class => [')
        ->and(TempAppTree::lints($shield))->toBeTrue();

    // The written file still parses into the shape Shield reads, the host's
    // own entry survives, and all eight abilities are on ours.
    $config = require $shield;

    expect($config['resources']['manage'])->toHaveCount(2)
        ->and($config['resources']['manage']['FinityLabs\FinCodex\Resources\ArticleResource'])
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'import', 'convert']);
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

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('--page=HelpSettings,HelpCoverage');
});

it('publishes nothing of lin-codex', function () {
    TempAppTree::writePanelProvider('admin');

    [$exitCode] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin']);

    $published = is_dir(database_path('migrations'))
        ? glob(database_path('migrations').'/*codex*')
        : [];

    expect($exitCode)->toBe(0)
        ->and($published)->toBe([])
        ->and(file_exists(config_path('lin-codex.php')))->toBeFalse();
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
        ->and(ArticleTranslation::query()->count())->toBe(22)
        ->and($articles->firstWhere('slug', 'help/settings')?->contexts()->value('key'))->toBe(HelpSettings::class)
        // The package's docs folder is not left behind as a content source.
        ->and(config('lin-codex.sources.filesystem.paths'))->not->toContain(InstallCommand::starterDocsPath());
});

it('leaves existing starter articles alone on a repeated install', function () {
    TempAppTree::writePanelProvider('admin');

    finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en']);
    Article::query()->where('slug', 'help')->sole()->translations()->where('locale', 'en')->update(['title' => 'Edited by the admin']);

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--panel' => 'admin', '--locales' => 'en']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('already present')
        ->and(Article::query()->count())->toBe(11)
        ->and(ArticleTranslation::query()->where('locale', 'en')->where('title', 'Edited by the admin')->count())->toBe(1);
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
        ->and($contexts('help/settings'))->toBe([HelpSettings::class])
        ->and($contexts('help/help-in-code'))->toBe([ArticleResource::class])
        ->and(Article::query()->where('slug', 'help')->sole()->sort_order)->toBe(1)
        ->and(Article::query()->where('slug', 'help/help-in-code')->sole()->sort_order)->toBe(5);
});

it('stamps the installed panel onto the starter articles\' pages, and leaves them panel-less without one', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writePanelProvider('staff');

    finCodexRunCommand('fin-codex:install', ['--panel' => 'staff', '--locales' => 'en']);

    $panels = ArticleContext::query()->pluck('panel_id')->unique()->all();

    expect(Article::query()->count())->toBe(11)
        ->and($panels)->toBe(['staff'])
        // The guest pages' articles are public, so a visitor to the staff login sees them.
        ->and(Article::query()->where('slug', 'account/signing-in')->sole()->visibility)->toBe(Visibility::Public);

    // Through the models, so the core's hooks cascade the contexts with the rows.
    Article::query()->get()->each->delete();
    TempAppTree::cleanup();

    [$exitCode, $output] = finCodexRunCommand('fin-codex:install', ['--locales' => 'en']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No panel providers found')
        ->and(Article::query()->count())->toBe(11)
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
