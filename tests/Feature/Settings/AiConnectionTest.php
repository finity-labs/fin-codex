<?php

use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use FinityLabs\FinCodex\Ai\AiSettings;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Ai\AiReason;
use Livewire\Livewire;

/*
 * AISET-02: the two actions beside the API key.
 *
 * Test connection makes one round trip through the seam with what the form
 * holds right now - the provider, the model and the typed key, else the
 * stored key, else nothing at all so the SDK's env key is left alone - and
 * changes neither the form nor storage. Remove stored key is the opposite:
 * it writes at once, without saving the rest of the page.
 *
 * The fake records every call, so the key precedence is proven on its log
 * rather than on a round trip nobody can make on a CI row.
 */

/** A fixture admin, signed in on the admin panel's guard. */
function finCodexAiConnectionUser(): User
{
    $user = User::create(['name' => 'Admin', 'email' => 'admin@example.com']);

    test()->actingAs($user, 'web');

    return $user;
}

/** The Test connection button, an affix action on the key field. */
function finCodexAiConnectionHandle(): TestAction
{
    return TestAction::make('test_connection')->schemaComponent('ai.api_key');
}

/** Remove stored key, the second affix action on the same field. */
function finCodexAiConnectionRemoveHandle(): TestAction
{
    return TestAction::make('remove_key')->schemaComponent('ai.api_key');
}

it('reports a working connection naming the provider and the model', function (): void {
    $fake = finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiConnectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.model_choice', 'fake-smartest')
        ->set('data.ai.api_key', 'sk-typed')
        ->callAction(finCodexAiConnectionHandle())
        ->assertNotified(__('fin-codex::fin-codex.settings.ai.test_connection_ok', [
            'provider' => 'Anthropic',
            'model' => 'fake-smartest',
        ]));

    expect($fake->connectionTests)->toBe([
        ['provider' => 'anthropic', 'model' => 'fake-smartest', 'apiKey' => 'sk-typed'],
    ])
        // Nothing written and nothing changed on the form.
        ->and(finCodexAiRows())->toBe(6)
        ->and(AiSettings::storedApiKey())->toBeNull()
        ->and($page->get('data.ai.api_key'))->toBe('sk-typed');
});

it('names the default tier when the form has no model', function (): void {
    $fake = finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiConnectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.model_choice', 'custom')
        ->callAction(finCodexAiConnectionHandle())
        ->assertNotified(__('fin-codex::fin-codex.settings.ai.test_connection_ok', [
            'provider' => 'Anthropic',
            'model' => 'fake-default',
        ]));

    expect($fake->connectionTests[0]['model'])->toBeNull();
});

