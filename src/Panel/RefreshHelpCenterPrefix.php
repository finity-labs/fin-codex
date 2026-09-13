<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Panel;

use Closure;
use Filament\Facades\Filament;
use FinityLabs\FinCodex\FinCodexPlugin;
use Illuminate\Http\Request;
use Throwable;

/**
 * The second write site for this panel's help-center prefix, for tenancy.
 *
 * Invisible from the call site, so the reasoning lives here: Filament sets the
 * panel up and boots its plugins before it identifies the tenant, so the write
 * the plugin makes at boot cannot know the tenant segment the page route
 * carries, route generation throws there and the write is skipped. Left at
 * that, every help link on a tenanted panel would be missing its tenant and
 * answer 404. This runs the same derivation again once the tenant is set.
 *
 * A panel's tenant middleware list is Filament's own IdentifyTenant followed by
 * whatever the panel added, and Filament applies the list only inside the
 * tenant route group — so this is guaranteed to run after the tenant is known
 * and a panel without tenancy never runs it at all.
 */
final class RefreshHelpCenterPrefix
{
    public function handle(Request $request, Closure $next): mixed
    {
        $panel = Filament::getCurrentPanel();

        try {
            $plugin = $panel?->getPlugin(FinCodexPlugin::ID);
        } catch (Throwable) {
            // getPlugin() throws on a panel that carries no such plugin, and a
            // middleware that throws takes the whole request down. There is
            // nothing to refresh in that case anyway.
            $plugin = null;
        }

        if ($panel !== null && $plugin instanceof FinCodexPlugin) {
            $plugin->refreshHelpCenterPrefix($panel);
        }

        return $next($request);
    }
}
