<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests;

/**
 * Boots the test application the way a host looks after fin-codex:install:
 * lin-codex's route prefix for the public help center is null.
 *
 * The key has to be set here rather than in a test body. lin-codex's route
 * file reads it once, when the provider boots, and registers the two
 * help-center routes only when it is not null. By the time a Pest beforeEach
 * or a test body runs, those routes either exist or they do not, and writing
 * the config then cannot un-register a route that is already there.
 *
 * Nothing else changes: the media, API and stylesheet routes sit outside that
 * branch and answer exactly as they do on any other test app.
 */
abstract class NullHelpCenterRouteTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('lin-codex.routes.help_center', null);
    }
}
