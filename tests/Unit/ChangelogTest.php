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
 * already published. The version under test is the newest released heading,
 * so this file needs no edit per release, and the Unreleased section may hold
 * entries: the awk stops at the next `## [` heading either way.
 */
$changelog = static fn (): string => (string) file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');

$latest = static function () use ($changelog): string {
    preg_match('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', $changelog(), $matches);

    return $matches[1] ?? '';
};

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

it('carries a released heading in the shape the release workflow parses', function () use ($latest): void {
    expect($latest())->toMatch('/^\d+\.\d+\.\d+$/');
});

it('keeps the Unreleased heading above the newest released version', function () use ($changelog, $latest): void {
    $content = $changelog();

    $unreleased = strpos($content, '## [Unreleased]');
    $released = strpos($content, '## ['.$latest().']');

    expect($unreleased)->not->toBeFalse('CHANGELOG.md has no ## [Unreleased] heading.')
        ->and($released)->not->toBeFalse('CHANGELOG.md has no released heading.')
        ->and($unreleased)->toBeLessThan($released, '## [Unreleased] must sit above the newest release.');
});

it('extracts a non-empty section for the newest release the way the workflow does', function () use ($section, $latest): void {
    $body = $section($latest());

    expect($body)->not->toBe('', 'The newest CHANGELOG section is empty, so the GitHub release would ship blank notes.')
        ->and($body)->toMatch('/^### (Added|Fixed|Changed)/m')
        ->and($body)->not->toContain('## [');
});
