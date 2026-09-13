<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use FinityLabs\FinCodex\FinCodexPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * A panel that keeps every plugin default: id portal, path /portal, the web
 * guard, no topbar (so the help button must fall back to the sidebar footer),
 * no dark mode (so the theme wrapper carries a static light class) and no
 * explicit helpButtonRenderHook(). Phase 3's fallback and theme tests run here.
 *
 * It is also the only fixture panel with a profile page and an email
 * verification prompt, so the context picker has something to prove those two
 * auth rows against. The prompt is registered with isRequired: false on
 * purpose: the page exists without an email-verified requirement landing on
 * the panel Phase 3's tests drive.
 */
final class PortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('portal')
            ->login()
            ->profile()
            ->emailVerification(isRequired: false)
            ->topbar(false)
            ->darkMode(false)
            ->pages([Dashboard::class])
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
            ->plugin(FinCodexPlugin::make());
    }
}
