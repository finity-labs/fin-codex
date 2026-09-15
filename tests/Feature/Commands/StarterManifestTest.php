<?php

use FinityLabs\FinCodex\Commands\InstallCommand;
use FinityLabs\FinCodex\Starter\StarterManifest;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Data\TranslationData;
use FinityLabs\LinCodex\Sources\FilesystemSource;

/*
 * The starter manifest, resources/docs/manifest.json: a hash of every text
 * this package has ever shipped for a starter article, per slug and locale.
 * The install's refresh reads it to tell a translation the host never touched
 * (its text is one the package shipped) from one the host rewrote.
 *
 * The first row is the gate that keeps the manifest complete. When a starter
 * article is edited, run
 *
 *   FIN_CODEX_WRITE_STARTER_MANIFEST=1 vendor/bin/pest tests/Feature/Commands/StarterManifestTest.php
 *
 * and commit the manifest with the docs. The variable may also name a docs
 * folder of an older version, which is how the hashes of every release since
 * 0.4.0 were added: hashes are only ever appended, never removed, so a host
 * who installed any of them is still refreshed.
 */

/**
 * The starter set read through the core's own file source, the way the
 * command reads it, from the package docs or from a folder of an older
 * version's docs.
 *
 * @return array<string, ArticleData>
 */
function finCodexShippedSet(?string $docsPath = null): array
{
    $key = 'lin-codex.sources.filesystem.paths';
    $hostPaths = config($key, []);

    config()->set($key, [$docsPath ?? InstallCommand::starterDocsPath()]);
    app()->forgetInstance(FilesystemSource::class);

    try {
        return app(FilesystemSource::class)->set()->articles;
    } finally {
        config()->set($key, $hostPaths);
        app()->forgetInstance(FilesystemSource::class);
    }
}

it('knows every text this version ships', function () {
    $write = (string) getenv('FIN_CODEX_WRITE_STARTER_MANIFEST');
    $docsPath = $write !== '' && $write !== '1' ? $write : null;

    $manifest = new StarterManifest;
    $missing = [];

    foreach (finCodexShippedSet($docsPath) as $slug => $article) {
        foreach ($article->translations as $locale => $translation) {
            $hash = StarterManifest::hash($translation);

            if ($manifest->knows($slug, $locale, $hash)) {
                continue;
            }

            $missing[] = "{$slug} ({$locale})";
            $manifest = $manifest->with($slug, $locale, $hash);
        }
    }

    if ($write !== '') {
        $manifest->write();

        expect(is_file(StarterManifest::path()))->toBeTrue();

        return;
    }

    expect($missing)->toBe([], 'The starter manifest is behind the docs for: '.implode(', ', $missing).'. Run FIN_CODEX_WRITE_STARTER_MANIFEST=1 vendor/bin/pest '.__FILE__.' and commit resources/docs/manifest.json.');
});

it('answers only for a hash it holds', function () {
    $manifest = new StarterManifest(['help' => ['en' => ['abc']]]);

    expect($manifest->knows('help', 'en', 'abc'))->toBeTrue()
        ->and($manifest->knows('help', 'de', 'abc'))->toBeFalse()
        ->and($manifest->knows('help', 'en', 'xyz'))->toBeFalse()
        ->and($manifest->with('help', 'de', 'xyz')->knows('help', 'de', 'xyz'))->toBeTrue()
        // with() hands back a copy; the original is unchanged.
        ->and($manifest->knows('help', 'de', 'xyz'))->toBeFalse();
});

it('hashes a blank excerpt and a missing one alike', function () {
    $a = new TranslationData('en', 'Title', null, 'Body', null);
    $b = new TranslationData('en', 'Title', '', 'Body', null);
    $c = new TranslationData('en', 'Title', 'Excerpt', 'Body', null);

    expect(StarterManifest::hash($a))->toBe(StarterManifest::hash($b))
        ->and(StarterManifest::hash($a))->not->toBe(StarterManifest::hash($c));
});
