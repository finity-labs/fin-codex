<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Feature\Commands;

use FinityLabs\FinCodex\Tests\NullHelpCenterRouteTestCase;
use Illuminate\Support\Facades\Route;

/**
 * PLACE-04, the half the install step cannot prove on its own: what a host
 * actually gets once the published prefix is null.
 *
 * A PHPUnit-style class rather than Pest closures, because the state under
 * test is set while the application boots and a Pest file boots the shared
 * harness. Routing only - a panel page under a null prefix is plan 16-03's,
 * and the published topbar view still resolves the core route by name.
 */
class PublicHelpCenterRouteTest extends NullHelpCenterRouteTestCase
{
    public function test_the_public_help_center_answers_404(): void
    {
        // Status codes, not assertNotFound(): a redirect to a login would be a
        // different answer with the same feel, and this row is the difference.
        $this->assertSame(404, $this->get('/help')->getStatusCode());
        $this->assertSame(404, $this->get('/help/users')->getStatusCode());
    }

    public function test_neither_help_center_route_is_registered(): void
    {
        $this->assertFalse(Route::has('lin-codex.help-center'));
        $this->assertFalse(Route::has('lin-codex.help-center.article'));
    }

    public function test_the_routes_outside_that_branch_are_untouched(): void
    {
        $this->assertTrue(Route::has('lin-codex.media'));
        $this->assertTrue(Route::has('lin-codex.api.tree'));
        $this->assertTrue(Route::has('lin-codex.assets.css'));

        $this->assertSame(200, $this->get('/codex/assets/codex.css')->getStatusCode());
    }
}
