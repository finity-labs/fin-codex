<?php

use FinityLabs\FinCodex\Enums\NavigationGroup;
use Illuminate\Support\Facades\Lang;

/** @return list<string> Sorted dotted key paths of one locale's lang file. */
function finCodexLangKeys(string $locale): array
{
    $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = is_array($value) ? [...$keys, ...$flatten($value, $path)] : [...$keys, $path];
        }

        sort($keys);

        return $keys;
    };

    return $flatten(finCodexLang($locale));
}

/** @return array<string, mixed> The raw lang array of one locale. */
function finCodexLang(string $locale): array
{
    return require dirname(__DIR__, 2).'/resources/lang/'.$locale.'/fin-codex.php';
}

/** @return array<string, mixed> Dotted leaf key path => value for one locale. */
function finCodexLangValues(string $locale): array
{
    $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $flat += is_array($value) ? $flatten($value, $path) : [$path => $value];
        }

        return $flat;
    };

    return $flatten(finCodexLang($locale));
}

/**
 * Values a translation is allowed to leave byte-identical to the English one.
 *
 * The list is by VALUE, never by key: a key-based list rots the moment someone
 * renames a key, and then silently stops guarding anything.
 *
 * Format, Panel, URI and Name are the words German and Hungarian actually
 * borrow whole — they are the measured identical pairs on today's files
 * (`Format` in de; `Panel` and `URI` in both; `Panels` and `Name` in de). Markdown, HTML and
 * Codex are product names that never translate. Anything added beyond these
 * seven needs a comment saying why the word is the same in all three.
 *
 * @var list<string>
 */
const FIN_CODEX_IDENTICAL_ALLOWED = ['Format', 'Panel', 'Panels', 'URI', 'Name', 'Markdown', 'HTML', 'Codex'];

/**
 * The invariant the two enum rules below protect.
 *
 * Every enum fin-codex displays goes through the core's own `$case->label()`
 * (ArticleForm, ArticlesTable, HelpSettings), which reads
 * `lin-codex::lin-codex.enums.{group}.{key}` through `HasKey::label()`.
 * fin-codex never re-labels a core enum, so a host that retranslates one
 * overrides lin-codex's file and gets a consistent panel — publishing ours
 * would leave half the panel on the old wording.
 *
 * Scoped to the six groups fin-codex actually renders. `search_field` and
 * `search_strategy` are never displayed by this package, and including them
 * costs eight false positives on words fin-codex legitimately owns
 * (Keywords, Title, Excerpt, Body).
 *
 * @var list<string>
 */
const FIN_CODEX_RENDERED_ENUM_GROUPS = [
    'article_format',
    'visibility',
    'context_type',
    'revision_reason',
    'fallback_behaviour',
    'source_warning_kind',
];

/**
 * The fin-codex keys allowed to share a word with a core enum label, each
 * pinned to the one core case it may collide with.
 *
 * `revisions.restore.label` / `.submit` are the verb on the button, not a
 * re-definition of `RevisionReason::Restore` — the reason label still comes
 * from the core when a restore writes a revision. English keeps the two apart
 * only by luck (`Restore` twice) and German by grammar (`Wiederherstellen` the
 * verb vs. `Wiederherstellung` the noun); Hungarian uses `Visszaállítás` for
 * both, so a by-value allowlist would have to name every translation of the
 * word and would still miss the next locale. Pinning the pair says what is
 * actually true, holds in every locale, and keeps failing if the same word
 * turns up under any other key.
 *
 * @var array<string, string>
 */
const FIN_CODEX_ENUM_LABEL_ALLOWED = [
    'revisions.restore.label' => 'enums.revision_reason.restore',
    'revisions.restore.submit' => 'enums.revision_reason.restore',
];

/**
 * The core's rendered enum groups for one locale, read through the translator.
 *
 * Never a filesystem path: `dirname(__DIR__, 3).'/lin-codex'` resolves only in
 * the monorepo, and in the mirror repo lin-codex arrives as
 * `vendor/finity-labs/lin-codex`, which would redden every CI row.
 *
 * @return array<string, array<string, string>>
 */
