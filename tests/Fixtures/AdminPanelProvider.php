<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The default panel: id admin, path /admin, the web guard, FinCodexPlugin
 * registered with literal option values (the staff panel uses closures). The middleware stack is the one Filament's own panel provider
 * generator emits; SetUpPanel is prepended automatically as panel:admin.
 * Carries the fixture resource, the Reports page and the registration and
 * password-reset pages so Phase 3 can prove page identity and the guest auth
 * pages.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->registration()
            ->passwordReset()
            ->pages([Dashboard::class, Reports::class])
            ->resources([UserResource::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class])
            ->plugin(
                FinCodexPlugin::make()
                    ->helpButtonRenderHook(PanelsRenderHook::TOPBAR_END)
                    ->shortcut('ctrl+/')
                    ->drawerWidth(480)
                    ->globalSearch(false)
                    ->navigationGroup('Help')
                    ->navigationSort(90)
                    ->articleResource(AdminHelpArticleResource::class)
                    ->settingsPage(AdminHelpSettings::class)
                    ->coveragePage(AdminHelpCoverage::class)
                    ->policyNamespace('App\\Policies'),
            );
    }
}
