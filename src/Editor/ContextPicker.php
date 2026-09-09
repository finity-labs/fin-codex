<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Resource;
use FinityLabs\LinCodex\Enums\ContextType;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Livewire\LivewireManager;
use Throwable;

/**
 * The option sets behind the contexts repeater: which panels exist, which
 * context types the core knows, and which class and route keys the running
 * application actually registers.
 *
 * The picker is the whole point of the contexts section. A context key is a
 * class name or a route name, both of which are long, both of which are
 * silently wrong when mistyped: nothing fails, the article simply never
 * appears on the page it was written for. Choosing from the registry makes
 * that class of mistake impossible.
 *
 * "Any panel" (the `*` sentinel, stored as a null `panel_id`) is the core's
 * second pass: ContextResolver looks for a context scoped to the current
 * panel first and falls back to the panel-less ones, so an article bound to
 * `class:App\Filament\Resources\UserResource` without a panel is found from
 * every panel that registers the resource. Passing null or `*` here widens
 * the option sets the same way, to the union over every panel.
 *
 * Nothing is memoised. Both sources are already in memory (the panel
 * registry and the router's route collection), the lists are only built
 * while a form renders, and the ignore globs are read on every call on
 * purpose so a host that changes `lin-codex.coverage.ignore` at runtime is
 * honoured. Those globs are lin-codex's own answer to "which routes are
 * worth a help article"; RouteCoverage::isIgnored() is private, so the
 * three-line Str::is() loop is replicated rather than reached into — a
 * subclass would inherit a report() this class has no use for.
 */
final class ContextPicker
{
    /** The "any panel" sentinel; ArticleWriter stores it as a null panel_id. */
    public const ANY_PANEL = '*';

    public function __construct(private readonly Router $router) {}

    /**
     * Every registered panel, "any panel" first. Panels have no display name
     * of their own, so the id is both the value and the label.
     *
     * @return array<string, string>
     */
    public function panels(): array
    {
        $panels = [self::ANY_PANEL => (string) __('fin-codex::fin-codex.editor.contexts.any_panel')];

        foreach (Filament::getPanels() as $panel) {
            $panels[$panel->getId()] = $panel->getId();
        }

        return $panels;
    }

    /**
     * The three context types, keyed by the string form used in front
     * matter and in form state, labelled by the core.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        $types = [];

        foreach (ContextType::cases() as $type) {
            $types[$type->key()] = $type->label();
        }

        return $types;
    }

    /**
     * `class:` keys: the resources and custom pages of one panel, or of every
     * panel when the panel is null or `*`. Labelled with the navigation label
     * the panel itself shows, sorted by that label; the first label wins when
     * two panels register the same class.
     *
     * @return array<string, string>
     */
    public function classKeys(?string $panelId): array
    {
        return array_column($this->classRows($panelId), 'label', 'key');
    }

