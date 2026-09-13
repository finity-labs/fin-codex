<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Coverage;

use Filament\Pages\Page as BasePage;
use Filament\Resources\Pages\Page as ResourcePage;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Help\DeclaredContextsSource;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Scope\ContextPanels;
use FinityLabs\LinCodex\Contexts\ContextIndex;
use FinityLabs\LinCodex\Contexts\PageContext;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Coverage\RouteCoverage;
use FinityLabs\LinCodex\Coverage\RouteCoverageRow;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ContextType;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Which screens have a help article, as the coverage page and its badge see
 * it. Built once per request over one RouteCoverage::report() and one
 * ContentSource::all().
 *
 * This is a VIEW over the core's report, not a second report. RouteCoverage
 * decides which routes are pages at all (GET, named, session-starting, not
 * matching a lin-codex.coverage.ignore glob, not vendor-namespaced) and this
 * class only regroups what it returns. Two things are added on top, and both
 * exist because a Filament resource registers each page as its OWN invokable
 * controller (Resources\Pages\Page::route() is Route::get($path, static::class)):
 *
 *  1. The collapse. RouteCoverage derives ListUsers, CreateUser and EditUser
 *     for one resource. helpClass() folds them back onto the resource class,
 *     which is the identity PageIdentity::pageClass() hands the drawer.
 *  2. The class credit. lin-codex's PatternMatcher matches class: keys exactly,
 *     with no inheritance walk, so `class:UserResource` never wins a resource
 *     route in report() — while the drawer on that page matches it every time.
 *     candidates() is asked again with the help class so the page agrees with
 *     the drawer.
 *
 * The number this produces is therefore NOT `codex:coverage`'s exit-code
 * number: the console counts routes and credits only what report() matched,
 * this counts screens and additionally credits a resource-class context. The
 * README says so (Phase 8).
 *
 * Which panel a row belongs to is not decided here: Scope\ContextPanels
 * answers that, so the coverage page, the panel scope gate and the Help
 * Center's panel filter cannot disagree about where a screen files.
 *
 * ContextResolver is deliberately not used: it applies ArticleGate and the
 * locale pick, and coverage asks whether a MAPPING exists, not whether the
 * current viewer may read it — RouteCoverage's own documented stance.
 */
final class CoverageReport
{
    /**
     * The panel filter's value for rows that belong to no panel. The value
     * lives on the resolver, which every panel-scoping consumer shares; this
     * alias stays because the coverage page and the coverage tests read it
     * here.
     */
    public const OUTSIDE_PANELS = ContextPanels::OUTSIDE_PANELS;

    private ?Request $memoRequest = null;

    /** @var list<CoverageRow>|null */
    private ?array $memo = null;

    public function __construct(private readonly Application $app, private readonly ContextPanels $panels) {}

    /**
     * Memoised on the request instance, exactly like Panel\CurrentPage: the
     * scoped instance survives the in-process requests a test issues, so a
     * different request object drops the memo.
     *
     * @return list<CoverageRow>
     */
    public function rows(): array
    {
        /** @var Request $request */
        $request = $this->app->make('request');

        if ($this->memo === null || $this->memoRequest !== $request) {
            $this->memoRequest = $request;
            $this->memo = $this->build();
        }

        return $this->memo;
    }

