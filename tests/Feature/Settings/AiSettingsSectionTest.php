<?php

use Filament\Actions\Testing\TestAction;
use FinityLabs\FinCodex\Ai\AiSettings;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Ai\AiAvailability;
use FinityLabs\LinCodex\Settings\CodexAiSettings;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Translation\DefaultInstructions;
use Livewire\Livewire;
use Spatie\LaravelSettings\Models\SettingsProperty;

/*
 * AISET-01 and AISET-03: the fourth section on the Help settings page.
 *
 * The section exists only when the seam reports the SDK installed; without it
 * the page carries one install note and nothing else about AI, which is what
 * keeps HelpSettingsTest's "mounts with the five settings keys" row green on
 * every CI row (the real client always reports not installed there).
 *
 * With the seam installed the page fills from a second settings group, saves
 * it with the one Save button, and never echoes the stored key. The fake is
 * bound BEFORE Livewire::test(): the section's schema closure asks the seam
 * on the very first render.
 */

/** A fixture admin, signed in on the admin panel's guard. */
function finCodexAiSectionUser(): User
{
    $user = User::create(['name' => 'Admin', 'email' => 'admin@example.com']);

    test()->actingAs($user, 'web');

    return $user;
}

/**
 * The AI settings as the database holds them.
 *
 * spatie binds each settings class as a container singleton, so reading
 * app(CodexAiSettings::class) straight after a save hands back the very
 * object the save filled. forgetInstance() is what makes the read a proof.
 */
function finCodexAiSectionStored(): CodexAiSettings
{
    app()->forgetInstance(CodexAiSettings::class);

    return app(CodexAiSettings::class);
}

