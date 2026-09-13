<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Shield;

use FinityLabs\FinCodex\Tests\Fixtures\Pages\ShieldedHelpSettings;

/**
 * What `BezhanSalleh\FilamentShield\Facades\FilamentShield::getPages()` answers,
 * in the shape verified against Shield 4.x source:
 *
 *     [$pageClass => ['pageFqcn' => $pageClass, 'permissions' => [$key => $label]]]
 *
 * The key is Shield 4's real default naming — separator ':', pascal case, the
 * 'view' prefix for pages — so 'View:HelpSettings', NOT Shield 3's
 * 'page_HelpSettings'. Both halves are configurable on a host's install, which
 * is exactly why HasPageShieldSupport asks for the key instead of building it,
 * and why this stub returns a key rather than a rule for making one.
 *
 * This is a PLAIN CLASS, deliberately not named BezhanSalleh\* and deliberately
 * not registered anywhere. Defining a real `FilamentShieldPlugin` — even behind
 * class_exists() — would make the trait's isShieldAvailable() true for every
 * later test in the same PHP process, flipping the real HelpSettings onto the
 * Shield branch and making the Gate-fallback rows pass or fail depending on
 * file order. The fixture page overrides the trait's two protected seams
 * instead; nothing about the class map changes.
 */
final class ShieldStub
{
    /** The permission Shield would register for the fixture page. */
    public const PERMISSION = 'View:HelpSettings';

    /** The permission Shield 4 builds for the article resource's viewAllPanels ability. */
    public const VIEW_ALL_PANELS = 'ViewAllPanels:Article';

    /**
     * @return array<class-string, array{pageFqcn: class-string, permissions: array<string, string>}>
     */
    public static function getPages(): array
    {
        return [
            ShieldedHelpSettings::class => [
                'pageFqcn' => ShieldedHelpSettings::class,
                'permissions' => [self::PERMISSION => 'View'],
            ],
        ];
    }

    /**
     * What FilamentShield::getResourcePolicyActionsWithPermissions() answers for
     * the article resource on a host whose Shield config carries it: the policy
     * action keyed to the permission key, built from separator ':', pascal case
     * and the model as the subject.
     *
     * Same reasoning as getPages() — a key, not a rule for making one, because
     * both halves of the name are a host's to change.
     *
     * @return array<string, string>
     */
    public static function getResourcePermissions(): array
    {
        return ['viewAny' => 'ViewAny:Article', 'viewAllPanels' => self::VIEW_ALL_PANELS];
    }

    /**
     * The same answer on a host whose Shield entry predates the ability.
     * fin-codex only added viewAllPanels to resources.manage in 0.5.0, so an
     * entry an earlier install wrote lists the eight older abilities and Shield
     * has no permission to hand back for this one.
     *
     * @return array<string, string>
     */
    public static function getResourcePermissionsWithoutViewAllPanels(): array
    {
        return array_diff_key(self::getResourcePermissions(), ['viewAllPanels' => '']);
    }
}