it('names the provider default when the seam lists no tiers', function (): void {
    $fake = finCodexFakeAi(new FakeAiClient(tiers: null));

    $this->usesPanel('admin', finCodexAiConnectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.provider', 'anthropic')
        ->callAction(finCodexAiConnectionHandle())
        ->assertNotified(__('fin-codex::fin-codex.settings.ai.test_connection_ok', [
            'provider' => 'Anthropic',
            'model' => __('fin-codex::fin-codex.settings.ai.provider_default'),
        ]));

    expect($fake->connectionTests[0]['model'])->toBeNull();
});

it('prefers the typed key, then the stored key, then nothing', function (): void {
    $fake = finCodexFakeAi();
    finCodexEnableAi(['api_key' => 'sk-stored']);

    $this->usesPanel('admin', finCodexAiConnectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.api_key', 'sk-typed')
        ->callAction(finCodexAiConnectionHandle());

    expect($fake->connectionTests[0]['apiKey'])->toBe('sk-typed');

    $page->set('data.ai.api_key', '')->callAction(finCodexAiConnectionHandle());

    expect($fake->connectionTests[1]['apiKey'])->toBe('sk-stored');

    // Nothing typed and nothing stored: the seam is handed null, which is what
    // leaves the SDK's own env credential untouched.
    AiSettings::write(['api_key' => null]);

    $page->callAction(finCodexAiConnectionHandle());

    expect($fake->connectionTests[2]['apiKey'])->toBeNull();
});

it('names the reason when the provider rejects the key or times out', function (string $reason): void {
    finCodexFakeAi(new FakeAiClient(connection: $reason));

    $this->usesPanel('admin', finCodexAiConnectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.api_key', 'sk-typed')
        ->callAction(finCodexAiConnectionHandle())
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title(__('fin-codex::fin-codex.settings.ai.test_connection_failed'))
                ->body(AiReason::label($reason)),
        );

    expect(finCodexAiRows())->toBe(6)
        ->and(AiSettings::storedApiKey())->toBeNull();
})->with([AiReason::AUTHENTICATION_FAILED, AiReason::TIMEOUT]);

it('asks for a provider before it calls anything', function (): void {
    $fake = finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiConnectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.api_key', 'sk-typed')
        ->callAction(finCodexAiConnectionHandle())
        ->assertNotified(__('fin-codex::fin-codex.settings.ai.test_connection_no_provider'));

    expect($fake->connectionTests)->toBe([]);
});

it('works whatever the toggle says', function (): void {
    $fake = finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiConnectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.enabled', false)
        ->set('data.ai.provider', 'anthropic')
        ->callAction(finCodexAiConnectionHandle());

    expect($fake->connectionTests)->toHaveCount(1)
        ->and($page->get('data.ai.enabled'))->toBeFalse();
});

it('removes the stored key immediately and flips the placeholder', function (): void {
    finCodexFakeAi();
    finCodexEnableAi(['api_key' => 'sk-stored']);

    $this->usesPanel('admin', finCodexAiConnectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->assertSee(__('fin-codex::fin-codex.settings.ai.key_stored'))
        ->callAction(finCodexAiConnectionRemoveHandle())
        ->assertNotified(__('fin-codex::fin-codex.settings.ai.remove_key_done'))
        ->assertSee(__('fin-codex::fin-codex.settings.ai.key_missing'))
        ->assertDontSee(__('fin-codex::fin-codex.settings.ai.key_stored'));

    expect(AiSettings::storedApiKey())->toBeNull()
        // The rest of the page is untouched, in storage and on the form.
        ->and(finCodexAiRows())->toBe(6)
        ->and($page->get('data.ai.enabled'))->toBeTrue()
        ->and($page->get('data.ai.provider'))->toBe('anthropic');
});

it('hides Remove stored key when no key is stored', function (): void {
    finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiConnectionUser());

    // assertActionDoesNotExist() is the wrong instrument for an affix action:
    // Field::cacheActions() registers every prefix and suffix action whatever
    // visible() says, so the lookup finds it and only the visibility check
    // reports the truth. The button itself is absent from the page.
    Livewire::test(AdminHelpSettings::class)
        ->assertOk()
        ->assertActionHidden(finCodexAiConnectionRemoveHandle())
        ->assertDontSee(__('fin-codex::fin-codex.settings.ai.remove_key'));
});

it('requires the key only while enabled with nothing stored and no env key', function (): void {
    finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiConnectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.enabled', true)
        ->set('data.ai.provider', 'anthropic')
        ->call('save')
        ->assertHasFormErrors(['ai.api_key' => 'required']);

    // The SDK's own env credential counts as a key.
    config(['ai.providers.anthropic.key' => 'env-key']);

    $page->call('save')->assertHasNoFormErrors();

    config(['ai.providers.anthropic.key' => null]);

    // So does one already in storage, which is why a blank field is allowed to
    // mean "keep it".
    AiSettings::write(['api_key' => 'sk-stored']);

    $page->call('save')->assertHasNoFormErrors();

    AiSettings::write(['api_key' => null]);

    $page->call('save')->assertHasFormErrors(['ai.api_key' => 'required']);

    // Ollama is reached over a URL, so it is never asked for one.
    $page->set('data.ai.provider', 'ollama')
        ->assertSee(__('fin-codex::fin-codex.settings.ai.ollama_help'))
        ->call('save')
        ->assertHasNoFormErrors();
});
