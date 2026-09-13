<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A multi-factor provider with nothing behind it.
 *
 * It exists so a test panel can answer hasMultiFactorAuthentication() without
 * pulling in Filament's app-authentication provider, which resolves a Google2FA
 * service and a QR code renderer this package does not install. Nothing here is
 * ever challenged: the picker only asks the panel whether it has providers and
 * whether it requires them.
 */
final class FixtureMultiFactorProvider implements MultiFactorAuthenticationProvider
{
    public function isEnabled(Authenticatable $user): bool
    {
        return true;
    }

    public function getId(): string
    {
        return 'fin-codex-fixture';
    }

    public function getLoginFormLabel(): string
    {
        return 'Fixture two-factor';
    }

    /**
     * @return array<Component|Action>
     */
    public function getManagementSchemaComponents(): array
    {
        return [];
    }

    /**
     * @return array<Component|Action|ActionGroup>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return [];
    }
}
