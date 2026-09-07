<?php

/**
 * The release notes on GitHub are built by awk, from CHANGELOG.md, in
 * .github/workflows/split-fin-codex.yml:
 *
 *     $0 ~ "^## \\[" ver "\\]" { capture=1; next }
 *     capture && /^## \[/        { exit }
 *     capture                    { print }
 *
 * So the heading shape is load-bearing. A heading like `## v0.1.0` or
 * `## 0.1.0 (2026-09-07)` matches nothing, the workflow falls back to
 * auto-generated commit notes, and nobody finds out until the release is
 * already published.
 */
$changelog = static fn (): string => (string) file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');

$section = static function (string $version) use ($changelog): string {
    $lines = preg_split('/\R/', $changelog()) ?: [];
    $body = [];
    $capturing = false;

    foreach ($lines as $line) {
        if (preg_match('/^## \['.preg_quote($version, '/').'\]/', $line) === 1) {
            $capturing = true;

            continue;
        }

        if ($capturing && str_starts_with($line, '## [')) {
            break;
        }

        if ($capturing) {
            $body[] = $line;
        }
    }

    return trim(implode("\n", $body));
};

it('carries a 0.1.0 heading in the shape the release workflow parses', function () use ($changelog): void {
    expect($changelog())->toMatch('/^## \[0\.1\.0\] - \d{4}-\d{2}-\d{2}$/m');
});

it('keeps an empty Unreleased heading above the released version', function () use ($changelog): void {
    $content = $changelog();

    $unreleased = strpos($content, '## [Unreleased]');
    $released = strpos($content, '## [0.1.0]');

    expect($unreleased)->not->toBeFalse('CHANGELOG.md has no ## [Unreleased] heading.')
        ->and($released)->not->toBeFalse('CHANGELOG.md has no ## [0.1.0] heading.')
        ->and($unreleased)->toBeLessThan($released, '## [Unreleased] must sit above ## [0.1.0].');

    $between = trim(substr($content, $unreleased + strlen('## [Unreleased]'), $released - $unreleased - strlen('## [Unreleased]')));

    expect($between)->toBe('', 'Nothing may sit between ## [Unreleased] and the released version; the release workflow would print it as part of neither.');
});

it('extracts a non-empty 0.1.0 section the way the workflow does', function () use ($section): void {
    $body = $section('0.1.0');

    expect($body)->not->toBe('', 'The 0.1.0 CHANGELOG section is empty, so the GitHub release would ship blank notes.')
        ->and($body)->toContain('### Added')
        ->and($body)->not->toContain('## [');
});
