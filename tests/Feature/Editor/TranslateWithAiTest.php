<?php

use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\TranslateWithAiAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Tests\Fixtures\FakeAiClient;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Ai\AiCallFailed;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * AITAB-01 to AITAB-03: Translate with AI on the language tabs.
 *
 * Every row drives CreateArticle or EditArticle, so what is asserted is what
 * the admin gets: the button is resolved out of the live schema and pressed
 * through the page, never called by hand. The seam is the fake AiClient bound
 * by finCodexFakeAi(), which is the whole wiring - the availability rule and
 * ArticleTranslator both resolve it from the container at call time.
 *
 * The action lives inside an Actions row inside a keyed Tab, and Filament
 * resolves it by the tab key alone, so the handle below is the only address a
 * row needs. A hidden action cannot be resolved at all, which is why the
 * negative rows assert it does not exist rather than that it is hidden.
 *
 * With Tabs::livewireProperty() only the active tab's panel is rendered, so a
 * row that reads the markup for the data-fin-codex-translate hook has to open
 * that tab first.
 */

/** A fixture user signed in on the panel under test. */
function finCodexTranslateUser(string $name = 'Translate'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * The languages this file's articles are written in. Its own helper rather
 * than TranslationTabsTest's: a Pest helper is loaded with its file, so a
 * borrowed one is undefined the moment this file runs on its own.
 *
 * @param  list<string>  $codes
 */
function finCodexTranslateUseLanguages(array $codes = ['en', 'de', 'hu'], string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/**
 * An article with one complete English translation, the way the editor leaves
 * it. $en overrides the three default-language fields.
 *
 * @param  array<string, mixed>  $en
 */
function finCodexTranslateArticle(array $en = []): Article
{
    $article = Article::factory()->markdown()->create(['slug' => 'users']);

    ArticleTranslation::factory()->create(array_merge([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'Users',
        'excerpt' => 'Manage users.',
        'body' => 'How users work.',
    ], $en));

    return $article;
}

/** The translate button on one language tab. */
function finCodexTranslateHandle(string $code): TestAction
{
    return TestAction::make('translate_with_ai')->schemaComponent($code);
}

/** A seam that answers one call with a complete German translation. */
function finCodexTranslateFake(): FakeAiClient
{
    return finCodexFakeAi(FakeAiClient::translating([
        'title' => 'Benutzer',
        'excerpt' => 'Kurz',
        'body' => 'Text',
    ]));
}

beforeEach(function (): void {
    finCodexTranslateUseLanguages();
    $this->usesPanel('admin', finCodexTranslateUser());
});

it('shows Translate with AI on every non-default tab and never on the default one', function (): void {
    finCodexTranslateFake();
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->assertActionVisible(finCodexTranslateHandle('de'))
        ->assertActionVisible(finCodexTranslateHandle('hu'))
        ->assertActionDoesNotExist(finCodexTranslateHandle('en'))
        ->assertActionVisible(TestAction::make('copy_from_default')->schemaComponent('de'));

    // The default tab is the one the form opens on, and it carries no hook.
    expect($component->html())->not->toContain('data-fin-codex-translate')
        ->and($component->set('activeLocale', 'de')->html())->toContain('data-fin-codex-translate="de"')
        ->and($component->set('activeLocale', 'hu')->html())->toContain('data-fin-codex-translate="hu"');
});

it('shows it on the create page too', function (): void {
    finCodexTranslateFake();
    finCodexEnableAi();

    Livewire::test(CreateArticle::class)
        ->assertActionVisible(finCodexTranslateHandle('de'))
        ->assertActionDoesNotExist(finCodexTranslateHandle('en'));
});

it('hides it for each unavailable state', function (Closure $state): void {
    $state();

    $article = finCodexTranslateArticle();

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->assertActionDoesNotExist(finCodexTranslateHandle('de'))
        ->assertActionVisible(TestAction::make('copy_from_default')->schemaComponent('de'));
})->with([
    'sdk_missing' => [fn (): FakeAiClient => finCodexFakeAi(new FakeAiClient(installed: false))],
    'disabled' => [fn (): FakeAiClient => finCodexFakeAi()],
    'no_key' => [function (): void {
        finCodexFakeAi();
        finCodexEnableAi(['provider' => null]);
    }],
    // Last, because spatie loads the group on the first write: enabling AI on
    // rows that were just deleted throws instead of enabling anything.
    'not_migrated' => [function (): void {
        finCodexFakeAi();
        finCodexAiUnseed();
    }],
]);

it('hides it on an HTML article', function (): void {
    finCodexTranslateFake();
    finCodexEnableAi();

    $article = Article::factory()->html()->public()->published()
        ->withTranslation('en', ['title' => 'Hi', 'body' => '<h2>Hi</h2><p>Text.</p>'])
        ->create(['slug' => 'legacy']);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->assertActionDoesNotExist(finCodexTranslateHandle('de'))
        ->assertActionVisible(TestAction::make('copy_from_default')->schemaComponent('de'));
});

it('takes it away from a user the policy refuses', function (): void {
    finCodexTranslateFake();
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    // Mounted while the policy still allows: a deny-all policy in force from
    // the start 403s the page itself, which would pass for the wrong reason.
    $edit = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);
    $create = Livewire::test(CreateArticle::class);

    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    $edit->assertActionDoesNotExist(finCodexTranslateHandle('de'));
    $create->assertActionDoesNotExist(finCodexTranslateHandle('de'));
});

it('disables it with a tooltip while the default tab is blank', function (): void {
    $fake = finCodexTranslateFake();
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['translations' => ['en' => ['title' => '', 'body' => 'x']]])
        ->assertActionDisabled(finCodexTranslateHandle('de'));

    expect($component->set('activeLocale', 'de')->html())
        ->toContain(e(__('fin-codex::fin-codex.editor.translate.empty_source', ['language' => 'English'])));

    $component
        ->callAction(finCodexTranslateHandle('de'))
        ->assertFormSet(['translations.de.title' => null]);

    expect($fake->requests)->toBeEmpty();

    $component
        ->fillForm(['translations' => ['en' => ['title' => 'Users', 'body' => '']]])
        ->assertActionDisabled(finCodexTranslateHandle('de'))
        ->fillForm(['translations' => ['en' => ['title' => 'Users', 'body' => 'How users work.']]])
        ->assertActionEnabled(finCodexTranslateHandle('de'));
});

