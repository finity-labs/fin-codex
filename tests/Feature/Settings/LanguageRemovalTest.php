<?php

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpSettings;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\FallbackBehaviour;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * SET-02's second half: removing a language warns twice and blocks once.
 *
 * The locked decision is that a settings save never deletes a translation.
 * A removed language disappears from the editor tabs and from the reader's
 * fallback chain, and the texts written in it stay in the database, ready to
 * come back the moment the code is added again. The single exception that
 * blocks is removing the language that is currently the default, because a
 * fallback pointing at a language that is no longer configured leaves the
 * reader with nothing.
 *
 * Helpers here are prefixed finCodexRemoval* — Pest helpers are global and
 * finCodexSettings* belongs to HelpSettingsTest, which is why this file
 * carries its own seed and read-back rather than reusing them: a single-file
 * run of either must work on its own.
 */

/** A fixture user signed in on the admin (web) guard. */
function finCodexRemovalUser(): User
{
    $user = User::create(['name' => 'Admin', 'email' => 'admin@example.com']);

    test()->actingAs($user, 'web');

    return $user;
}

/**
 * Store a whole settings group, the way an installation that has saved once
 * looks. The harness seeds the group already, so this overwrites.
 *
 * @param  list<string>  $codes
 */
function finCodexRemovalSeed(array $codes = ['en', 'de'], string $default = 'en'): CodexSettings
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->fallback = FallbackBehaviour::ShowDefault;
    $settings->revisions_enabled = true;
    $settings->revisions_keep = 10;
    $settings->save();

    return $settings;
}

/**
 * The settings as the database holds them right now. The page's fresh-install
 * guard may have bound an instance for the rest of the request, so a post-save
 * read drops it first.
 */
function finCodexRemovalStored(): CodexSettings
{
    app()->forgetInstance(CodexSettings::class);

    return app(CodexSettings::class);
}

/** @return list<string> The stored language codes, in order. */
function finCodexRemovalStoredCodes(): array
{
    return array_values(array_column(finCodexRemovalStored()->languages, 'code'));
}

/**
 * $count articles, each with one translation in $locale. Separate articles
 * rather than one article in many languages: the count is per locale and the
 * shape does not matter to it.
 *
 * @return list<ArticleTranslation>
 */
function finCodexRemovalTranslations(string $locale, int $count): array
{
    $translations = [];

    for ($index = 0; $index < $count; $index++) {
        $article = Article::factory()->create(['slug' => $locale.'-article-'.$index]);

        $translations[] = ArticleTranslation::factory()->create([
            'article_id' => $article->id,
            'locale' => $locale,
            'title' => strtoupper($locale).' title '.$index,
            'body' => strtoupper($locale).' body '.$index,
        ]);
    }

    return $translations;
}

/** The admin panel's settings page, mounted and signed in. */
function finCodexRemovalPage(): Testable
{
    test()->usesPanel('admin', finCodexRemovalUser());

    return Livewire::test(AdminHelpSettings::class);
}

/**
 * The form's language rows keyed by code, so a test can drop or rename one
 * without caring about the repeater's uuid keys.
 *
 * @return array<string, array<string, mixed>>
 */
function finCodexRemovalRows(Testable $page): array
{
    /** @var array<string, array<string, mixed>> $rows */
    $rows = $page->get('data.languages');

    return collect($rows)->keyBy(fn (array $row): string => (string) $row['code'])->all();
}

/*
 * The live count: what a language holds, shown before anything is removed.
 */

it('shows how many translations each language holds', function (): void {
    finCodexRemovalSeed(['en', 'de']);
    finCodexRemovalTranslations('en', 3);
    finCodexRemovalTranslations('de', 1);

    finCodexRemovalPage()
        ->assertOk()
        // The marker carries the row's code and its count together: one
        // attribute is enough to tell the rows apart.
        ->assertSee('data-fin-codex-language-count="en:3"', escape: false)
        ->assertSee('data-fin-codex-language-count="de:1"', escape: false);
});

