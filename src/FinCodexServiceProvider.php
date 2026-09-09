<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex;

use Filament\Forms\Components\Field;
use FinityLabs\FinCodex\Auth\ArticlePolicyRegistration;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\SourceWarnings;
use FinityLabs\FinCodex\Forms\CodexHelp;
use FinityLabs\FinCodex\Help\ArticleLookup;
use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\FinCodex\Help\DeclaredContextsSource;
use FinityLabs\FinCodex\Livewire\HelpDrawer;
use FinityLabs\FinCodex\Panel\CurrentPage;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use Illuminate\Contracts\Container\Container;
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
     * wait for the first read.
     *
     * ArticleLookup is scoped for the same reason as CurrentPage: one lookup
     * per request answers the title and the gate verdict for every field
     * hint on a page, so ten hints cost one ContentSource::all() and one
     * viewer.
     *
     * CoverageReport is scoped because the coverage page and the navigation
     * badge that links to it must show the same number, and because that
     * badge renders on every panel page: one route report and one
     * ContentSource::all() per request, never one per surface. SourceWarnings
     * is scoped for the second half of that reason: no source memoises its
     * warnings, and the declared-help decorator reads the inner source twice
     * to produce them.
     */
    public function packageRegistered(): void
    {
        $this->app->scoped(CurrentPage::class);
        $this->app->scoped(ArticleLookup::class);
        $this->app->scoped(CoverageReport::class);
        $this->app->scoped(SourceWarnings::class);
        $this->app->singleton(DeclaredContexts::class);
        $this->app->extend(ContentSource::class, static fn (ContentSource $inner, Container $app): ContentSource => new DeclaredContextsSource($inner, $app->make(DeclaredContexts::class), $app));
    }

    /**
     * The Field::codexHelp($slug, $heading) sugar over
     * hintAction(CodexHelp::make(...)). Filament's Macroable binds the closure
     * to the field instance and its getMacro() walks class_parents(), so one
     * macro on Field reaches TextInput, Select and every other field. Macros
     * are a static map: booting once is enough and no Filament boot order
     * matters.
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
    }

    /**
     * A write to an article, a translation or a context drops the decorated
     * source's request memo, so a save earlier in the same request is visible
     * to the next read, which is the guarantee the core's DatabaseSource gives
     * by never memoising. Only a source that has already been resolved is
     * touched: resolving it from inside a model event during a migration or a
     * seeder would be the wrong moment.
     */
    protected function forgetSourceMemoOnWrite(): void
    {
        $forget = function (): void {
            if (! $this->app->resolved(ContentSource::class)) {
                return;
            }

            $source = $this->app->make(ContentSource::class);

            // A host may rebind the source without the decorator, so the check stays.
            if ($source instanceof DeclaredContextsSource) { // @phpstan-ignore instanceof.alwaysTrue
                $source->forget();
            }
        };

        foreach ([Article::class, ArticleTranslation::class, ArticleContext::class] as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }
    }

    /**
     * Give lin-codex's Article a policy while no panel is current: the
     * default panel's namespace when the plugin is on it, App\Policies
     * otherwise. FinCodexPlugin::boot() registers again with the booting
     * panel's own namespace; Auth\ArticlePolicyRegistration explains why
     * both calls exist.
     */
    protected function registerPolicies(): void
    {
        ArticlePolicyRegistration::register(ArticlePolicyRegistration::defaultNamespace());
    }
}
