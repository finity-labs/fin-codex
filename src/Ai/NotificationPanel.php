<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Ai;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * The panel a finished translation run is reported to.
 *
 * NotificationLocale's sibling, and for the same reason: a fact about the
 * request that queued the work, which the worker running it cannot ask for.
 * A press happens on one panel, but a queued job has no current panel at all,
 * so anything the listener needs a panel for - the edit URL it links and the
 * guard it resolves the admin through - falls back to the DEFAULT panel. On a
 * host whose default panel is not the one the admin pressed in, that is the
 * wrong answer twice over: the URL names a route the default panel does not
 * register, and the guard reads the id the press captured on another panel's
 * guard through the default panel's provider.
 *
 * So the press records the panel's id the way it records the locale, through
 * Laravel's context, which is serialised into the queue payload and restored
 * on the worker before the job runs. The id alone travels; the panel itself is
 * resolved from it on the other side, once, by id.
 *
 * Its absence is not an error. A job queued by something other than these
 * actions, a payload written before this existed, or a panel removed from the
 * host between the press and the run all mean the listener does what it did
 * before: the current-or-default panel answers. Nothing here reads a list of
 * panels to guess with (locked) - one recorded id is looked up, or nothing is.
 */
final class NotificationPanel
{
    /** The context key the press writes and the listener reads. */
    public const KEY = 'fin-codex.notification_panel';

    /**
     * Record the panel the press is happening on. Once per press, beside the
     * locale: the panel belongs to the request, not to any one job, and every
     * job pushed after this call carries it. A call with no current panel
     * records nothing rather than a guess.
     */
    public static function remember(): void
    {
        $id = Filament::getCurrentPanel()?->getId();

        if ($id === null) {
            return;
        }

        Context::add(self::KEY, $id);
    }

    /**
     * The panel to report to: the one the press recorded, or null when it
     * recorded none and when the id it recorded no longer names a panel.
     * Null is the caller's cue to do what it did before this existed.
     */
    public static function current(): ?Panel
    {
        $id = Context::get(self::KEY);

        if (! is_string($id) || $id === '') {
            return null;
        }

        try {
            return Filament::getPanel($id);
        } catch (Throwable) {
            return null;
        }
    }
}
