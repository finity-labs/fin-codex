<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Help;

/**
 * Answers HasHelp::getHelpArticles() from a `protected static array
 * $helpArticles` property the using class declares itself, in one of two
 * shapes: a flat list of slugs that applies to every panel, or a map of
 * `panelId => list` where the `'*'` entry is the default for panels without
 * their own key. A panel with no entry and no `'*'` answers [].
 *
 * The property is deliberately not declared here: PHP requires a property
 * defined in both a trait and its using class to be identical, so declaring it
 * in the trait would make the using class's own initial value a fatal error.
 * A trait cannot implement an interface either, so the class must still
 * `implements HasHelp` for the scanner to pick it up.
 */
trait WithHelp
{
    /**
     * @return list<string>
     */
    public static function getHelpArticles(string $panelId): array
    {
        if (! property_exists(static::class, 'helpArticles')) {
            return [];
        }

        /** @var array<string, list<string>>|list<string> $declared */
        $declared = static::$helpArticles;

        if (array_is_list($declared)) {
            return $declared;
        }

        return $declared[$panelId] ?? $declared['*'] ?? [];
    }
}
