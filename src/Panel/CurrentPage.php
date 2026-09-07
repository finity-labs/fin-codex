<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Panel;

use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\Component;
use Livewire\LivewireManager;
use Throwable;

/**
 * The one request-scoped source of page identity for the help button and the
 * drawer. The identity is derived from the current route, never from
 * render-hook scopes: the topbar hook receives none while BODY_END does, and
 * the route answers on both Livewire majors (the livewire_component action on
 * Livewire 4, the controller class on Livewire 3). Panel id and guard are read
 * lazily from the current panel, which SetUpPanel has set by the time any hook
 * renders.
 */
final class CurrentPage
{
    private ?Request $memoRequest = null;

    private ?PageIdentity $memo = null;

    public function __construct(private readonly Application $app) {}

    /**
     * Memoised per request instance rather than behind a flag: the scoped
     * instance survives the in-process requests a test issues (Octane flushes
     * it, Testbench does not), so a different request object drops the memo.
     * Same reasoning as lin-codex's PageHelpResolver.
     */
    public function identity(): PageIdentity
    {
        /** @var Request $request */
        $request = $this->app->make('request');

        if ($this->memo === null || $this->memoRequest !== $request) {
            $this->memoRequest = $request;
            $this->memo = $this->derive($request);
        }

        return $this->memo;
    }

    private function derive(Request $request): PageIdentity
    {
        $class = $this->livewireClass($request->route());
        $resource = null;

        if ($class !== null && is_subclass_of($class, ResourcePage::class)) {
            /** @var class-string<ResourcePage> $class */
            $resource = $class::getResource();
        }

        $panel = Filament::getCurrentPanel();

        return new PageIdentity(
            livewireClass: $class,
            resourceClass: $resource,
            panelId: $panel?->getId(),
            guard: $panel?->getAuthGuard(),
            isSimplePage: $class !== null && is_subclass_of($class, SimplePage::class),
        );
    }

    /**
     * The Livewire component class behind the route, or null. The final
     * Livewire\Component check is what keeps a topbar refresh from carrying a
     * page: the update route's controller is HandleRequests, and without the
     * check lin-codex would be handed class:Livewire\Mechanisms\... as the page.
     */
    private function livewireClass(mixed $route): ?string
    {
        if (! $route instanceof Route) {
            return null;
        }

        $component = $route->getAction('livewire_component');

        if (is_object($component)) {
            $class = $component::class;
        } elseif (is_string($component) && $component !== '') {
            $class = class_exists($component) ? ltrim($component, '\\') : $this->namedComponentClass($component);
        } else {
            $controller = $route->getControllerClass();
            $class = is_string($controller) && $controller !== '' ? ltrim($controller, '\\') : null;
        }

        return $class !== null && is_subclass_of($class, Component::class) ? $class : null;
    }

    /**
     * Resolve a Livewire component name (kebab alias) to its class; mirrors
     * lin-codex's RouteCoverage::livewireClass().
     */
    private function namedComponentClass(string $name): ?string
    {
        if (! $this->app->bound(LivewireManager::class)) {
            return null;
        }

        try {
            return $this->app->make(LivewireManager::class)->new($name)::class;
        } catch (Throwable) {
            return null;
        }
    }
}
