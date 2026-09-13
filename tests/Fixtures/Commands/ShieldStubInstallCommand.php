<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Commands;

use FinityLabs\FinCodex\Commands\InstallCommand;

/**
 * The installer as it behaves on a host that HAS Shield installed, with
 * shield:generate's answer canned.
 *
 * The real command shells out to a fresh PHP process so that shield:generate
 * reads the config file the install has just written. A fresh process cannot be
 * handed a fake Artisan command, so the seam replaces the run itself rather than
 * the command it runs — which is what makes the handling of Shield's answer
 * testable in a harness that has no Shield at all.
 *
 * Only the two seams are overridden. Everything the rows then exercise — the
 * printing, the skipped-policy note, the failure fallback and the next-steps
 * table — is the shipped command's own code.
 */
class ShieldStubInstallCommand extends InstallCommand
{
    /**
     * The exit code the canned run reports.
     *
     * Prefixed, along with its pair, because Illuminate\Console\Command already
     * declares $output as an instance property — PHP refuses to redeclare it as
     * a static one and the fatal is at class-load time.
     */
    public static int $shieldExitCode = 0;

    /** What the canned run said, standard and error output already combined. */
    public static string $shieldOutput = '';

    /** Shield's own skip line, as GenerateCommand::policyInfo() prints it. */
    public const SKIP_LINE = 'ArticlePolicy   skipped — provided by FinityLabs\FinCodex\Policies\ArticlePolicy';

    /** Back to the defaults, so nothing leaks from one row into the next. */
    public static function reset(): void
    {
        self::$shieldExitCode = 0;
        self::$shieldOutput = '';
    }

    protected function hasCommand(string $name): bool
    {
        return $name === 'shield:generate' || parent::hasCommand($name);
    }

    /**
     * @param  list<string>  $args
     *
     * @return array{0: int, 1: string}
     */
    protected function runShieldGenerate(array $args): array
    {
        return [self::$shieldExitCode, self::$shieldOutput];
    }
}
