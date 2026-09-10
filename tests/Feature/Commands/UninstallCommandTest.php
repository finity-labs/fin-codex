<?php

use FinityLabs\FinCodex\Tests\Fixtures\TempAppTree;
use FinityLabs\FinCodex\Tests\TestCase;
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
