<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Traits;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Page access: Shield's permission when Shield is installed, an opt-in Gate
 * ability when it is not, and open otherwise.
 *
 * This file is a DELIBERATE COPY of fin-mail's trait of the same name, not a
 * shared abstraction. fin-codex must not depend on fin-mail, there is no shared
 * base package between the fin-* plugins, and extracting one is its own
 * cross-package decision (08-CONTEXT, Deferred). The duplication is the
 * accepted cost; keep the two in step by hand when either changes.
 *
 * The Shield branch ASKS Shield for the permission key rather than building it,
 * which is what keeps it correct across Shield versions and across a host's own
 * config — Shield 4 made both the separator and the case configurable, so any
 * hand-built name is wrong for somebody. Shield itself is never a hard
 * dependency: it is reached behind class_exists(), and phpstan.neon carries the
 * matching ignore for exactly as long as Shield stays out of require-dev.
 *
 * Both methods are static and overridable, so a host page that extends
 * Pages\HelpSettings or Pages\HelpCoverage can still replace either.
 */
trait HasPageShieldSupport
{
    protected static ?string $pagePermissionKey = null;

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        if (! static::isShieldAvailable()) {
            // Without Shield, a host can gate this page by defining a Gate
            // ability named after it, e.g.
            // Gate::define('page_HelpSettings', ...) or
            // Gate::define('page_HelpCoverage', ...).
            //
            // That name is FIN-CODEX'S OWN convention for hosts without
            // Shield — it is not Shield's. Shield 3 used page_{Class}, but
            // Shield 4 renamed every permission (separator ':', pascal case,
            // 'view' prefix for pages) and made both halves configurable, so
            // Shield's own key for this page is 'View:HelpSettings' by
            // default and something else entirely on a reconfigured install.
            // That is precisely why the branch below asks Shield instead.
            //
            // Pages stay open when no ability is defined.
            $ability = 'page_'.class_basename(static::class);

            if (Gate::has($ability)) {
                return (bool) static::getAuthUser()?->can($ability);
            }

            return parent::canAccess();
        }

        $permission = static::getPagePermission();
        $user = Filament::auth()->user();

        return $permission && $user
            ? $user->can($permission)
            : parent::canAccess();
    }

    protected static function getAuthUser(): ?Authenticatable
    {
        try {
            return Filament::auth()->user();
        } catch (\Throwable) {
            return auth()->user();
        }
    }

    protected static function isShieldAvailable(): bool
    {
        return class_exists(FilamentShieldPlugin::class);
    }

    protected static function getPagePermission(): ?string
    {
        if (static::$pagePermissionKey === null) {
            $page = FilamentShield::getPages()[static::class] ?? null;
            static::$pagePermissionKey = $page ? array_key_first($page['permissions']) : null;
        }

        return static::$pagePermissionKey;
    }
}