it('shows zero for a language nobody has written in yet', function (): void {
    finCodexRemovalSeed(['en', 'fr']);
    finCodexRemovalTranslations('en', 2);

    finCodexRemovalPage()
        ->assertSee('data-fin-codex-language-count="en:2"', escape: false)
        ->assertSee('data-fin-codex-language-count="fr:0"', escape: false);
});

it('counts what the row says now, not what the settings still hold', function (): void {
    finCodexRemovalSeed(['en', 'de']);
    finCodexRemovalTranslations('en', 1);
    finCodexRemovalTranslations('fr', 2);

    $page = finCodexRemovalPage();

    $page->assertSee('data-fin-codex-language-count="de:0"', escape: false);

    $rows = finCodexRemovalRows($page);
    $rows['de']['code'] = 'fr';

    $page->set('data.languages', array_values($rows))
        ->assertSee('data-fin-codex-language-count="fr:2"', escape: false)
        ->assertDontSee('data-fin-codex-language-count="de:0"', escape: false);

    // Nothing was saved: the stored list is untouched.
    expect(finCodexRemovalStoredCodes())->toBe(['en', 'de']);
});

/*
 * The one thing this phase blocks.
 */

it('refuses a save that removes the current default language', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');
    finCodexRemovalTranslations('en', 2);

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['de']])
        ->call('save')
        ->assertHasErrors(['data.languages']);

    // Ours is the message on data.languages. Filament's own generic "The
    // selected default locale is invalid." also lands, on data.default_locale,
    // from the select's implicit in: rule — both are kept, because the admin
    // has two fields to reconcile.
    expect($page->errors()->get('data.languages'))
        ->toContain((string) __('fin-codex::fin-codex.settings.default_locale_removed', ['locale' => 'en']))
        // Nothing was written.
        ->and(finCodexRemovalStoredCodes())->toBe(['en', 'de'])
        ->and(finCodexRemovalStored()->default_locale)->toBe('en');
});

it('names the language it refuses to drop', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'de');

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['en']])->call('save');

    expect($page->errors()->get('data.languages'))
        ->toContain((string) __('fin-codex::fin-codex.settings.default_locale_removed', ['locale' => 'de']))
        ->and(implode(' ', $page->errors()->get('data.languages')))->toContain('de');
});

it('treats a renamed code as a removal', function (): void {
    finCodexRemovalSeed(['en'], 'en');

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);
    $rows['en']['code'] = 'en-gb';

    $page->set('data.languages', array_values($rows))
        ->call('save')
        ->assertHasErrors(['data.languages']);

    expect(finCodexRemovalStoredCodes())->toBe(['en']);
});

it('lets the same edit through once a new default is picked', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');
    finCodexRemovalTranslations('en', 2);

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['de']])
        ->set('data.default_locale', 'de')
        ->call('save')
        ->assertHasNoErrors();

    expect(finCodexRemovalStoredCodes())->toBe(['de'])
        ->and(finCodexRemovalStored()->default_locale)->toBe('de');
});

it('does not fire on an ordinary save that removes nothing', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);
    $rows['de']['display'] = 'Deutsch (Schweiz)';

    $page->set('data.languages', array_values($rows))
        ->call('save')
        ->assertHasNoErrors();

    expect(finCodexRemovalStoredCodes())->toBe(['en', 'de'])
        ->and(finCodexRemovalStored()->languages[1]['display'])->toBe('Deutsch (Schweiz)');
});

it('counts translations without naming a table', function (): void {
    finCodexRemovalTranslations('hu', 4);

    expect(HelpSettings::translationCount('hu'))->toBe(4)
        ->and(HelpSettings::translationCount('nl'))->toBe(0);
});

