<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Editor\HtmlToMarkdown;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Livewire\HelpDrawer;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Livewire\Livewire;

/*
 * EDIT-09 through the edit page: an HTML article is read-only until it is
 * converted.
 *
 * The body of an HTML article is the one field the editor refuses to touch:
 * a Markdown editor would mangle it and a rich editor would smuggle its own
 * markup back in. It is shown as source in a disabled textarea, the title
 * and the excerpt stay editable, and the single way out is the convert
 * action, which goes through ArticleWriter like every other write.
 */

const FIN_CODEX_HTML_EN = '<h2>Hi</h2><p>Some <strong>bold</strong> text.</p>';

const FIN_CODEX_HTML_DE = '<h2>Hallo</h2>';

function finCodexConvertUser(string $name = 'Converter'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * @param  list<string>  $codes
 */
function finCodexConvertUseLanguages(array $codes, string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/** A published, public HTML article with an English and a German body. */
function finCodexConvertSeed(string $slug = 'legacy'): Article
{
    return Article::factory()->html()->public()->published()
        ->withTranslation('en', ['title' => 'Hi', 'body' => FIN_CODEX_HTML_EN])
        ->withTranslation('de', ['title' => 'Hallo', 'body' => FIN_CODEX_HTML_DE])
        ->create(['slug' => $slug])
        ->fresh();
}

/** The stored bodies keyed by locale. */
function finCodexConvertBodies(Article $article): array
{
    return ArticleTranslation::query()
        ->where('article_id', $article->id)
        ->orderBy('locale')
        ->pluck('body', 'locale')
        ->all();
}

/**
 * The opening <textarea> tag of one locale's HTML body, found through the
 * marker the read-only field carries: Filament's own field markup has no
 * stable handle on a component, and a regex over it would break on the next
 * minor.
 */
function finCodexHtmlBodyTag(string $html, string $code): string
{
    expect($html)->toContain('data-fin-codex-html-body="'.$code.'"');

    $wrapper = substr($html, (int) strpos($html, 'data-fin-codex-html-body="'.$code.'"'));
    $start = strpos($wrapper, '<textarea');

    expect($start)->not->toBeFalse();

    $end = (int) strpos($wrapper, '>', (int) $start);

    return substr($wrapper, (int) $start, $end - (int) $start + 1);
}

it('shows the HTML body read-only and keeps title and excerpt editable', function (): void {
    enableRevisions(true);
    finCodexConvertUseLanguages(['en', 'de']);
    $user = finCodexConvertUser();
    $this->usesPanel('admin', $user);

    $article = finCodexConvertSeed();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);
    $html = $component->html();

    expect($html)
        ->toContain(__('fin-codex::fin-codex.editor.html_readonly'))
        ->not->toContain('fi-fo-markdown-editor')
        ->and(finCodexHtmlBodyTag($html, 'en'))
        ->toContain('disabled')
        ->toContain('translations.en.body');

    // Only the active tab's panel is rendered server-side, so the German
    // body has to be asked for by switching to it.
    expect(finCodexHtmlBodyTag($component->set('activeLocale', 'de')->html(), 'de'))->toContain('disabled');

    $component
        ->set('activeLocale', 'en')
        ->fillForm(['translations' => ['en' => ['title' => 'Hi v2', 'excerpt' => 'new']]])
        ->call('save')
        ->assertHasNoFormErrors();

    $english = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->firstOrFail();
    $revisions = ArticleRevision::query()->where('article_id', $article->id)->get();

    expect($english->body)->toBe(FIN_CODEX_HTML_EN)
        ->and($english->title)->toBe('Hi v2')
        ->and($english->excerpt)->toBe('new')
        ->and($article->fresh()->format)->toBe(ArticleFormat::Html)
        ->and(finCodexConvertBodies($article))->toBe(['de' => FIN_CODEX_HTML_DE, 'en' => FIN_CODEX_HTML_EN])
        ->and($revisions)->toHaveCount(1)
        ->and($revisions[0]->title)->toBe('Hi')
        ->and($revisions[0]->user_id)->toBe($user->id);
});

it('offers convert only on HTML articles', function (): void {
    $this->usesPanel('admin', finCodexConvertUser());

    $html = finCodexConvertSeed();
    $markdown = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'How users work.'])
        ->create(['slug' => 'users']);

    Livewire::test(EditArticle::class, ['record' => $html->getRouteKey()])
        ->assertActionVisible('convert');

    Livewire::test(EditArticle::class, ['record' => $markdown->getRouteKey()])
        ->assertActionHidden('convert');
});

it('converts every translation in one transaction with one Html revision each and leaves the article editable as Markdown', function (): void {
    enableRevisions(true);
    finCodexConvertUseLanguages(['en', 'de']);
    $user = finCodexConvertUser();
    $this->usesPanel('admin', $user);

    $article = finCodexConvertSeed();

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->callAction('convert')
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertRedirect();

    $bodies = finCodexConvertBodies($article);
    $revisions = ArticleRevision::query()->where('article_id', $article->id)->orderBy('locale')->get();

    expect($article->fresh()->format)->toBe(ArticleFormat::Markdown)
        ->and($bodies['en'])->toContain('## Hi')
        ->and($bodies['en'])->toContain('**bold**')
        ->and($bodies['de'])->toContain('## Hallo')
        ->and($revisions)->toHaveCount(2)
        ->and($revisions->pluck('locale')->all())->toBe(['de', 'en'])
        ->and($revisions->pluck('format')->unique()->all())->toBe([ArticleFormat::Html])
        ->and($revisions->pluck('user_id')->unique()->all())->toBe([$user->id]);

    $html = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->html();

    expect($html)
        ->toContain('fi-fo-markdown-editor')
        ->not->toContain('data-fin-codex-html-body');

    forgetHelpMemo();

    Livewire::test(HelpDrawer::class, ['pageClass' => Dashboard::class, 'panelId' => 'admin', 'guard' => 'web'])
        ->call('open', 'legacy')
        ->assertSeeHtml('<h2')
        ->assertDontSee('## Hi');
});

it('requires confirmation', function (): void {
    $this->usesPanel('admin', finCodexConvertUser());

    $article = finCodexConvertSeed();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->mountAction('convert');

    $action = $component->instance()->getMountedAction();

    expect($action?->isConfirmationRequired())->toBeTrue()
        ->and($action?->getModalHeading())->toBe(__('fin-codex::fin-codex.editor.convert.heading'))
        ->and($action?->getModalDescription())->toBe(__('fin-codex::fin-codex.editor.convert.description'));

    expect($article->fresh()->format)->toBe(ArticleFormat::Html);
});

it('leaves everything untouched when the conversion fails', function (): void {
    enableRevisions(true);
    finCodexConvertUseLanguages(['en', 'de']);
    $user = finCodexConvertUser();
    $this->usesPanel('admin', $user);

    $article = finCodexConvertSeed();

    app()->instance(HtmlToMarkdown::class, new class extends HtmlToMarkdown
    {
        private int $calls = 0;

        public function convert(string $html): string
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('boom');
            }

            return '# ok';
        }
    });

    expect(fn () => Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->callAction('convert'))
        ->toThrow(RuntimeException::class, 'boom');

    expect($article->fresh()->format)->toBe(ArticleFormat::Html)
        ->and(finCodexConvertBodies($article))->toBe(['de' => FIN_CODEX_HTML_DE, 'en' => FIN_CODEX_HTML_EN])
        ->and(ArticleRevision::query()->count())->toBe(0);
});
