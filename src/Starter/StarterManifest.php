<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Starter;

use FinityLabs\FinCodex\Commands\InstallCommand;
use FinityLabs\LinCodex\Data\TranslationData;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use JsonException;
use RuntimeException;

/**
 * Every text this package has ever shipped for a starter article, as a hash
 * per slug and locale.
 *
 * The install's refresh has to tell a starter translation the host has never
 * touched from one they have rewritten, and it cannot ask the row: timestamps
 * move on every save, the refresh's own included, and a host article that
 * happens to share a starter slug looks exactly like an import. What the row
 * can be asked is whether its text is one the package shipped at some point.
 * If it is, the host has not written to it; if it is not, the host owns it.
 *
 * The file is resources/docs/manifest.json. It grows and never shrinks: a
 * hash from 0.4.0 stays so a host who installed then is still refreshed
 * today. StarterManifestTest keeps it complete and says how to regenerate it.
 */
final class StarterManifest
{
    /**
     * @param  array<string, array<string, list<string>>>  $entries  slug => locale => hashes
     */
    public function __construct(private array $entries = [])
    {
        if ($entries === []) {
            $this->entries = self::read();
        }
    }

    public static function path(): string
    {
        return InstallCommand::starterDocsPath().'/manifest.json';
    }

    /**
     * The fingerprint of one translation's title, excerpt and body — the three
     * values the refresh moves and the importer stores verbatim. A missing
     * excerpt and a blank one are the same text.
     */
    public static function hash(TranslationData|ArticleTranslation $translation): string
    {
        return sha1($translation->title."\0".(string) $translation->excerpt."\0".$translation->body);
    }

    public function knows(string $slug, string $locale, string $hash): bool
    {
        return in_array($hash, $this->entries[$slug][$locale] ?? [], true);
    }

    /** A copy that also knows $hash, for the regeneration test and for tests that need an "old" text. */
    public function with(string $slug, string $locale, string $hash): self
    {
        $entries = $this->entries;

        if (! in_array($hash, $entries[$slug][$locale] ?? [], true)) {
            $entries[$slug][$locale][] = $hash;
        }

        return new self($entries);
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function write(): void
    {
        $entries = $this->entries;
        ksort($entries);

        foreach ($entries as &$locales) {
            ksort($locales);
        }

        unset($locales);

        file_put_contents(self::path(), json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private static function read(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            return [];
        }

        try {
            $entries = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("The starter manifest at {$path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        return is_array($entries) ? $entries : [];
    }
}
