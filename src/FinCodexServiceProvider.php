<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex;

use Filament\Forms\Components\Field;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\SourceWarnings;
use FinityLabs\FinCodex\Forms\CodexHelp;
use FinityLabs\FinCodex\Help\ArticleLookup;
use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\FinCodex\Help\DeclaredContextsSource;
use FinityLabs\FinCodex\Panel\CurrentPage;
use FinityLabs\FinCodex\Policies\ArticlePolicy;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

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
        $this->app->extend(ContentSource::class, static fn (ContentSource $inner, Container $app): ContentSource => new DeclaredContextsSource($inner, $app->make(DeclaredContexts::class)));
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

        Field::macro('codexHelp', function (string $slug, ?string $heading = null): Field {
            // Macroable binds the closure to the field; PHPStan types $this as the provider.
            return $this->hintAction(CodexHelp::make($slug, $heading)); // @phpstan-ignore method.notFound
        });
    }

    /**
     * Give lin-codex's Article a policy: the host's when it wrote one, ours
     * otherwise.
     *
     * The explicit registration is not optional. Gate::guessPolicyName() walks
     * the model's own namespace, so for FinityLabs\LinCodex\Models\Article it
     * only ever tries FinityLabs\Policies\ArticlePolicy,
     * FinityLabs\LinCodex\Policies\ArticlePolicy and
     * FinityLabs\LinCodex\Models\Policies\ArticlePolicy. Neither the host's
     * App\Policies\ArticlePolicy nor ours is among them.
     *
     * One arm covers both stories a host can have. A hand-written policy lands
     * at {policyNamespace}\ArticlePolicy, and so does the one Filament Shield
     * writes for a vendor model — shield:generate puts it in the configured
     * policies path, which is that same namespace by default. No Shield
     * branch, no Shield dependency.
     *
     * FinCodexPlugin::get() reaches for the current panel and throws when
     * there is none, which is the normal case here: packageBooted() also runs
     * in console commands, queue workers and any request outside a panel. The
     * catch keeps the registration happening anyway, on the default namespace.
     */
    protected function registerPolicies(): void
    {
        try {
            $namespace = FinCodexPlugin::get()->getPolicyNamespace();
        } catch (Throwable) {
            $namespace = 'App\\Policies';
        }

        $hostPolicy = $namespace.'\\ArticlePolicy';

        Gate::getFacadeRoot()->policy(
            Article::class,
            class_exists($hostPolicy) ? $hostPolicy : ArticlePolicy::class,
        );
    }
}
