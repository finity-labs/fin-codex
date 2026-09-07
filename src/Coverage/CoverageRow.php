<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Coverage;

use FinityLabs\LinCodex\Coverage\RouteCoverageRow;

/**
 * One screen in the coverage report: either a Filament page or resource with
 * every route it registers folded into it, or a single route that has no
 * Filament page behind it.
 */
final readonly class CoverageRow
{
    /**
     * @param  string  $key  == $routes[0]->name; the table's __key and the action's recordKey
     * @param  string|null  $panelId  null means "outside panels"
     * @param  string|null  $helpClass  resource class / custom page class; null for a standalone route row
     * @param  string  $label  ContextPicker::label(), or a humanised fallback
     * @param  list<RouteCoverageRow>  $routes  in report order (route name), never empty
     * @param  string|null  $matchedBy  ContextData::toString() of the winning context, null when uncovered
     * @param  bool  $isDeclared  the winning context came from a HasHelp declaration
     * @param  bool  $isFileOnly  the matched article has no database row
     * @param  int|null  $articleId  the matched article's id; null when uncovered or file-only
     */
    public function __construct(
        public string $key,
        public ?string $panelId,
        public ?string $helpClass,
        public string $label,
        public array $routes,
        public ?string $matchedBy,
        public ?string $slug,
        public bool $isDeclared,
        public bool $isFileOnly,
        public ?int $articleId,
    ) {}

    public function covered(): bool
    {
        return $this->slug !== null;
    }

    public function routeCount(): int
    {
        return count($this->routes);
    }

    /**
     * @return list<string>
     */
    public function routeNames(): array
    {
        return array_map(static fn (RouteCoverageRow $route): string => $route->name, $this->routes);
    }

    /**
     * The table row. Filament copies the array key onto `__key`
     * (ArrayRecord::getKeyName()), so the page keys this by `$row->key`.
     *
     * @return array{key: string, label: string, panel: ?string, help_class: ?string,
     *               route: string, routes: int, uri: string, matched: ?string,
     *               slug: ?string, covered: bool, declared: bool, file_only: bool,
     *               article_id: ?int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'panel' => $this->panelId,
            'help_class' => $this->helpClass,
            'route' => $this->routes[0]->name,
            'routes' => $this->routeCount(),
            'uri' => $this->routes[0]->uri,
            'matched' => $this->matchedBy,
            'slug' => $this->slug,
            'covered' => $this->covered(),
            'declared' => $this->isDeclared,
            'file_only' => $this->isFileOnly,
            'article_id' => $this->articleId,
        ];
    }
}