    /** Uncovered rows of one panel — the badge number, and the default view's count. */
    public function uncovered(?string $panelId): int
    {
        $count = 0;

        foreach ($this->rows() as $row) {
            if ($row->panelId === $panelId && ! $row->covered()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Panel ids present in the report, plus OUTSIDE_PANELS when any row has
     * none. The coverage page's filter options.
     *
     * @return array<string, string>
     */
    public function panelOptions(): array
    {
        $options = [];
        $outside = false;

        foreach ($this->rows() as $row) {
            if ($row->panelId === null) {
                $outside = true;

                continue;
            }

            $options[$row->panelId] ??= $row->panelId;
        }

        if ($outside) {
            $options[self::OUTSIDE_PANELS] = (string) __('fin-codex::fin-codex.coverage.outside_panels');
        }

        return $options;
    }

    /**
     * The help identity of a route's derived page class — PageIdentity's rule:
     * a resource page answers with its resource, a custom Filament page with
     * itself, anything else (plain controller, closure, unresolved) with null.
     *
     * Resources\Pages\Page extends BasePage, not Pages\Page, so the two checks
     * are disjoint; the resource check comes first for readability.
     *
     * The package's own pages answer getResource() with the CURRENT panel's
     * resource, and this report scans every panel from one request, so for
     * them the resource is asked of the route's panel instead: a staff row
     * files under staff's override even while admin is the panel serving.
     *
     * @param  class-string|string|null  $pageClass
     */
    public static function helpClass(?string $pageClass, ?string $panelId = null): ?string
    {
        if ($pageClass === null || $pageClass === '' || ! class_exists($pageClass)) {
            return null;
        }

        if (is_subclass_of($pageClass, ResourcePage::class)) {
            $resource = $pageClass::getResource();

            return $panelId !== null && is_a($resource, ArticleResource::class, true)
                ? FinCodexPlugin::articleResourceClass($panelId)
                : $resource;
        }

        return is_subclass_of($pageClass, BasePage::class) ? ltrim($pageClass, '\\') : null;
    }

    /**
     * One row per screen, in the order the routes of that screen first appear
     * in the core's report.
     *
     * @return list<CoverageRow>
     */
    private function build(): array
    {
        try {
            $report = $this->app->make(RouteCoverage::class)->report();
            $all = $this->app->make(ContentSource::class)->all();
        } catch (MissingSettings|QueryException) {
            // A fresh install before the settings migration must not break
            // every panel page through the navigation badge.
            return [];
        }

        $index = ContextIndex::fromArticles($all);

        // Both picker methods rebuild from the panel registry and the router's
        // route collection on every call, so they are asked once per report,
        // never once per row. A null panel unions every panel, which keeps the
        // first registered label for a class two panels share.
        $picker = $this->app->make(ContextPicker::class);
        $classLabels = $picker->classKeys(null);
        $routeLabels = $picker->routeKeys(null);

        /** @var array<string, array{panelId: ?string, helpClass: ?string, routes: list<RouteCoverageRow>}> $groups */
        $groups = [];

        foreach ($report as $route) {
            $panelId = $this->panels->forRoute($route->name);
            $helpClass = self::helpClass($route->pageClass, $panelId);
            $key = $helpClass !== null ? $panelId.'|'.$helpClass : $route->name;

            $groups[$key] ??= ['panelId' => $panelId, 'helpClass' => $helpClass, 'routes' => []];
            $groups[$key]['routes'][] = $route;
        }

        $rows = [];

        foreach ($groups as $group) {
            $key = $group['routes'][0]->name;
            [$matchedBy, $slug] = $this->match($index, $group['panelId'], $group['helpClass'], $group['routes']);
            $article = $slug !== null ? ($all[$slug] ?? null) : null;

            $rows[] = new CoverageRow(
                key: $key,
                panelId: $group['panelId'],
                helpClass: $group['helpClass'],
                label: $this->label($key, $group['helpClass'], $classLabels, $routeLabels),
                routes: $group['routes'],
                matchedBy: $matchedBy,
                slug: $slug,
                isDeclared: $this->isDeclared($article, $matchedBy),
                isFileOnly: $article !== null && $article->id === null,
                articleId: $article?->id,
            );
        }

        return $rows;
    }

    /**
     * The winning context of one screen, as the drawer would resolve it.
     *
     * The class credit runs first when the screen has a help identity: the
     * index is asked for the panel's own contexts and then for the panel-less
     * ones, which is the core's two-pass fallback, and only PageClass matches
     * are kept — the `/` path is a placeholder, and without the type filter a
     * `url:/*` context would claim every row. When no class context answers,
     * the first member route the core's own report matched wins.
     *
     * ContextResolver is not asked, on purpose: it applies the viewer gate and
     * the locale pick, and coverage asks whether a mapping exists.
     *
     * @param  list<RouteCoverageRow>  $routes
     *
     * @return array{0: ?string, 1: ?string} the context string and the slug
     */
    private function match(ContextIndex $index, ?string $panelId, ?string $helpClass, array $routes): array
    {
        if ($helpClass !== null) {
            $page = new PageContext(null, '/', $helpClass, null);

            foreach ([$panelId, null] as $pass) {
                foreach ($index->candidates($page, $pass) as $candidate) {
                    if ($candidate->context->type === ContextType::PageClass) {
                        return [$candidate->context->toString(), $candidate->slug];
                    }
                }
            }
        }

        foreach ($routes as $route) {
            if ($route->covered()) {
                return [$route->matchedBy, $route->slug];
            }
        }

        return [null, null];
    }

    /**
     * Whether the winning context is one DeclaredContextsSource synthesised
     * from a HasHelp declaration rather than one an author stored.
     */
    private function isDeclared(?ArticleData $article, ?string $matchedBy): bool
    {
        if ($article === null || $matchedBy === null) {
            return false;
        }

        $declared = $article->meta[DeclaredContextsSource::META_KEY] ?? [];

        return is_array($declared) && in_array($matchedBy, $declared, true);
    }

    /**
     * The navigation label of the screen: the class label when there is a help
     * class, the route label otherwise, and a humanised route name as the last
     * resort — which never returns an empty string.
     *
     * @param  array<string, string>  $classLabels
     * @param  array<string, string>  $routeLabels
     */
    private function label(string $key, ?string $helpClass, array $classLabels, array $routeLabels): string
    {
        if ($helpClass !== null && isset($classLabels[ltrim($helpClass, '\\')])) {
            return $classLabels[ltrim($helpClass, '\\')];
        }

        return $routeLabels[$key] ?? Str::headline(str_replace(['filament.', '.'], ['', ' '], $key));
    }
}