it('fills title, excerpt and body from the default tab unsaved text and saves nothing', function (): void {
    $fake = finCodexTranslateFake();
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->set('activeLocale', 'de')
        ->fillForm(['translations' => ['en' => ['title' => 'Users (draft)']]])
        ->callAction(finCodexTranslateHandle('de'))
        ->assertFormSet([
            'translations.de.title' => 'Benutzer',
            'translations.de.excerpt' => 'Kurz',
            'translations.de.body' => 'Text',
            'translations.en.title' => 'Users (draft)',
        ])
        ->assertNotified(__('fin-codex::fin-codex.editor.translate.done', ['language' => 'Deutsch']));

    expect($component->get('activeLocale'))->toBe('de')
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]->prompt)->toContain('Users (draft)')
        ->toContain('Manage users.')
        ->toContain('How users work.')
        ->and(ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'de')->count())->toBe(0)
        ->and(ArticleRevision::query()->where('article_id', $article->id)->count())->toBe(0);
});

it('clears the excerpt when the translation has none', function (): void {
    finCodexFakeAi(FakeAiClient::translating(['title' => 'Benutzer', 'excerpt' => null, 'body' => 'Text']));
    finCodexEnableAi();

    $article = finCodexTranslateArticle(['excerpt' => null]);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['translations' => ['de' => ['excerpt' => 'Kurz']]])
        ->callAction(finCodexTranslateHandle('de'))
        ->assertFormSet([
            'translations.de.title' => 'Benutzer',
            'translations.de.excerpt' => null,
            'translations.de.body' => 'Text',
        ]);
});