function linCodexRenderedEnums(string $locale): array
{
    $enums = Lang::get('lin-codex::lin-codex.enums', [], $locale);

    $scoped = [];

    foreach (FIN_CODEX_RENDERED_ENUM_GROUPS as $group) {
        $cases = is_array($enums) ? ($enums[$group] ?? null) : null;

        // A namespace or a locale that stops resolving has to fail loudly
        // here; both rules below would otherwise pass over an empty lookup.
        if (! is_array($cases) || $cases === []) {
            throw new RuntimeException("lin-codex::lin-codex.enums.{$group} did not resolve to a non-empty array in [{$locale}].");
        }

        /** @var array<string, string> $cases */
        $scoped[$group] = $cases;
    }

    expect($scoped)->toHaveCount(count(FIN_CODEX_RENDERED_ENUM_GROUPS));

    return $scoped;
}

/**
 * Every nested group in one locale's fin-codex file, as path => sorted keys.
 *
 * @return array<string, list<string>>
 */
function finCodexLangGroups(string $locale): array
{
    $walk = function (array $values, string $path) use (&$walk): array {
        $keys = array_map(strval(...), array_keys($values));
        sort($keys);

        $groups = [$path => $keys];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $groups += $walk($value, $path === '' ? (string) $key : $path.'.'.$key);
            }
        }

        return $groups;
    };

    return $walk(finCodexLang($locale), '');
}

it('ships the same lang keys in en, de and hu', function (string $locale): void {
    expect(array_keys(finCodexLang($locale)))->toBe(array_keys(finCodexLang('en')))
        ->and(finCodexLangKeys($locale))->toBe(finCodexLangKeys('en'))
        ->and(finCodexLangKeys('en'))->not->toBeEmpty();
})->with(['de', 'hu']);

it('leaves no English string sitting in the de or hu file', function (string $locale): void {
    $translated = finCodexLangValues($locale);

    $untranslated = [];

    foreach (finCodexLangValues('en') as $key => $value) {
        if (! is_string($value) || ($translated[$key] ?? null) !== $value) {
            continue;
        }

        if (in_array($value, FIN_CODEX_IDENTICAL_ALLOWED, true)) {
            continue;
        }

        // ":slug (:locale)" and anything of that shape carries no translatable
        // words at all — only placeholders and punctuation.
        if (trim((string) preg_replace('/:\w+/', '', $value), " \t\n()[]{}·-—:") === '') {
            continue;
        }

        $untranslated[] = "{$locale}.{$key} is still the English string: \"{$value}\"";
    }

    expect($untranslated)->toBe([], PHP_EOL.implode(PHP_EOL, $untranslated));
})->with(['de', 'hu']);

it('never re-defines a core enum label', function (string $locale): void {
    $labels = [];

    foreach (linCodexRenderedEnums($locale) as $group => $cases) {
        foreach ($cases as $case => $label) {
            $labels[$label] ??= "enums.{$group}.{$case}";
        }
    }

    $collisions = [];

    foreach (finCodexLangValues($locale) as $key => $value) {
        if (! is_string($value) || ! isset($labels[$value])) {
            continue;
        }

        if ((FIN_CODEX_ENUM_LABEL_ALLOWED[$key] ?? null) === $labels[$value]) {
            continue;
        }

        $collisions[] = "{$locale}.{$key} = \"{$value}\" re-defines {$labels[$value]}";
    }

    expect($collisions)->toBe([], PHP_EOL.implode(PHP_EOL, $collisions));
})->with(['en', 'de', 'hu']);

it('never carries a core enum group wholesale', function (string $locale): void {
    $ours = finCodexLangGroups($locale);

    $pasted = [];

    foreach (linCodexRenderedEnums($locale) as $group => $cases) {
        $coreKeys = array_map(strval(...), array_keys($cases));
        sort($coreKeys);

        foreach ($ours as $path => $keys) {
            if ($keys === $coreKeys) {
                $pasted[] = "{$locale}.{$path} has the key set of enums.{$group}";
            }
        }
    }

    expect($pasted)->toBe([], PHP_EOL.implode(PHP_EOL, $pasted));
})->with(['en', 'de', 'hu']);

it('loads the package translations under the fin-codex namespace', function (): void {
    expect(__('fin-codex::fin-codex.navigation.group'))->toBe('Help');

    app()->setLocale('de');
    expect(__('fin-codex::fin-codex.navigation.group'))->toBe('Hilfe');

    app()->setLocale('hu');
    expect(NavigationGroup::Help->getLabel())->toBe('Súgó');
});
