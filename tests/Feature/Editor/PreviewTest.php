<?php

use Filament\Actions\Action;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * EDIT-08: the preview slide-over.
 *
 * Every row reads the modal content off the mounted action rather than off
 * the page markup — Filament renders a modal's body only once it is open, so
 * the rendered component never carries it. The point of each row is that the
 * html came out of lin-codex's ArticleRenderer: callouts, steps and figures
 * are core markup no Markdown parser produces on its own, and the raw body
 * never appears.
 */

function finCodexPreviewUser(string $name = 'Previewer'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * @param  list<string>  $codes
 */
function finCodexPreviewUseLanguages(array $codes, string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/** A body using the three lin-codex syntaxes the panel's own Markdown editor knows nothing about. */
function finCodexPreviewBody(): string
{
    return "> [!WARNING] Before you delete\n> Their articles stay published.\n\n"
        .":::steps\n1. Open the users page\n\n2. Click **Add**\n:::\n\n"
        .'![Users list](/storage/codex/users.png "The users page")';
}

/** Mount the preview action and hand back the mounted instance. */
function finCodexPreviewAction(Testable $component): Action
{
    $component->mountAction('preview');

    $action = $component->instance()->getMountedAction();

    expect($action)->toBeInstanceOf(Action::class);

    return $action;
}

/** The rendered modal body of the preview action. */
function finCodexPreviewHtml(Testable $component): string
{
    $content = finCodexPreviewAction($component)->getModalContent();

    expect($content)->not->toBeNull();

    return $content->toHtml();
}

it('renders the unsaved body of the active tab through the core renderer on the create page', function (): void {
    $this->usesPanel('admin', finCodexPreviewUser());

    $component = Livewire::test(CreateArticle::class)
        ->fillForm(['translations' => ['en' => ['body' => finCodexPreviewBody()]]]);

    $html = finCodexPreviewHtml($component);

    expect($html)
        ->toContain('data-fin-codex-preview')
        ->toContain('codex-root')
        ->toContain('codex-callout codex-callout--warning')
        ->toContain('Before you delete')
        ->toContain('<ol class="codex-steps">')
        ->toContain('codex-step__number')
        ->toContain('<figure class="codex-figure">')
        ->toContain('<figcaption>The users page</figcaption>')
        ->not->toContain('[!WARNING]')
        ->not->toContain(':::steps');
});

it('previews the active language tab', function (): void {
    finCodexPreviewUseLanguages(['en', 'de']);
    $this->usesPanel('admin', finCodexPreviewUser());

    $article = Article::factory()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'How users work.'])
        ->withTranslation('de', ['title' => 'Benutzer', 'body' => 'Wie Benutzer funktionieren.'])
        ->create(['slug' => 'users']);

    $english = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);

    expect(finCodexPreviewHtml($english))
        ->toContain('How users work.')
        ->not->toContain('Wie Benutzer funktionieren.')
        ->and($english->instance()->getMountedAction()->getModalHeading())
        ->toBe(__('fin-codex::fin-codex.editor.preview.heading', ['locale' => 'EN']));

    $german = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->set('activeLocale', 'de');

    expect(finCodexPreviewHtml($german))
        ->toContain('Wie Benutzer funktionieren.')
        ->not->toContain('How users work.')
        ->and($german->instance()->getMountedAction()->getModalHeading())
        ->toBe(__('fin-codex::fin-codex.editor.preview.heading', ['locale' => 'DE']));
});

it('renders an HTML article through the sanitizer, not as Markdown', function (): void {
    $this->usesPanel('admin', finCodexPreviewUser());

    $article = Article::factory()->html()
        ->withTranslation('en', ['title' => 'Legacy', 'body' => '<h2>Hi</h2><script>alert(1)</script><p>x</p>'])
        ->create(['slug' => 'legacy']);

    $html = finCodexPreviewHtml(Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]));

    expect($html)
        ->toContain('<h2')
        ->toContain('Hi')
        ->not->toContain('<script')
        ->not->toContain('alert(1)');
});

it('binds the theme wrapper like the drawer', function (): void {
    $this->usesPanel('admin', finCodexPreviewUser());

    expect(finCodexPreviewHtml(Livewire::test(CreateArticle::class)))
        ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"')
        ->not->toContain('class="light"');

    $this->usesPanel('portal', finCodexPreviewUser('Portal'));

    expect(finCodexPreviewHtml(Livewire::test(CreateArticle::class)))
        ->toContain('class="light"')
        ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"');
});

it('never echoes a raw body', function (): void {
    $this->usesPanel('admin', finCodexPreviewUser());

    $component = Livewire::test(CreateArticle::class)
        ->fillForm(['translations' => ['en' => ['body' => "Safe copy stays.\n\n<img src=x onerror=alert(1)>"]]]);

    expect(finCodexPreviewHtml($component))
        ->toContain('Safe copy stays.')
        ->not->toContain('onerror')
        ->not->toContain('<img');
});