it('shows only the install note when the seam is not installed', function (): void {
    finCodexFakeAi(new FakeAiClient(installed: false));

    $this->usesPanel('admin', finCodexAiSectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->assertOk()
        ->assertSee(__('fin-codex::fin-codex.settings.ai.section'))
        ->assertSee(__('fin-codex::fin-codex.settings.ai.install_note'))
        // The section's own description names Test connection in prose, so the
        // absence of the action is asserted on the field it hangs off rather
        // than on that string.
        ->assertDontSee(__('fin-codex::fin-codex.settings.ai.api_key'))
        ->assertDontSee(__('fin-codex::fin-codex.settings.ai.status_available'))
        ->assertFormFieldDoesNotExist('ai.api_key');

    expect(array_keys($page->get('data')))->toEqualCanonicalizing([
        'languages',
        'default_locale',
        'fallback',
        'revisions_enabled',
        'revisions_keep',
    ]);
});

it('opens on the stored AI values with the key blanked', function (): void {
    finCodexFakeAi();
    finCodexEnableAi(['api_key' => 'sk-secret', 'model' => 'fake-smartest', 'timeout' => 90]);

    $this->usesPanel('admin', finCodexAiSectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->assertOk()
        ->assertDontSee('sk-secret')
        ->assertSee(__('fin-codex::fin-codex.settings.ai.key_stored'));

    $ai = $page->get('data.ai');

    expect(array_keys($ai))->toEqualCanonicalizing([
        'enabled',
        'provider',
        'model',
        'model_choice',
        'api_key',
        'timeout',
        'translation_instructions',
    ])
        ->and($ai['enabled'])->toBeTrue()
        ->and($ai['provider'])->toBe('anthropic')
        ->and($ai['model'])->toBe('fake-smartest')
        ->and($ai['model_choice'])->toBe('fake-smartest')
        ->and($ai['api_key'])->toBe('')
        // TextInput::numeric() casts on the way in, the revisions_keep precedent.
        ->and($ai['timeout'])->toEqual(90)
        ->and($ai['translation_instructions'])->toBe(DefaultInstructions::TEXT);
});

it('opens on the defaults when the group is unseeded and saves six rows', function (): void {
    finCodexFakeAi();
    finCodexAiUnseed();

    $this->usesPanel('admin', finCodexAiSectionUser());

    $page = Livewire::test(AdminHelpSettings::class)->assertOk();

    expect($page->get('data.ai.enabled'))->toBeFalse()
        ->and($page->get('data.ai.provider'))->toBeNull()
        ->and($page->get('data.ai.timeout'))->toEqual(120);

    $page->set('data.ai.timeout', '60')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(finCodexAiRows())->toBe(6)
        ->and(finCodexAiSectionStored()->timeout)->toBe(60)
        // Seeding by writing touches the AI group only.
        ->and(SettingsProperty::query()->where('group', 'lin-codex')->count())->toBe(5);
});

it('saves the AI values together with the rest of the settings', function (): void {
    finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiSectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->set('data.revisions_keep', '5')
        ->set('data.ai.enabled', true)
        // The provider first: it resets the model the way the browser does.
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.model_choice', 'fake-smartest')
        ->set('data.ai.api_key', 'sk-new')
        ->set('data.ai.timeout', '90')
        ->set('data.ai.translation_instructions', 'Be brief.')
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = finCodexAiSectionStored();

    expect($stored->enabled)->toBeTrue()
        ->and($stored->provider)->toBe('anthropic')
        ->and($stored->model)->toBe('fake-smartest')
        ->and($stored->api_key)->toBe('sk-new')
        ->and($stored->timeout)->toBe(90)
        ->and($stored->timeout)->toBeInt()
        ->and($stored->translation_instructions)->toBe('Be brief.')
        ->and(finCodexAiRows())->toBe(6);

    app()->forgetInstance(CodexSettings::class);

    expect(app(CodexSettings::class)->revisions_keep)->toBe(5);
});

it('keeps the stored key on a blank field and replaces it on a typed one', function (): void {
    finCodexFakeAi();
    finCodexEnableAi(['api_key' => 'sk-old']);

    $this->usesPanel('admin', finCodexAiSectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(finCodexAiSectionStored()->api_key)->toBe('sk-old');

    $page->set('data.ai.api_key', 'sk-new')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(finCodexAiSectionStored()->api_key)->toBe('sk-new');
});

it('requires a provider only while AI is enabled', function (): void {
    finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiSectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.enabled', true)
        ->set('data.ai.provider', null)
        ->call('save')
        ->assertHasFormErrors(['ai.provider' => 'required']);

    $page->set('data.ai.enabled', false)
        ->call('save')
        ->assertHasNoFormErrors();
});

it("resets the model to the new provider's default and drops a custom id", function (): void {
    finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiSectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.provider', 'anthropic');

    expect($page->get('data.ai.model_choice'))->toBe('fake-default')
        ->and($page->get('data.ai.model'))->toBe('fake-default');

    $page->set('data.ai.model_choice', 'custom');

    expect($page->get('data.ai.model'))->toBeNull();

    $page->set('data.ai.model', 'my-model')
        ->set('data.ai.provider', 'openai');

    expect($page->get('data.ai.model'))->toBe('fake-default')
        ->and($page->get('data.ai.model_choice'))->toBe('fake-default');

    $page->set('data.ai.model_choice', 'custom')
        ->set('data.ai.model', 'my-model')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(finCodexAiSectionStored()->model)->toBe('my-model');
});

it('shows the custom text input alone when the seam lists no tiers', function (): void {
    finCodexFakeAi(new FakeAiClient(tiers: null));
    finCodexEnableAi(['model' => 'typed-model']);

    $this->usesPanel('admin', finCodexAiSectionUser());

    $page = Livewire::test(AdminHelpSettings::class)
        ->assertOk()
        ->assertFormFieldHidden('ai.model_choice')
        ->assertFormFieldVisible('ai.model');

    expect($page->get('data.ai.model'))->toBe('typed-model')
        ->and($page->get('data.ai.model_choice'))->toBeNull();

    $page->call('save')->assertHasNoFormErrors();

    expect(finCodexAiSectionStored()->model)->toBe('typed-model');
});

it('reports availability in the status line on load and after save', function (): void {
    finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiSectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->assertOk()
        ->assertSee(AiAvailability::unavailable(AiAvailability::DISABLED)->label())
        ->assertDontSee(__('fin-codex::fin-codex.settings.ai.status_available'))
        ->set('data.ai.enabled', true)
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.api_key', 'sk-new')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee(__('fin-codex::fin-codex.settings.ai.status_available'));
});

it('names the missing piece in the status line for each unavailable state', function (): void {
    finCodexFakeAi();
    finCodexEnableAi(['provider' => null]);

    $this->usesPanel('admin', finCodexAiSectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->assertOk()
        ->assertSee(AiAvailability::unavailable(AiAvailability::NO_KEY)->label());

    // Last, because spatie's __set() loads the group first: enabling AI on a
    // group whose rows were just deleted throws MissingSettings.
    finCodexAiUnseed();

    Livewire::test(AdminHelpSettings::class)
        ->assertOk()
        ->assertSee(AiAvailability::unavailable(AiAvailability::NOT_MIGRATED)->label());
});

it('resets the instructions to the package default, asking only when they differ', function (): void {
    finCodexFakeAi();
    finCodexEnableAi(['translation_instructions' => 'Custom text']);

    $this->usesPanel('admin', finCodexAiSectionUser());

    $handle = TestAction::make('reset_instructions')->schemaComponent('ai.translation_instructions');

    $page = Livewire::test(AdminHelpSettings::class)->assertOk();

    expect($page->get('data.ai.translation_instructions'))->toBe('Custom text');

    $page->mountAction($handle);

    expect($page->instance()->getMountedAction()?->shouldOpenModal())->toBeTrue()
        ->and($page->get('data.ai.translation_instructions'))->toBe('Custom text');

    $page->unmountAction()->callAction($handle);

    expect($page->get('data.ai.translation_instructions'))->toBe(DefaultInstructions::TEXT);

    // Already the default: the action runs straight through, no modal.
    $page->mountAction($handle);

    expect($page->instance()->getMountedAction())->toBeNull()
        ->and($page->get('data.ai.translation_instructions'))->toBe(DefaultInstructions::TEXT);
});

it('keeps HelpSettings at exactly two guards', function (): void {
    // The same count HelpSettingsTest asserts, duplicated on purpose: the AI
    // reads and writes go through Ai\AiSettings, so this file fails first if a
    // third MissingSettings|QueryException guard is added to the page.
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Pages/HelpSettings.php');

    expect(substr_count($source, 'MissingSettings|QueryException'))->toBe(2)
        ->and($source)->toContain('AiSettings::write(');
});

it('never lets the AI values reach the CodexSettings group', function (): void {
    finCodexFakeAi();

    $this->usesPanel('admin', finCodexAiSectionUser());

    Livewire::test(AdminHelpSettings::class)
        ->set('data.ai.enabled', true)
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.api_key', 'sk-new')
        ->call('save')
        ->assertHasNoFormErrors();

    // Settings::fill() assigns every key it is handed, so an 'ai' key left in
    // the payload would become a row in the wrong group.
    expect(SettingsProperty::query()->where('group', 'lin-codex')->pluck('name')->all())
        ->toEqualCanonicalizing([
            'languages',
            'default_locale',
            'fallback',
            'revisions_enabled',
            'revisions_keep',
        ])
        ->and(AiSettings::storedApiKey())->toBe('sk-new');
});
