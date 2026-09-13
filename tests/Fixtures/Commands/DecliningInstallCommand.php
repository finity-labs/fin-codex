<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Commands;

use FinityLabs\FinCodex\Commands\InstallCommand;

/**
 * The installer on a host that answers no to the one question that rewrites
 * lin-codex's config.
 *
 * A decline cannot be expressed any other way in this file. The console is
 * deliberately not mocked here — Laravel's PendingCommand swaps the output
 * style for a double that throws on any question it was not told to expect,
 * which would turn every confirm() in the command into a test-side
 * expectation — and --no-interaction answers every question with its default.
 * Overriding the one answer leaves the rest of the run untouched.
 */
class DecliningInstallCommand extends InstallCommand
{
    /**
     * Answer no to the public-help-center question and defer on everything
     * else.
     *
     * The signature is Illuminate's, untyped on purpose: this package types its
     * own methods, but a mismatch against the parent is a fatal at class-load
     * time.
     *
     * @param  string  $question
     * @param  bool  $default
     */
    public function confirm($question, $default = false): bool
    {
        if (str_contains((string) $question, 'Switch the public help center off?')) {
            return false;
        }

        return parent::confirm($question, $default);
    }
}
