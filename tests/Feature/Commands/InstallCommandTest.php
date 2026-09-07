<?php

use FinityLabs\FinCodex\Tests\Fixtures\TempAppTree;
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
