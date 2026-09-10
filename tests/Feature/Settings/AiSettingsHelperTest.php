<?php

use FinityLabs\FinCodex\Ai\AiSettings;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\LinCodex\Ai\AiAvailability;
use FinityLabs\LinCodex\Ai\AiAvailabilityCheck;
use FinityLabs\LinCodex\Ai\AiCallFailed;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Settings\CodexAiSettings;
use FinityLabs\LinCodex\Translation\ArticleTranslator;
use FinityLabs\LinCodex\Translation\DefaultInstructions;
use Spatie\LaravelSettings\Models\SettingsProperty;

/*
 * The foundation the rest of Phase 10 stands on: the one helper that reads
 * and writes the AI settings group, the fake seam every AI test binds, and
 * the harness helpers that reach each availability state.
 *
 * The settings page, the tab action and the install step all lean on exactly
 * these pieces, so they are proven here once rather than three times over.
 */

it('reads the seeded defaults and the stored values', function (): void {
    expect(AiSettings::values())->toBe(CodexAiSettings::defaults());

    finCodexEnableAi(['model' => 'claude-x']);

    expect(AiSettings::values())
        ->toMatchArray([
            'enabled' => true,
            'provider' => 'anthropic',
            'model' => 'claude-x',
            'api_key' => 'sk-test',
            'timeout' => 120,
        ]);
});

it('falls back to the defaults when the group is unseeded', function (): void {
    finCodexAiUnseed();

    expect(finCodexAiRows())->toBe(0)
        ->and(AiSettings::values())->toBe(CodexAiSettings::defaults());
});

it('reports the stored key or null', function (): void {
    expect(AiSettings::storedApiKey())->toBeNull();

    finCodexEnableAi(['api_key' => '']);

    expect(AiSettings::storedApiKey())->toBeNull();

    finCodexEnableAi(['api_key' => 'sk-test']);

    expect(AiSettings::storedApiKey())->toBe('sk-test');
});

it('writes overrides over the current values', function (): void {
    finCodexEnableAi();

    AiSettings::write(['timeout' => 30]);

    app()->forgetInstance(CodexAiSettings::class);
    $stored = app(CodexAiSettings::class);

    expect($stored->timeout)->toBe(30)
        ->and($stored->enabled)->toBeTrue()
        ->and($stored->provider)->toBe('anthropic');
});

it('seeds all six rows when the group has never been written', function (): void {
    finCodexAiUnseed();

    AiSettings::write(['provider' => 'openai']);

    expect(finCodexAiRows())->toBe(6);

    app()->forgetInstance(CodexAiSettings::class);
    $stored = app(CodexAiSettings::class);

    expect($stored->provider)->toBe('openai')
        ->and($stored->enabled)->toBeFalse()
        ->and($stored->timeout)->toBe(120)
        ->and($stored->translation_instructions)->toBe(DefaultInstructions::TEXT)
        // The core group is a different group and is left alone.
        ->and(SettingsProperty::query()->where('group', 'lin-codex')->count())->toBe(5);
});

it('stores the key encrypted', function (): void {
    AiSettings::write(['api_key' => 'sk-plain']);

    $payload = SettingsProperty::query()
        ->where('group', 'lin-codex-ai')
        ->where('name', 'api_key')
        ->value('payload');

    expect($payload)->toBeString()
        ->and($payload)->not->toBe('')
        ->and($payload)->not->toContain('sk-plain');

    // A fresh instance proves the row decrypts, not the object still in memory.
    app()->forgetInstance(CodexAiSettings::class);

    expect(AiSettings::storedApiKey())->toBe('sk-plain');

    AiSettings::write(['api_key' => null]);

    app()->forgetInstance(CodexAiSettings::class);

    expect(AiSettings::storedApiKey())->toBeNull();
});

it('binds the fake as the whole seam', function (): void {
    // The five availability states, in an order that leaves the unseeded one
    // last: enabling AI needs the group to be there.
    $fake = finCodexFakeAi(new FakeAiClient(installed: false));

    expect(app(AiAvailabilityCheck::class)->check()->reason)->toBe(AiAvailability::SDK_MISSING)
        ->and($fake->providers())->toBe([]);

    finCodexFakeAi();

    expect(app(AiAvailabilityCheck::class)->check()->reason)->toBe(AiAvailability::DISABLED);

    finCodexEnableAi(['provider' => null]);

    expect(app(AiAvailabilityCheck::class)->check()->reason)->toBe(AiAvailability::NO_KEY);

    finCodexEnableAi();

    expect(app(AiAvailabilityCheck::class)->available())->toBeTrue();

    finCodexEnableAi(['api_key' => null]);
    config(['ai.providers.anthropic.key' => 'env-key']);

    expect(app(AiAvailabilityCheck::class)->available())->toBeTrue();

    finCodexEnableAi(['provider' => 'ollama', 'api_key' => null]);
    config(['ai.providers.ollama.url' => 'http://localhost:11434']);

    expect(app(AiAvailabilityCheck::class)->available())->toBeTrue();

    finCodexAiUnseed();

    expect(app(AiAvailabilityCheck::class)->check()->reason)->toBe(AiAvailability::NOT_MIGRATED);
});

it('answers tiers, connection tests and structured calls as configured', function (): void {
    expect(finCodexFakeAi()->tierModels('anthropic'))->toBe([
        'default' => 'fake-default',
        'cheapest' => 'fake-cheapest',
        'smartest' => 'fake-smartest',
    ]);

    $blind = new FakeAiClient(tiers: null);

    expect(fn (): array => $blind->tierModels('anthropic'))
        ->toThrow(AiCallFailed::class);

    try {
        $blind->tierModels('anthropic');
    } catch (AiCallFailed $failure) {
        expect($failure->reason)->toBe(AiReason::UNAVAILABLE);
    }

    $refusing = new FakeAiClient(connection: AiReason::TIMEOUT);

    expect($refusing->testConnection('anthropic', null, 'k'))->toBe('timeout')
        ->and($refusing->connectionTests)->toBe([
            ['provider' => 'anthropic', 'model' => null, 'apiKey' => 'k'],
        ]);

    $fake = finCodexFakeAi(FakeAiClient::translating([
        'title' => 'Benutzer',
        'excerpt' => null,
        'body' => 'Text',
    ]));

    finCodexEnableAi();

    $result = app(ArticleTranslator::class)->translateText('Users', null, 'How users work.', 'de', 'en');

    expect($result->ok)->toBeTrue()
        ->and($result->title)->toBe('Benutzer')
        ->and($result->body)->toBe('Text')
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]->prompt)->toContain('How users work.');
});