/*
 * The second warning: a save that drops a language asks once more.
 *
 * SettingsPage's own save button cannot carry a modal — with the form wrapper
 * it renders type="submit" and no wire:click at all, and without it a string
 * action() short-circuits into a direct method call. getFormActions() is
 * replaced by a plain Action whose action() is a closure, which renders a
 * mountAction() handler. The test handle is the same shape: the empty
 * schema: argument is load-bearing, because getDefaultTestingSchemaName() is
 * 'form' and a bare schemaComponent('content.form-actions') would resolve to
 * form.content.form-actions and silently not mount.
 */

/** Mount the replaced save button and hand back the mounted Action. */
function finCodexRemovalMountSave(Testable $page): ?Action
{
    $page->mountAction(TestAction::make('save')->schemaComponent('content.form-actions', schema: ''));

    return $page->instance()->getMountedAction();
}

/** The save button as getFormActions() hands it over, unmounted. */
function finCodexRemovalSaveAction(Testable $page): Action
{
    /** @var Action $action */
    $action = collect($page->instance()->getFormActions())
        ->first(fn (Action $action): bool => $action->getName() === 'save');

    return $action;
}

it('asks once more when a save is about to drop a language', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');
    finCodexRemovalTranslations('de', 2);

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['en']]);

    $action = finCodexRemovalMountSave($page);

    expect($action)->not->toBeNull()
        ->and($action?->shouldOpenModal())->toBeTrue()
        ->and($action?->isConfirmationRequired())->toBeTrue()
        ->and((string) $action?->getModalHeading())->toBe((string) __('fin-codex::fin-codex.settings.removal.heading'));

    $description = (string) $action?->getModalDescription();

    expect($description)
        ->toContain((string) __('fin-codex::fin-codex.settings.removal.intro'))
        // The language and what it holds, so the cost is on screen before the
        // press, not after it.
        ->toContain((string) __('fin-codex::fin-codex.settings.removal.row', ['locale' => 'de', 'count' => 2]));

    // And the checkbox that decides what happens to the texts, on by default.
    $page->assertSchemaStateSet(['keep_translations' => true], 'mountedActionSchema0');
});

it('names every language going away, each with its own count', function (): void {
    finCodexRemovalSeed(['en', 'de', 'hu'], 'en');
    finCodexRemovalTranslations('de', 2);
    finCodexRemovalTranslations('hu', 5);

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['en']]);

    $description = (string) finCodexRemovalMountSave($page)?->getModalDescription();

    expect($description)
        ->toContain((string) __('fin-codex::fin-codex.settings.removal.row', ['locale' => 'de', 'count' => 2]))
        ->toContain((string) __('fin-codex::fin-codex.settings.removal.row', ['locale' => 'hu', 'count' => 5]));
});

it('does not ask when nothing is being removed', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);
    $rows['de']['display'] = 'Deutsch (Österreich)';

    $page->set('data.languages', array_values($rows));

    // shouldOpenModal() is the question, not isConfirmationRequired(): Filament
    // opens a modal for any action with a custom heading, so the heading alone
    // once opened an empty box on every save.
    expect(finCodexRemovalSaveAction($page)->shouldOpenModal())->toBeFalse()
        ->and($page->instance()->removedLanguages())->toBe([]);

    // Mounting the button runs the save straight through: nothing to confirm.
    expect(finCodexRemovalMountSave($page))->toBeNull()
        ->and(finCodexRemovalStored()->languages[1]['display'])->toBe('Deutsch (Österreich)');
});

it('saves an untouched form without a modal', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');

    $page = finCodexRemovalPage();

    expect(finCodexRemovalSaveAction($page)->shouldOpenModal())->toBeFalse()
        ->and(finCodexRemovalMountSave($page))->toBeNull()
        ->and(finCodexRemovalStoredCodes())->toBe(['en', 'de']);
});

