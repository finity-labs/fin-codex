<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands\Concerns;

/**
 * The one edit fin-codex makes to lin-codex's published config file: the prefix
 * the core's public help center is mounted under. The installer writes a null
 * there, the uninstaller offers to write a path back.
 *
 * It is a line-level rewrite rather than a parse and a dump. The published file
 * is a couple of hundred lines of commented configuration a host is meant to
 * read; rebuilding it from the package stub, or from an export of the parsed
 * array, would throw all of that away to change one value.
 *
 * It lives in fin-codex rather than in fin-support because fin-support is
 * shared with packages that know nothing about lin-codex.
 */
trait EditsCoreConfig
{
    protected function coreConfigPath(): string
    {
        return config_path('lin-codex.php');
    }

    /**
     * Rewrite the help-center route prefix in the published core config.
     *
     * The closing quote in the pattern is load-bearing. The same array carries
     * a longer key two lines below that begins with the same word, and matching
     * the quote is what keeps this edit off it.
     *
     * Only a value this trait can read is rewritten: null, or one quoted
     * string, followed by the comma that ends the entry. Anything else — an
     * env() call, a constant, an expression — is declined rather than cut at
     * its first comma, which used to leave `null '/help'),` behind and take the
     * whole application down with a parse error. The trailing comma and every
     * surrounding comment survive.
     *
     * The match count is the guard: none means a host reshaped the file or
     * wrote a value of their own, more than one means something unexpected,
     * and both decline rather than guess.
     *
     * @param  string|null  $prefix  null switches the public page off; a string switches it back on
     *
     * @return bool false when the file could not be read or written, or did not carry a recognisable value exactly once
     */
    protected function setCoreRoutePrefix(string $path, ?string $prefix): bool
    {
        $content = file_get_contents($path);

        if ($content === false) {
            return false;
        }

        $value = $prefix === null ? 'null' : "'".addslashes($prefix)."'";

        $updated = preg_replace(
            "/(['\"]help_center['\"]\s*=>\s*)(?:null|'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")(?=\s*,)/",
            '${1}'.$value,
            $content,
            1,
            $count,
        );

        if ($updated === null || $count !== 1) {
            return false;
        }

        return file_put_contents($path, $updated) !== false;
    }
}
