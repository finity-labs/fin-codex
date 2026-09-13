<?php

use FinityLabs\FinCodex\Commands\UninstallCommand;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\TempAppTree;
use FinityLabs\FinCodex\Tests\TestCase;
use FinityLabs\FinSupport\Pages\Concerns\HasPageShieldSupport;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
 * CLI-01, the uninstall half.
 *
 * The rule this file exists to hold is the last one: fin-codex:uninstall
 * removes what fin-codex added to a panel provider and to the Shield config,
 * and NEVER touches a codex_* table. Articles, translations, contexts,
 * revisions and media belong to lin-codex and survive removing the Filament
 * layer — codex:uninstall is the command that removes those.
 *
 * The console output is un-mocked for the same reason as in
 * InstallCommandTest: PendingCommand's Mockery double throws on any
 * un-expected confirm(), which would hide rather than prove the
 * --no-interaction behaviour.
 */

afterEach(function () {
    TempAppTree::cleanup();
});

/**
 * Run a fin-codex command non-interactively and hand back its exit code and
 * its real output. Named apart from InstallCommandTest's twin on purpose:
 * Pest helpers are global functions, so a shared name would be a fatal
 * redeclaration in a full-suite run, and a single-file run never loads the
 * other file.
 *
 * @param  array<string, mixed>  $parameters
 *
 * @return array{0: int, 1: string}
 */
function finCodexRunUninstallCommand(string $command, array $parameters = []): array
{
    test()->withoutMockingConsoleOutput();

    $exitCode = test()->artisan($command, [...$parameters, '--no-interaction' => true]);

    return [$exitCode, Artisan::output()];
}

it('removes the plugin registration and leaves the provider valid PHP', function () {
    $path = TempAppTree::writePanelProvider('admin');

    finCodexRunUninstallCommand('fin-codex:install', ['--panel' => 'admin']);

    expect((string) file_get_contents($path))->toContain('FinCodexPlugin::make()');

    [$exitCode] = finCodexRunUninstallCommand('fin-codex:uninstall');

    $content = (string) file_get_contents($path);

    expect($exitCode)->toBe(0)
        ->and($content)->not->toContain('FinCodexPlugin::make()')
        ->and($content)->not->toContain('use FinityLabs\FinCodex\FinCodexPlugin;')
        ->and(TempAppTree::lints($path))->toBeTrue();
});

it('is a round trip: the provider comes back byte for byte', function () {
    $path = TempAppTree::writePanelProvider('admin');
    $before = (string) file_get_contents($path);

    finCodexRunUninstallCommand('fin-codex:install', ['--panel' => 'admin']);
    finCodexRunUninstallCommand('fin-codex:uninstall');

    expect((string) file_get_contents($path))->toBe($before);
});

it('says so and still succeeds when the plugin was never registered', function () {
    $path = TempAppTree::writePanelProvider('admin');

    [$exitCode] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($path))->not->toContain('FinCodexPlugin')
        ->and(TempAppTree::lints($path))->toBeTrue();
});

it('succeeds and says so when there is no panel provider at all', function () {
    [$exitCode, $output] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No panel providers found');
});

it('drops the Codex entry from the Shield config and leaves the host entry alone', function () {
    TempAppTree::writePanelProvider('admin');
    $shield = TempAppTree::writeShieldConfig();

    finCodexRunUninstallCommand('fin-codex:install', ['--panel' => 'admin']);

    expect((string) file_get_contents($shield))->toContain('FinCodex\\Resources\\ArticleResource');

    [$exitCode] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($shield))->not->toContain('FinCodex')
        ->and(TempAppTree::lints($shield))->toBeTrue();

    $config = require $shield;

    expect($config['resources']['manage'])->toHaveCount(1)
        ->and(array_key_first($config['resources']['manage']))
        ->toBe('App\Filament\Resources\Users\UserResource');
});

/*
 * The page list is read from the command rather than exercised through Shield:
 * the harness has no Shield at all, so shieldPermissionNames() returns nothing
 * here and a behavioural row could only prove the empty branch. What matters is
 * that the list holds every page that asks Shield for its permission — a page
 * missing from it leaves its permission rows behind on an uninstall, which is
 * how Help center was missed when 0.5.0 added it.
 */
it('deletes the Shield permissions of every page that carries Shield support', function () {
    $shieldedPages = collect((array) glob(dirname(__DIR__, 3).'/src/Pages/*.php'))
        ->map(fn ($file): string => 'FinityLabs\\FinCodex\\Pages\\'.basename((string) $file, '.php'))
        ->filter(fn (string $page): bool => in_array(HasPageShieldSupport::class, class_uses_recursive($page), true))
        ->values()
        ->all();

    expect($shieldedPages)->toEqualCanonicalizing([HelpSettings::class, HelpCoverage::class, HelpCenter::class])
        ->and(UninstallCommand::SHIELD_PAGES)->toEqualCanonicalizing($shieldedPages);
});

it('never drops a codex table and never deletes an article', function () {
    TempAppTree::writePanelProvider('admin');

    Article::factory()->create(['slug' => 'intro']);
    Article::factory()->create(['slug' => 'users']);

    finCodexRunUninstallCommand('fin-codex:install', ['--panel' => 'admin', '--skip-starter-articles' => true]);

    [$exitCode, $output] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0);

    foreach (TestCase::PACKAGE_MIGRATIONS as $migration) {
        $table = (string) str_replace(['create_', '_table'], '', $migration);

        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Article::query()->count())->toBe(2)
        ->and(Article::query()->pluck('slug')->all())->toEqualCanonicalizing(['intro', 'users'])
        ->and($output)->toContain('were NOT touched')
        ->and($output)->toContain('lin-codex-ai')
        ->and($output)->toContain('codex:uninstall');
});

it('leaves a host without spatie/laravel-permission untouched', function () {
    TempAppTree::writePanelProvider('admin');
    TempAppTree::writeShieldConfig();

    expect(Schema::hasTable('permissions'))->toBeFalse();

    finCodexRunUninstallCommand('fin-codex:install', ['--panel' => 'admin']);

    [$exitCode] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0);
});

/*
 * PLACE-04, the uninstall half. Removing fin-codex removes the Help Center
 * page inside the panel, so the public one is offered back — and unlike the
 * two prompts above it, this one defaults to yes.
 */

it('switches the public help center back on and says where', function () {
    TempAppTree::writePanelProvider('admin');
    $path = TempAppTree::writeLinCodexConfig(null);
    config(['lin-codex.routes.help_center' => null]);

    [$exitCode, $output] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Public help center switched back on at /help')
        ->and((string) file_get_contents($path))->toContain("'help_center' => '/help',");
});

it('leaves a prefix the host is already serving alone', function () {
    TempAppTree::writePanelProvider('admin');
    $path = TempAppTree::writeLinCodexConfig('/manual');
    config(['lin-codex.routes.help_center' => '/manual']);

    $before = (string) file_get_contents($path);

    [$exitCode, $output] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('switched back on')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('publishes nothing when the core config was never published', function () {
    TempAppTree::writePanelProvider('admin');
    config(['lin-codex.routes.help_center' => null]);

    [$exitCode, $output] = finCodexRunUninstallCommand('fin-codex:uninstall');

    expect($exitCode)->toBe(0)
        ->and(file_exists(TempAppTree::linCodexConfigPath()))->toBeFalse()
        ->and($output)->not->toContain('switched back on');
});
