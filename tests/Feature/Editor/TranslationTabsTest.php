<?php

use Filament\Actions\Testing\TestAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Livewire\Livewire;

/*
 * EDIT-05 through the pages: what the admin sees on the language tabs.
 *
 * The badges are read off the rendered markup rather than off the schema,
 * because a badge closure that never runs would still return the right
 * string when called by hand. Every row drives CreateArticle or EditArticle,
 * so the missing verdict comes from live form state, exactly as on the page.
 * There is no outdated badge: a default language saved after a translation
 * leaves that translation's tab unbadged.
 */

/** A fixture user signed in on the panel under test. */
function finCodexTabsUser(string $name = 'Tabs'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * @param  list<string>  $codes
 */
function finCodexTabsUseLanguages(array $codes, string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/**
 * The rendered tab buttons, keyed by locale in document order.
 *
 * With a livewireProperty() Tabs, Filament renders one server-side
 * <button wire:click="$set('activeLocale', 'de')" class="fi-tabs-item"> per
 * tab and no client-side tab list, so the locale marker the tabs carry is
 * the only stable handle on a tab button. The marker rides on the tab's
 * extra attributes, which Filament puts on the nav button *and* on the
 * active tab's panel <div>, so the match is anchored to <button.
 *
 * @return array<string, string>
 */
function finCodexTabsButtons(string $html): array
{
    preg_match_all('/<button[^>]*data-fin-codex-locale="([^"]+)"(.*?)<\/button>/s', $html, $buttons, PREG_SET_ORDER);

    $found = [];

    foreach ($buttons as $button) {
        $found[$button[1]] = $button[2];
    }

    return $found;
}

/** The badge text on one tab, or null when the tab carries no badge. */
function finCodexTabsBadge(string $html, string $code): ?string
{
    $buttons = finCodexTabsButtons($html);

    expect($buttons)->toHaveKey($code);

    if (preg_match('/class="fi-badge-label"[^>]*>\s*(.*?)\s*<\/span>/s', $buttons[$code], $badge) !== 1) {
        return null;
    }

    return html_entity_decode(trim($badge[1]));
}

/** The class attribute of one tab's badge, or '' when it carries no badge. */
function finCodexTabsBadgeClasses(string $html, string $code): string
{
    $buttons = finCodexTabsButtons($html);

    expect($buttons)->toHaveKey($code);

    if (preg_match('/<span\s+class="(fi-badge[^"]*)"/s', $buttons[$code], $badge) !== 1) {
        return '';
    }

    return $badge[1];
}

/** The tab labels, keyed by locale in the order the tabs render. */
function finCodexTabsLabels(string $html): array
{
    $labels = [];

    foreach (finCodexTabsButtons($html) as $code => $button) {
        preg_match('/class="fi-tabs-item-label"[^>]*>\s*(.*?)\s*<\/span>/s', $button, $label);

        $labels[$code] = html_entity_decode(trim($label[1] ?? ''));
    }

    return $labels;
}

it('badges a non-default tab missing until title and body are filled, never the default', function (): void {
    finCodexTabsUseLanguages(['en', 'de']);
    $this->usesPanel('admin', finCodexTabsUser());

    $component = Livewire::test(CreateArticle::class);

    $html = $component->html();

    expect(finCodexTabsBadge($html, 'de'))->toBe(__('fin-codex::fin-codex.editor.state.missing'))
        ->and(finCodexTabsBadge($html, 'en'))->toBeNull();

    $html = $component->fillForm(['translations' => ['de' => ['title' => 'Benutzer']]])->html();

    expect(finCodexTabsBadge($html, 'de'))->toBe(__('fin-codex::fin-codex.editor.state.missing'));

    $html = $component->fillForm(['translations' => ['de' => ['body' => 'Wie Benutzer funktionieren.']]])->html();

    expect(finCodexTabsBadge($html, 'de'))->toBeNull()
        ->and(finCodexTabsBadge($html, 'en'))->toBeNull();
});

it('shows no badge on a filled tab whose default was saved later', function (): void {
    finCodexTabsUseLanguages(['en', 'de']);
    $this->usesPanel('admin', finCodexTabsUser());

    $article = Article::factory()->create(['slug' => 'users']);
    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'de',
        'title' => 'Benutzer',
        'body' => 'Wie Benutzer funktionieren.',
    ]);

    $this->travelTo(now()->addMinute());

    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'Users',
        'body' => 'How users work.',
    ]);

    $html = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->html();

    expect(finCodexTabsBadge($html, 'de'))->toBeNull()
        ->and(finCodexTabsBadgeClasses($html, 'de'))->not->toContain('fi-color-warning')
        ->and(finCodexTabsBadge($html, 'en'))->toBeNull();
});

it('shows no badge on either tab when the default language is the older one', function (): void {
    finCodexTabsUseLanguages(['en', 'de'], 'de');
    $this->usesPanel('admin', finCodexTabsUser());

    $article = Article::factory()->create(['slug' => 'users']);
    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'de',
        'title' => 'Benutzer',
        'body' => 'Wie Benutzer funktionieren.',
    ]);

    $this->travelTo(now()->addMinute());

    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'Users',
        'body' => 'How users work.',
    ]);

    $html = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->html();

    expect(finCodexTabsBadge($html, 'de'))->toBeNull()
        ->and(finCodexTabsBadge($html, 'en'))->toBeNull();
});

it('copies title, excerpt and body from the default tab after confirmation and nothing else', function (): void {
    finCodexTabsUseLanguages(['en', 'de']);
    $this->usesPanel('admin', finCodexTabsUser());

    $article = Article::factory()->create(['slug' => 'users', 'keywords' => ['people']]);
    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'Users',
        'excerpt' => 'Manage users.',
        'body' => 'How users work.',
    ]);

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['translations' => ['en' => ['title' => 'Users (draft)']]])
        ->assertActionDoesNotExist(TestAction::make('copy_from_default')->schemaComponent('en'))
        ->mountAction(TestAction::make('copy_from_default')->schemaComponent('de'));

    $action = $component->instance()->getMountedAction();

    expect($action?->isConfirmationRequired())->toBeTrue()
        ->and($action?->getModalHeading())->toBe(__('fin-codex::fin-codex.editor.copy.heading'))
        ->and($action?->getModalDescription())->toBe(__('fin-codex::fin-codex.editor.copy.description'));

    $component
        ->unmountAction()
        ->callAction(TestAction::make('copy_from_default')->schemaComponent('de'))
        ->assertFormSet([
            'translations.de.title' => 'Users (draft)',
            'translations.de.excerpt' => 'Manage users.',
            'translations.de.body' => 'How users work.',
            'translations.en.title' => 'Users (draft)',
            'slug' => 'users',
            'keywords' => ['people'],
        ]);

    expect(ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'de')->count())->toBe(0);
});

it('labels tabs with the flag and display name in settings order', function (): void {
    finCodexTabsUseLanguages(['hu', 'en']);
    $this->usesPanel('admin', finCodexTabsUser());

    $labels = finCodexTabsLabels(Livewire::test(CreateArticle::class)->html());

    expect(array_keys($labels))->toBe(['hu', 'en'])
        ->and($labels['hu'])->toStartWith('🇭🇺')
        ->and($labels['hu'])->toContain(CodexSettings::languageEntry('hu')['display'])
        ->and($labels['en'])->toStartWith('🇬🇧')
        ->and(TranslationTabs::flag('gb'))->toBe('🇬🇧')
        ->and(TranslationTabs::flag('x'))->toBe('');
});
