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
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\StaffHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\StaffHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * A second panel on its own guard: id staff, path /staff, authGuard staff
 * (a session guard over the same users provider, declared in the TestCase),
 * FinCodexPlugin registered with closure-valued options that differ from
 * admin's on every option. Not the default panel. Carries the fixture
 * resource, the Reports page and the registration and password-reset pages
 * so Phase 3 can prove page identity and the guest auth pages.
 */
final class StaffPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('staff')
            ->path('staff')
            ->authGuard('staff')
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
                    ->helpButtonRenderHook(fn (): string => PanelsRenderHook::TOPBAR_START)
                    ->shortcut(fn (): string => 'ctrl+.')
                    ->drawerWidth(fn (): int => 360)
                    ->globalSearch(fn (): bool => true)
                    ->navigationGroup(fn (): string => 'Support')
                    ->navigationSort(fn (): int => 5)
                    ->articleResource(StaffHelpArticleResource::class)
                    ->settingsPage(StaffHelpSettings::class)
                    ->coveragePage(StaffHelpCoverage::class)
                    ->policyNamespace('Staff\\Policies'),
            );
    }
}
