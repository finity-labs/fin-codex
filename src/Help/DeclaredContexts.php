<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Help;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use FinityLabs\LinCodex\Data\ContextData;
use FinityLabs\LinCodex\Enums\ContextType;

/**
 * Turns every HasHelp declaration in the Filament panel registry into
 * lin-codex contexts. The scan is lazy and memoised once per process: panel
 * providers register after this package's provider, so the registry is only
 * complete at the first call, and neither the registry nor the static
 * declarations change afterwards.
 *
 * A resource-level declaration emits a `class:` context on the resource and
 * one exact `route:` context per registered resource page. The drawer resolves
 * a resource page by its resource class (Phase 3 identity) and de-duplicates
 * the doubled slug, so readers see one entry; lin-codex's RouteCoverage
 * matches `class:` keys against the route's page class, never the resource,
 * so the `route:` contexts are what make list, create and edit count as
 * covered. A resource-page declaration emits a `route:` context on that
 * page's own route name; a custom page emits a `class:` context on its class.
 *
 * Sort orders sit in a negative band, SORT_BASE plus the slug's position in
 * the class's answer. Stored sort orders are unsigned, so declared entries
 * lead within their type; the core still orders `class` before `route`, which
 * is why a page-level declaration follows the resource-level ones on that page.
 */
final class DeclaredContexts
{
    public const SORT_BASE = -1_000_000;

    /** @var list<Declaration>|null */
    private ?array $memo = null;

    /**
     * Registry order: panels as registered, then each panel's resources
     * (the resource-level declaration first, then its HasHelp pages), then
     * the panel's custom pages.
     *
     * @return list<Declaration>
     */
    public function declarations(): array
    {
        return $this->memo ??= $this->scan();
    }

    /**
     * Every synthetic context from every class and panel, keyed by slug, in
     * scan order.
     *
     * @return array<string, list<ContextData>>
     */
    public function contextsBySlug(): array
    {
        $bySlug = [];

        foreach ($this->declarations() as $declaration) {
            $bySlug[$declaration->slug] = [...$bySlug[$declaration->slug] ?? [], ...$declaration->contexts];
        }

        return $bySlug;
    }

    /**
     * Drop the memo, for tests that change the registry between calls.
     */
    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * @return list<Declaration>
     */
    private function scan(): array
    {
        $declarations = [];

        foreach (Filament::getPanels() as $panel) {
            $panelId = $panel->getId();

            foreach ($panel->getResources() as $resource) {
                if (! is_a($resource, Resource::class, true)) {
                    continue;
                }

                $registrations = array_values($resource::getPages());

                if (is_a($resource, HasHelp::class, true)) {
                    $keys = [[ContextType::PageClass, ltrim($resource, '\\')]];

                    foreach ($registrations as $registration) {
                        $keys[] = [ContextType::Route, $this->routeName($registration, $panel)];
                    }

                    array_push($declarations, ...$this->declare($resource, $panelId, $keys));
                }

                foreach ($registrations as $registration) {
                    $page = $registration->getPage();

                    if (is_a($page, HasHelp::class, true)) {
                        array_push($declarations, ...$this->declare($page, $panelId, [[ContextType::Route, $this->routeName($registration, $panel)]]));
                    }
                }
            }

            foreach ($panel->getPages() as $page) {
                if (is_a($page, HasHelp::class, true)) {
                    array_push($declarations, ...$this->declare($page, $panelId, [[ContextType::PageClass, ltrim($page, '\\')]]));
                }
            }
        }

        return $declarations;
    }

    /**
     * The page's route name in the scanned panel. The panel is passed
     * explicitly: getRouteName(null) falls back to the current or default
     * panel, which is the wrong one while scanning every panel.
     */
    private function routeName(PageRegistration $registration, Panel $panel): string
    {
        /** @var class-string<Page> $page */
        $page = $registration->getPage();

        return $page::getRouteName($panel);
    }

    /**
     * Ask one class for one panel and build a Declaration per slug, every
     * context carrying SORT_BASE plus the slug's position. Empty strings are
     * dropped; anything that is not a string breaks the HasHelp contract and
     * fails loudly under strict types rather than being cast.
     *
     * @param  class-string<HasHelp>  $class
     * @param  list<array{0: ContextType, 1: string}>  $keys
     *
     * @return list<Declaration>
     */
    private function declare(string $class, string $panelId, array $keys): array
    {
        $slugs = array_values(array_filter(
            $class::getHelpArticles($panelId),
            static fn (string $slug): bool => $slug !== '',
        ));

        $declarations = [];

        foreach ($slugs as $position => $slug) {
            $sortOrder = self::SORT_BASE + $position;

            $contexts = array_map(
                static fn (array $key): ContextData => new ContextData($key[0], $key[1], $panelId, $sortOrder),
                $keys,
            );

            $declarations[] = new Declaration($class, $panelId, $slug, $position, $contexts);
        }

        return $declarations;
    }
}
