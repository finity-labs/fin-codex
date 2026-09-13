<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Feature\Panel;

use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\FinCodex\Tests\NullHelpCenterRouteTestCase;

/**
 * The other half of PLACE-04, and the row plan 16-03 owes plan 16-02: the
 * panel keeps working once the public help center is gone.
 *
 * The topbar button and the guest link used to resolve the core's public
 * help-center route by name, so on a host that ran fin-codex:install every
 * panel page answered 500 instead of 200. Both take their href as a prop now,
 * and these rows are what says so end to end.
 *
 * A PHPUnit-style class rather than Pest closures, for the reason
 * PublicHelpCenterRouteTest gives: the null prefix is set while the
 * application boots, and a Pest file boots the shared harness.
 */
class NullPrefixPanelTest extends NullHelpCenterRouteTestCase
{
    public function test_a_panel_page_still_renders_with_the_public_help_center_gone(): void
    {
        $user = User::create(['name' => 'Tester', 'email' => 'null-prefix@example.com']);

        $response = $this->actingAs($user, 'web')->get('/admin');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-fin-codex-help-button="admin"', $response->getContent());
        $this->assertSame(404, $this->get('/help')->getStatusCode());
    }

    public function test_the_panels_own_help_center_page_still_answers(): void
    {
        $user = User::create(['name' => 'Tester', 'email' => 'null-prefix-page@example.com']);

        $this->assertSame(200, $this->actingAs($user, 'web')->get('/admin/help')->getStatusCode());
    }

    public function test_a_guest_page_still_renders_with_the_public_help_center_gone(): void
    {
        $response = $this->get('/admin/login');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-fin-codex-guest-link="admin"', $response->getContent());
    }
}
