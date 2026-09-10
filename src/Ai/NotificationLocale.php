<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Ai;

use Illuminate\Support\Facades\Context;

/**
 * The language a finished translation run is reported in.
 *
 * A panel's language is not the application's. A language switcher keeps the
 * admin's choice in the session and applies it to every panel request, so a
 * press can happen under English while the configured application locale says
 * something else entirely. The job then runs on a worker, which has neither
 * session nor request, and Laravel carries no locale into a queued job: left
 * alone, an English panel is answered in the application's language. Worse,
 * the answer depends on the host's queue driver, because on the sync driver
 * the press and the job share one request and the same code reports in the
 * panel's language instead.
 *
 * Laravel's context is the one thing that does travel. It is serialised into
 * the queue payload when the job is pushed and restored on the worker before
 * the job runs, so the press records the locale it happened under and the
 * listener renders under that.
 *
 * The key is namespaced to this package, and its absence is not an error: a
 * job queued by something other than these actions, or a payload written
 * before this existed, falls back to the locale the process is already running
 * under, which is what the notification used to render in.
 */
final class NotificationLocale
{
    /** The context key the press writes and the listener reads. */
    public const KEY = 'fin-codex.notification_locale';

    /**
     * Record the locale of the request that is queueing the work. Once per
     * press: the locale belongs to the request, not to any one job, and every
     * job pushed after this call carries it.
     */
    public static function remember(): void
    {
        Context::add(self::KEY, app()->getLocale());
    }

    /**
     * The locale to report in: the one the press recorded, else the one this
     * process is already running under.
     */
    public static function current(): string
    {
        $locale = Context::get(self::KEY);

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }
}
