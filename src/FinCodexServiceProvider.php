<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex;

use Filament\Forms\Components\Field;
use FinityLabs\FinCodex\Ai\NotifyTranslationFinished;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\SourceWarnings;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Forms\CodexHelp;
use FinityLabs\FinCodex\Help\ArticleLookup;
use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\FinCodex\Help\DeclaredContextsSource;
use FinityLabs\FinCodex\Livewire\HelpDrawer;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Panel\CurrentPage;
use FinityLabs\FinCodex\Policies\ArticlePolicy;
use FinityLabs\FinCodex\Scope\ContextPanels;
use FinityLabs\FinCodex\Scope\PanelScopeGate;
use FinityLabs\FinSupport\Auth\PolicyRegistrar;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Events\ArticleTranslated;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FinCodexServiceProvider extends PackageServiceProvider
{
    public static string $name = 'fin-codex';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasTranslations()
            ->hasViews()
            ->hasCommands([
                Commands\InstallCommand::class,
                Commands\UninstallCommand::class,
            ]);
    }

    /**
     * CurrentPage is scoped like lin-codex's PageHelpResolver: one identity
     * per request, flushed by Octane between requests.
     *
     * The declared-help decorator rides on lin-codex's own ContentSource
     * binding through Container::extend(), so the core's lin-codex.source
     * switch keeps choosing the inner source and forgetInstance() still
     * yields a fresh, decorated instance (extenders live outside the
     * instance map). DeclaredContexts is a singleton whose registry scan is
     * lazy: the panel providers register after this one, so the scan has to
     * wait for the first read. ContextPanels is a singleton for the same
     * reason and with the same lifetime: the panel registry does not change
     * after boot, so its class index and path list are built once per
     * process rather than once per request or once per article.
     *
     * ArticleLookup is scoped for the same reason as CurrentPage: one lookup
     * per request answers the title and the gate verdict for every field
     * hint on a page, so ten hints cost one ContentSource::all() and one
     * viewer. ContextPicker is scoped so its per-panel row lists are built
     * once per request rather than once per contexts-repeater row.
     *
     * CoverageReport is scoped because the coverage page and the navigation
     * badge that links to it must show the same number, and because that
     * badge renders on every panel page: one route report and one
     * ContentSource::all() per request, never one per surface. SourceWarnings
     * is scoped for the second half of that reason: no source memoises its
     * warnings, and the declared-help decorator reads the inner source twice
     * to produce them.
     *
     * PanelScopeGate is a singleton with a request-identity memo of its own,
     * like DeclaredContextsSource: the host hook it wraps is handed to it once
     * at panel boot and must survive the per-request flush a scoped binding
     * would give it under Octane, while its verdict map still resets with the
     * request.
     *
     * The Help Center's slug parameter gets its pattern here, on the router
     * itself, because Filament builds a page's route with no hook for that
     * route's own where(). Laravel merges global patterns into a route when the
     * route is CREATED, and register() runs before every provider's boot(), so
     * the pattern is in place before any route file is read and survives
     * route caching — a where() attached afterwards would not. The pattern is
     * ".*" rather than ".+" because the parameter is optional and {panel}/help
     * has to match with no value at all.
     */
    public function packageRegistered(): void
    {
        Route::pattern(HelpCenter::SLUG_PARAMETER, '.*');

        $this->app->scoped(CurrentPage::class);
        $this->app->scoped(ArticleLookup::class);
        $this->app->scoped(ContextPicker::class);
        $this->app->scoped(CoverageReport::class);
        $this->app->scoped(SourceWarnings::class);
        $this->app->singleton(DeclaredContexts::class);
        $this->app->singleton(ContextPanels::class);
        $this->app->singleton(PanelScopeGate::class);
        $this->app->extend(ContentSource::class, static fn (ContentSource $inner, Container $app): ContentSource => new DeclaredContextsSource($inner, $app->make(DeclaredContexts::class), $app));
    }

    /**
     * The Field::codexHelp($slug, $heading) sugar over
     * hintAction(CodexHelp::make(...)). Filament's Macroable binds the closure
     * to the field instance and its getMacro() walks class_parents(), so one
     * macro on Field reaches TextInput, Select and every other field. Macros
     * are a static map: booting once is enough and no Filament boot order
     * matters.
     *
     * The listener that turns a finished translation run into the admin's
     * notification is registered here because it belongs to the panel layer,
     * not to the engine: lin-codex fires the event and says nothing about how
     * an admin is told.
     */
    public function packageBooted(): void
    {
        $this->registerPolicies();
        $this->forgetSourceMemoOnWrite();

        // The core drawer with fin-codex's Filament-native shell; the panel
        // mount renders this tag, a page outside Filament keeps the core's.
        Livewire::component('fin-codex.help-drawer', HelpDrawer::class);

        Field::macro('codexHelp', function (string $slug, ?string $heading = null): Field {
            // Macroable binds the closure to the field; PHPStan types $this as the provider.
            return $this->hintAction(CodexHelp::make($slug, $heading)); // @phpstan-ignore method.notFound
        });

        Event::listen(ArticleTranslated::class, NotifyTranslationFinished::class);
    }

    /**
     * A write to an article, a translation or a context drops the decorated
     * source's request memo, so a save earlier in the same request is visible
     * to the next read, which is the guarantee the core's DatabaseSource gives
     * by never memoising. The coverage report and the source warnings memoise
     * one reading of that source per request and are dropped with it, so the
     * coverage page's attach and import see their own write in the render they
     * trigger. Only an instance that has already been resolved is touched:
     * resolving one from inside a model event during a migration or a seeder
     * would be the wrong moment.
     */
    protected function forgetSourceMemoOnWrite(): void
    {
        $forget = function (): void {
            if ($this->app->resolved(ContentSource::class)) {
                $source = $this->app->make(ContentSource::class);

                // A host may rebind the source without the decorator, so the check stays.
                if ($source instanceof DeclaredContextsSource) {
                    $source->forget();
                }
            }

            foreach ([CoverageReport::class, SourceWarnings::class] as $memo) {
                if ($this->app->resolved($memo)) {
                    $this->app->make($memo)->forget();
                }
            }
        };

        foreach ([Article::class, ArticleTranslation::class, ArticleContext::class] as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }
    }

    /**
     * Give lin-codex's Article a policy while no panel is current: the host's
     * at {namespace}\ArticlePolicy when it exists, the shipped one otherwise,
     * with the namespace read from the default panel's plugin (App\Policies
     * when the plugin is not there). FinCodexPlugin::boot() registers again
     * with the booting panel's own namespace, which is what makes the option
     * per panel; fin-support's PolicyRegistrar explains the two calls.
     */
    protected function registerPolicies(): void
    {
        self::registerArticlePolicy(PolicyRegistrar::namespaceOf(FinCodexPlugin::ID));
    }

    public static function registerArticlePolicy(string $namespace): void
    {
        PolicyRegistrar::register($namespace, [Article::class => 'ArticlePolicy'], [Article::class => ArticlePolicy::class]);
    }
}