it('writes the shortened list when the confirmation is accepted', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');
    finCodexRemovalTranslations('de', 2);

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['en']]);

    finCodexRemovalMountSave($page);

    $page->setActionData(['keep_translations' => true])->callMountedAction();

    expect(finCodexRemovalStoredCodes())->toBe(['en']);
});

it('writes nothing when the confirmation is left standing', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['en']]);

    // Mounted, never called: the admin read the modal and walked away.
    expect(finCodexRemovalMountSave($page)?->isConfirmationRequired())->toBeTrue()
        ->and(finCodexRemovalStoredCodes())->toBe(['en', 'de']);
});

/*
 * The locked decision, and the row that gets to be explicit about it.
 */

it('deletes every translation and revision in a removed language when the checkbox is unticked, and nothing else', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');
    $english = finCodexRemovalTranslations('en', 1);
    $german = finCodexRemovalTranslations('de', 3);
    ArticleRevision::factory()->create(['article_id' => $english[0]->article_id, 'locale' => 'en']);
    ArticleRevision::factory()->create(['article_id' => $german[0]->article_id, 'locale' => 'de']);

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['en']]);
    finCodexRemovalMountSave($page);
    $page->setActionData(['keep_translations' => false])->callMountedAction()->assertNotified();

    expect(finCodexRemovalStoredCodes())->toBe(['en'])
        ->and(ArticleTranslation::query()->where('locale', 'de')->count())->toBe(0)
        ->and(ArticleRevision::query()->where('locale', 'de')->count())->toBe(0)
        ->and(ArticleTranslation::query()->where('locale', 'en')->count())->toBe(1)
        ->and(ArticleRevision::query()->where('locale', 'en')->count())->toBe(1);
});

it('deletes nothing when the checkbox stays ticked, and gives the texts back when the language returns', function (): void {
    finCodexRemovalSeed(['en', 'de'], 'en');
    finCodexRemovalTranslations('en', 1);
    $german = finCodexRemovalTranslations('de', 3);

    $page = finCodexRemovalPage();
    $rows = finCodexRemovalRows($page);

    $page->set('data.languages', [$rows['en']]);
    finCodexRemovalMountSave($page);
    $page->setActionData(['keep_translations' => true])->callMountedAction();

    expect(finCodexRemovalStoredCodes())->toBe(['en'])
        // Not one row went away, and every one of them still reads exactly as
        // it did: a settings save never touches article content.
        ->and(ArticleTranslation::query()->where('locale', 'de')->count())->toBe(3);

    foreach ($german as $translation) {
        $fresh = ArticleTranslation::query()->findOrFail($translation->id);

        expect($fresh->title)->toBe($translation->title)
            ->and($fresh->body)->toBe($translation->body);
    }

    // Adding the language back brings the tab and its texts straight back.
    finCodexRemovalSeed(['en', 'de'], 'en');
    forgetHelpMemo();

    expect(array_column(TranslationTabs::languages()['languages'], 'code'))->toBe(['en', 'de'])
        ->and(ArticleTranslation::query()->where('locale', 'de')->count())->toBe(3);
});

it('prunes no revision when the keep count is lowered', function (): void {
    finCodexRemovalSeed(['en'], 'en');

    $article = Article::factory()->create(['slug' => 'keeping-history']);

    ArticleRevision::factory()->count(5)->create([
        'article_id' => $article->id,
        'locale' => 'en',
    ]);

    $page = finCodexRemovalPage();

    expect($page->get('data.revisions_keep'))->toEqual(10);

    $page->set('data.revisions_keep', '2')
        ->call('save')
        ->assertHasNoErrors();

    // Pruning lives in the core's snapshot path (RevisionManager::prune(),
    // called per article and per locale after a revision is recorded), never
    // in a settings save. Lowering the ceiling changes what happens from here
    // on; it deletes nothing that is already stored.
    expect(finCodexRemovalStored()->revisions_keep)->toBe(2)
        ->and(ArticleRevision::query()->where('article_id', $article->id)->count())->toBe(5);
});