it('asks before replacing a tab that already has text', function (array $existing): void {
    $fake = finCodexTranslateFake();
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['translations' => ['de' => $existing]])
        ->mountAction(finCodexTranslateHandle('de'));

    $action = $component->instance()->getMountedAction();

    expect($action?->shouldOpenModal())->toBeTrue()
        ->and($action?->getModalHeading())->toBe(__('fin-codex::fin-codex.editor.translate.heading', ['language' => 'Deutsch']))
        ->and($action?->getModalDescription())->toBe(__('fin-codex::fin-codex.editor.translate.description', [
            'language' => 'Deutsch',
            'default' => 'English',
        ]))
        ->and($action?->getModalSubmitAction()?->getLabel())->toBe(__('fin-codex::fin-codex.editor.translate.submit'))
        ->and($fake->requests)->toBeEmpty();

    $component
        ->assertFormSet(['translations.de.title' => $existing['title'] ?? null])
        ->unmountAction()
        ->callAction(finCodexTranslateHandle('de'))
        ->assertFormSet([
            'translations.de.title' => 'Benutzer',
            'translations.de.excerpt' => 'Kurz',
            'translations.de.body' => 'Text',
        ]);
})->with([
    'a title' => [['title' => 'Alt']],
    'an excerpt alone' => [['excerpt' => 'x']],
]);

it('runs without a modal on an empty tab', function (): void {
    finCodexTranslateFake();
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->mountAction(finCodexTranslateHandle('de'));

    expect($component->instance()->getMountedAction())->toBeNull();

    $component->assertFormSet(['translations.de.title' => 'Benutzer']);
});

/*
 * unavailable arrives through the seam rather than by switching AI off between
 * the mount and the press: the schema is rebuilt on every Livewire request and
 * asks the same availability rule the translator does, so switching it off
 * takes the button off the page before the press lands - which is the point of
 * the gate, and leaves the seam as the only way to exercise the branch.
 */
it('leaves the tab untouched and names the reason when the call fails', function (string $reason, bool $hint): void {
    finCodexFakeAi((new FakeAiClient)->push(new AiCallFailed($reason)));
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    $body = AiReason::label($reason);

    if ($hint) {
        $body .= ' '.__('fin-codex::fin-codex.editor.translate.check_settings');
    }

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['translations' => ['de' => ['title' => 'Alt']]])
        ->callAction(finCodexTranslateHandle('de'))
        ->assertFormSet([
            'translations.de.title' => 'Alt',
            'translations.de.excerpt' => null,
            'translations.de.body' => null,
        ])
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title(__('fin-codex::fin-codex.editor.translate.failed'))
                ->body($body),
        );
})->with([
    'authentication_failed points at the settings page' => [AiReason::AUTHENTICATION_FAILED, true],
    'unavailable points at the settings page' => [AiReason::UNAVAILABLE, true],
    'timeout is the bare label' => [AiReason::TIMEOUT, false],
]);

it('leaves reporting an unknown reason to lin-codex', function (): void {
    Exceptions::fake();

    finCodexFakeAi((new FakeAiClient)->push(new RuntimeException('boom')));
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->callAction(finCodexTranslateHandle('de'))
        ->assertFormSet(['translations.de.title' => null])
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title(__('fin-codex::fin-codex.editor.translate.failed'))
                ->body(AiReason::label(AiReason::UNKNOWN)),
        );

    // lin-codex 0.3.1 hands the original throwable to report() where the
    // reason becomes unknown, so the host's error tooling gets the real
    // stack...
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'boom');

    // ...and this package no longer adds a synthetic one beside it.
    Exceptions::assertNotReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[unknown]'));
});

it('never reports a reason it can name', function (): void {
    Exceptions::fake();

    finCodexFakeAi((new FakeAiClient)->push(new AiCallFailed(AiReason::RATE_LIMITED)));
    finCodexEnableAi();

    $article = finCodexTranslateArticle();

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->callAction(finCodexTranslateHandle('de'))
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title(__('fin-codex::fin-codex.editor.translate.failed'))
                ->body(AiReason::label(AiReason::RATE_LIMITED)),
        );

    Exceptions::assertNothingReported();
});

it('raises a short execution limit for the call and never lowers an unlimited one', function (): void {
    $original = ini_get('max_execution_time');

    try {
        ini_set('max_execution_time', '30');
        TranslateWithAiAction::extendTimeLimit(120);
        expect((int) ini_get('max_execution_time'))->toBe(150);

        ini_set('max_execution_time', '0');
        TranslateWithAiAction::extendTimeLimit(120);
        expect((int) ini_get('max_execution_time'))->toBe(0);

        ini_set('max_execution_time', '600');
        TranslateWithAiAction::extendTimeLimit(120);
        expect((int) ini_get('max_execution_time'))->toBe(600);
    } finally {
        // Back to the CLI default, or the rest of the suite runs on a clock.
        set_time_limit(is_string($original) ? (int) $original : 0);
    }
});