    /**
     * The same classes as picker rows, one per class in label order: the
     * class as the key, its navigation label, whether it is a resource or a
     * custom page, the path of its first route in the first panel that
     * registers it, and every panel that does — the columns of the modal
     * picker, so an editor choosing between two "Users" sees which is which.
     *
     * @return list<array{key: string, label: string, kind: string, uri: ?string, panel: list<string>}>
     */
    public function classRows(?string $panelId): array
    {
        $rows = [];
        $uris = $this->classUris();

        foreach ($this->panelsFor($panelId) as $panel) {
            foreach ($panel->getResources() as $resource) {
                if (! is_a($resource, Resource::class, true)) {
                    continue;
                }

                $this->addClassRow($rows, ltrim($resource, '\\'), $panel->getId(), 'resource', $uris);
            }

            foreach ($panel->getPages() as $page) {
                $this->addClassRow($rows, ltrim($page, '\\'), $panel->getId(), 'custom_page', $uris);
            }
        }

        uasort($rows, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return array_values($rows);
    }

    /**
     * `route:` keys: every named GET route, minus the names matching a
     * `lin-codex.coverage.ignore` glob, and — for one panel — minus every
     * name outside that panel's `filament.{id}.` prefix. Sorted by key,
     * because the key is what identifies the route; the label is the page's
     * navigation label where the route leads to a Filament page, and the
     * route name itself for a controller or a closure.
     *
     * @return array<string, string>
     */
    public function routeKeys(?string $panelId): array
    {
        return array_column($this->routeRows($panelId), 'label', 'key');
    }

    /**
     * The same routes as picker rows, one per route in key order: the route
     * name as the key, its label, its path, and the panel its name belongs
     * to — null for a route outside Filament, which "any panel" lists too.
     *
     * @return list<array{key: string, label: string, uri: string, panel: ?string}>
     */
    public function routeRows(?string $panelId): array
    {
        $panelId = $this->normalise($panelId);
        $prefix = $panelId === null ? null : 'filament.'.$panelId.'.';
        $ignore = $this->ignorePatterns();
        $rows = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = $route->getName();

            if ($name === null || $name === '') {
                continue;
            }

            if ($prefix !== null && ! str_starts_with($name, $prefix)) {
                continue;
            }

            foreach ($ignore as $pattern) {
                if (Str::is($pattern, $name)) {
                    continue 2;
                }
            }

            $rows[$name] = [
                'key' => $name,
                'label' => $this->routeLabel($route) ?? $name,
                'uri' => $this->path($route),
                'panel' => $this->routePanel($name),
            ];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * The human label of one stored row: the option label for a class or
     * route key, the pattern itself for a url, and null for a blank or
     * unknown key — a key that no longer resolves is a context pointing at
     * something the application dropped.
     */
    public function label(?string $panelId, ?string $type, ?string $key): ?string
    {
        if ($type === null || $key === null || $key === '') {
            return null;
        }

        return match ($type) {
            ContextType::PageClass->key() => $this->classKeys($panelId)[ltrim($key, '\\')] ?? null,
            ContextType::Route->key() => $this->routeKeys($panelId)[$key] ?? null,
            ContextType::Url->key() => $key,
            default => null,
        };
    }

    /**
     * One row per class; a class two panels register keeps the first
     * panel's label and path and lists both panels.
     *
     * @param  array<string, array{key: string, label: string, kind: string, uri: ?string, panel: list<string>}>  $rows
     * @param  array<string, array<string, string>>  $uris
     */
    private function addClassRow(array &$rows, string $class, string $panelId, string $kind, array $uris): void
    {
        if (isset($rows[$class])) {
            if (! in_array($panelId, $rows[$class]['panel'], true)) {
                $rows[$class]['panel'][] = $panelId;
            }

            return;
        }

        $rows[$class] = [
            'key' => $class,
            'label' => $this->navigationLabel($class) ?? $class,
            'kind' => (string) __('fin-codex::fin-codex.editor.contexts.'.$kind),
            'uri' => $uris[$panelId][$class] ?? null,
            'panel' => [$panelId],
        ];
    }

    /**
     * The path of the first route of every Filament page class, per panel,
     * and of every resource through the pages that belong to it — the list
     * page registers first, so a resource shows its index path.
     *
     * @return array<string, array<string, string>>
     */
    private function classUris(): array
    {
        $uris = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $panelId = $name === null ? null : $this->routePanel($name);

            if ($panelId === null) {
                continue;
            }

            $class = $this->pageClass($route);

            if ($class === null) {
                continue;
            }

            $uris[$panelId][$class] ??= $this->path($route);

            if (is_a($class, ResourcePage::class, true)) {
                $uris[$panelId][ltrim($class::getResource(), '\\')] ??= $this->path($route);
            }
        }

        return $uris;
    }

    /** The panel a `filament.{id}.` route name belongs to; null for any other route. */
    private function routePanel(string $name): ?string
    {
        if (! str_starts_with($name, 'filament.')) {
            return null;
        }

        $panelId = Str::before(Str::after($name, 'filament.'), '.');

        return array_key_exists($panelId, Filament::getPanels()) ? $panelId : null;
    }

    private function path(Route $route): string
    {
        return '/'.ltrim($route->uri(), '/');
    }

    /**
     * One panel, or every panel for null / `*` / an id nobody registered.
     *
     * @return list<Panel>
     */
    private function panelsFor(?string $panelId): array
    {
        $panels = array_values(Filament::getPanels());
        $panelId = $this->normalise($panelId);

        if ($panelId === null) {
            return $panels;
        }

        $scoped = array_values(array_filter($panels, static fn (Panel $panel): bool => $panel->getId() === $panelId));

        return $scoped === [] ? $panels : $scoped;
    }

    /**
     * A resource page is labelled by its resource plus its own label
     * ("Users › Create User"), so the three pages of one resource are told
     * apart in the select; a custom page by its own label alone.
     */
    private function routeLabel(Route $route): ?string
    {
        $class = $this->pageClass($route);

        if ($class === null) {
            return null;
        }

        if (is_a($class, ResourcePage::class, true)) {
            $resource = $this->navigationLabel($class::getResource());
            $page = $this->navigationLabel($class);

            return match (true) {
                $resource === null => $page,
                $page === null, $page === $resource => $resource,
                default => $resource.' › '.$page,
            };
        }

        return is_a($class, Page::class, true) ? $this->navigationLabel($class) : null;
    }

    /**
     * The class behind a route, in the three shapes Livewire's route macro
     * uses (an instance, a class-string, a registered component name), then
     * the plain controller class. Mirrors Panel\CurrentPage::livewireClass()
     * and lin-codex's RouteCoverage::pageClass(); a closure route has none.
     *
     * @return class-string|null
     */
    private function pageClass(Route $route): ?string
    {
        $component = $route->getAction('livewire_component');

        if (is_object($component)) {
            return $component::class;
        }

        if (is_string($component) && $component !== '') {
            return class_exists($component) ? ltrim($component, '\\') : $this->namedComponentClass($component);
        }

        $controller = $route->getControllerClass();

        return is_string($controller) && $controller !== '' && class_exists($controller)
            ? ltrim($controller, '\\')
            : null;
    }

    /**
     * A Livewire component registered under a kebab alias; null when the
     * name is unknown or Livewire is not bound.
     *
     * @return class-string|null
     */
    private function namedComponentClass(string $name): ?string
    {
        if (! app()->bound(LivewireManager::class)) {
            return null;
        }

        try {
            return app(LivewireManager::class)->new($name)::class;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The navigation label of a resource or a page. Filament's auth pages
     * and any other component that is neither carries no such method, and a
     * host's label closure may need a panel context this call does not have,
     * so both cases fall back to null and the caller keeps the raw key.
     */
    private function navigationLabel(string $class): ?string
    {
        try {
            if (is_a($class, Resource::class, true)) {
                $label = $class::getNavigationLabel();
            } elseif (is_a($class, Page::class, true)) {
                $label = $class::getNavigationLabel();
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $label === '' ? null : $label;
    }

    /**
     * @return list<string>
     */
    private function ignorePatterns(): array
    {
        $patterns = config('lin-codex.coverage.ignore', []);

        return is_array($patterns) ? array_values(array_filter($patterns, 'is_string')) : [];
    }

    /** `*` and the empty string both mean "any panel", like the writer's own null. */
    private function normalise(?string $panelId): ?string
    {
        return in_array($panelId, [null, '', self::ANY_PANEL], true) ? null : $panelId;
    }
}
