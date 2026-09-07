<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Coverage;

use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\SourceWarning;
use FinityLabs\LinCodex\Enums\SourceWarningKind;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * What the content sources complained about while reading, read once per
 * request. It lives beside the coverage report because both are read models
 * over the same source and both are asked on every panel page render (the
 * navigation badges), and because src/Coverage/ is the directory
 * ARCHITECTURE.md reserved for this surface.
 *
 * CompositeSource, DatabaseSource and DeclaredContextsSource memoise nothing,
 * and DeclaredContextsSource::warnings() calls inner->all() on top of
 * inner->warnings(), so this memo is what keeps a page render at one reading.
 */
final class SourceWarnings
{
    private ?Request $memoRequest = null;

    /** @var list<SourceWarning>|null */
    private ?array $memo = null;

    public function __construct(private readonly Application $app) {}

    /**
     * Memoised on the request instance, exactly like Panel\CurrentPage and
     * CoverageReport. An install whose settings group or whose settings table
     * is missing reports nothing rather than throwing: this is read by a
     * navigation badge on every panel page.
     *
     * @return list<SourceWarning>
     */
    public function all(): array
    {
        /** @var Request $request */
        $request = $this->app->make('request');

        if ($this->memo === null || $this->memoRequest !== $request) {
            $this->memoRequest = $request;

            try {
                $this->memo = $this->app->make(ContentSource::class)->warnings();
            } catch (MissingSettings|QueryException) {
                $this->memo = [];
            }
        }

        return $this->memo;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Warnings under their kind, in SourceWarningKind case order, with the
     * core's own translated label and sentence. Grouping happens here and not
     * in Blade (the 05-03 rule): the view stays a conditional-free loop.
     *
     * @return list<array{key: string, label: string, count: int,
     *                    lines: list<array{message: string, path: ?string}>}>
     */
    public function grouped(): array
    {
        /** @var array<string, list<SourceWarning>> $byKind */
        $byKind = [];

        foreach ($this->all() as $warning) {
            $byKind[$warning->kind->key()][] = $warning;
        }

        $groups = [];

        foreach (SourceWarningKind::cases() as $kind) {
            $warnings = $byKind[$kind->key()] ?? [];

            if ($warnings === []) {
                continue;
            }

            $groups[] = [
                'key' => $kind->key(),
                'label' => $kind->label(),
                'count' => count($warnings),
                'lines' => array_map(static fn (SourceWarning $warning): array => [
                    'message' => $warning->message(),
                    'path' => $warning->path,
                ], $warnings),
            ];
        }

        return $groups;
    }
}
