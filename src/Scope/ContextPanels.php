<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Scope;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource;
use FinityLabs\LinCodex\Contexts\PatternMatcher;
use FinityLabs\LinCodex\Data\ContextData;
use FinityLabs\LinCodex\Enums\ContextType;

/**
 * Which Filament panels one lin-codex context belongs to.
 *
 * Three consumers ask that question and must never disagree: the coverage
 * report files every screen under a panel, the panel scope gate hides an
 * article whose contexts all live in other panels, and the Help Center's
 * panel filter offers the answers as its options. They share this class
 * instead of each carrying its own registry walk — the coverage report's
 * private route rule was the first copy and moved here whole.
 *
 * An empty list is the explicit outside-panels bucket: the context resolves
 * into no panel, either because it names a plain Laravel page or because it
 * cannot be resolved at all (an unregistered route name, a class no panel
 * registers, a pattern that opens with a wildcard). Conservative on purpose,
 * and NOT the same thing as general: general is a property of an article
 * that carries no contexts at all, never of a context.
 *
 * Both memos are built once per process in the DeclaredContexts style. The
 * panel providers register after this package's provider, so the first read
 * has to wait for the registry, and the registry does not change afterwards;
 * forget() drops them for a test that registers a panel mid-test.
 */
final class ContextPanels
{
    /** The value a panel filter carries for anything that belongs to no panel. */
    public const OUTSIDE_PANELS = '__outside';

    /** @var array<string, list<string>>|null */
    private ?array $classIndex = null;

    /** @var array<string, string>|null */
    private ?array $paths = null;

    /**
     * The panels one context resolves into, in registry order.
     *
     * An explicit panel prefix wins outright: that is how a HasHelp
     * declaration scopes itself and how an author pins a context to one
     * panel, and it is trusted even when it names a panel this process does
     * not have registered.
     *
     * @return list<string> panel ids; the empty list is the outside-panels bucket
     */
    public function forContext(ContextData $context): array
    {
        if ($context->panelId !== null) {
            return [$context->panelId];
        }

        return match ($context->type) {
            ContextType::PageClass => $this->forClass($context->key),
            ContextType::Route => $this->wrap($this->forRoute($context->key)),
            ContextType::Url => $this->wrap($this->forUrl($context->key)),
        };
    }

    /**
     * The panel a route name belongs to, null for any other route.
     *
     * Every panel route is `filament.{id}.…` (a multi-domain panel inserts
     * the domain after the id), so the id is matched with its trailing dot
     * against the registered panels rather than parsed out of the name: ids
     * that prefix one another would otherwise collide.
     */
    public function forRoute(string $routeName): ?string
    {
        foreach (Filament::getPanels() as $panel) {
            if (str_starts_with($routeName, 'filament.'.$panel->getId().'.')) {
                return $panel->getId();
            }
        }

        return null;
    }

    /**
     * Every panel registering the class — as a resource, as one of a
     * resource's registered pages, or as a custom page — in registry order
     * and once each. A class two panels share belongs to both.
     *
     * @return list<string>
     */
    public function forClass(string $class): array
    {
        $this->classIndex ??= $this->scanClasses();

        return $this->classIndex[PatternMatcher::normaliseClass($class)] ?? [];
    }

    /**
     * The panel whose path prefix claims a url pattern, longest path first,
     * so a root panel (one registered with an empty path) takes only what no
     * other panel claims.
     *
     * The prefix has to end at a segment boundary, which is what keeps
     * `/adminx/foo` off the admin panel. A pattern that opens with a
     * wildcard segment spans every panel and matches no literal prefix, so
     * it belongs to none.
     *
     * Panels are resolved by path alone. A panel served on its own domain
     * shares the path space with the panels on the default domain, and two
     * root-path panels tie on registry order; both are documented rather
     * than solved, because the pattern language carries no host.
     */
    public function forUrl(string $pattern): ?string
    {
        $pattern = PatternMatcher::normalisePath($pattern);

        if (str_contains(explode('/', ltrim($pattern, '/'))[0], '*')) {
            return null;
        }

        $this->paths ??= $this->scanPaths();

        foreach ($this->paths as $panelId => $path) {
            if ($pattern === $path || str_starts_with($pattern, rtrim($path, '/').'/')) {
                return $panelId;
            }
        }

        return null;
    }

    /** Drop both memos, for a test that changes the panel registry between calls. */
    public function forget(): void
    {
        $this->classIndex = null;
        $this->paths = null;
    }

    /**
     * The class-to-panels index, over the DeclaredContexts walk: panels as
     * registered, each panel's resources with their registered pages, then
     * the panel's own pages.
     *
     * @return array<string, list<string>>
     */
    private function scanClasses(): array
    {
        $index = [];

        foreach (Filament::getPanels() as $panel) {
            $panelId = $panel->getId();

            foreach ($panel->getResources() as $resource) {
                if (! is_a($resource, Resource::class, true)) {
                    continue;
                }

                $this->credit($index, $resource, $panelId);

                foreach (array_values($resource::getPages()) as $registration) {
                    $this->credit($index, $registration->getPage(), $panelId);
                }
            }

            foreach ($panel->getPages() as $page) {
                $this->credit($index, $page, $panelId);
            }
        }

        return $index;
    }

    /**
     * @param  array<string, list<string>>  $index
     */
    private function credit(array &$index, string $class, string $panelId): void
    {
        $key = PatternMatcher::normaliseClass($class);
        $index[$key] ??= [];

        if (! in_array($panelId, $index[$key], true)) {
            $index[$key][] = $panelId;
        }
    }

    /**
     * Panel ids keyed to their normalised path, longest path first so the
     * most specific panel wins and a root panel sorts last. PHP 8's sorts
     * are stable, so panels whose paths are the same length keep registry
     * order.
     *
     * @return array<string, string>
     */
    private function scanPaths(): array
    {
        $panels = Filament::getPanels();

        uasort($panels, static fn (Panel $a, Panel $b): int => strlen($b->getPath()) <=> strlen($a->getPath()));

        $paths = [];

        foreach ($panels as $panel) {
            $paths[$panel->getId()] = PatternMatcher::normalisePath($panel->getPath());
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function wrap(?string $panelId): array
    {
        return $panelId !== null ? [$panelId] : [];
    }
}
