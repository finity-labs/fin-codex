<?php

use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Support\Js;
use Livewire\Livewire;

/*
 * EDIT-01 through the page the admin actually opens: the Articles tab reads
 * like a manual's table of contents, with the parent path muted in front of
 * the title, where each article comes from, its publishing state and one flag
 * per configured language, translated or missing.
 *
 * usesPanel() comes first in every row (the table is built by the panel's
 * resource) and the seed travels the clock once, so exactly one article has a
 * German translation saved before its English one — which the list must show
 * as translated all the same: there is no outdated state on the page.
 */

/** A fixture user signed in on the admin guard. */
function finCodexListUser(): User
{
    return User::create(['name' => 'Lister', 'email' => 'lister@example.com']);
}

/**
 * @param  list<string>  $codes
 */
function finCodexListUseLanguages(array $codes, string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

function finCodexListTranslate(Article $article, string $locale, string $title): ArticleTranslation
{
    return ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => $locale,
        'title' => $title,
        'body' => $title.' body',
    ]);
}

/**
 * Four articles under two languages, keyed by slug:
 *
 * - billing     public, published, Markdown, en + de, both current
 * - users       authenticated, published, Markdown, en + de, English edited
 *               a minute later — still shown as translated, never outdated
 * - users/roles public, unpublished, HTML, en only
 * - zebra       public, published, Markdown, en only
 *
 * The fixture docs tree (useFixtureDocs()) knows "users" and "users/roles",
 * so those two are the "file and database" rows and the other two are
 * database-only.
 *
 * @return array<string, Article>
 */
function finCodexListSeed(): array
{
    finCodexListUseLanguages(['en', 'de']);

    $billing = Article::factory()->public()->published()->markdown()->create(['slug' => 'billing']);
    finCodexListTranslate($billing, 'en', 'Billing');
    finCodexListTranslate($billing, 'de', 'Abrechnung');

    $users = Article::factory()->authenticated()->published()->markdown()->create(['slug' => 'users']);
    finCodexListTranslate($users, 'en', 'Users');
    finCodexListTranslate($users, 'de', 'Benutzer');

    $roles = Article::factory()->public()->unpublished()->html()->create(['slug' => 'users/roles']);
    finCodexListTranslate($roles, 'en', 'Roles');

    $zebra = Article::factory()->public()->published()->markdown()->create(['slug' => 'zebra']);
    finCodexListTranslate($zebra, 'en', 'Zebra');

    test()->travelTo(now()->addMinute());

    // The English body of "users" is revised later; the title stays "Users"
    // so the slug column still reads the way the first row asserts.
    ArticleTranslation::query()
        ->where('article_id', $users->id)
        ->where('locale', 'en')
        ->sole()
        ->update(['body' => 'How users work, revised.']);

    return ['billing' => $billing, 'users' => $users, 'users/roles' => $roles, 'zebra' => $zebra];
}

/**
 * The markup of one row, cut from its slug marker to the next one. The
 * closing quote keeps "users" from matching the "users/roles" row.
 */
function finCodexListRow(string $html, string $slug): string
{
    $start = strpos($html, 'data-fin-codex-slug="'.$slug.'"');

    expect($start)->not->toBeFalse("No row markup for [{$slug}].");

    $rest = substr($html, (int) $start);
    $next = strpos($rest, 'data-fin-codex-slug="', 1);

    return $next === false ? $rest : substr($rest, 0, $next);
}

function finCodexListFlagState(string $rowHtml, string $locale): ?string
{
    $matched = preg_match(
        '/data-fin-codex-locale="'.$locale.'"[^>]*data-fin-codex-state="([a-z]+)"/',
        $rowHtml,
        $matches,
    );

    return $matched === 1 ? $matches[1] : null;
}

function finCodexListFlagMarkup(string $rowHtml, string $locale): string
{
    preg_match('/<span[^>]*data-fin-codex-locale="'.$locale.'".*?<\/span>/s', $rowHtml, $matches);

    return $matches[0] ?? '';
}

it('lists articles sorted by slug path with the parent prefix and the title', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    $this->usesPanel('admin', $user);

    $html = Livewire::test(ListArticles::class)
        ->assertCanSeeTableRecords([
            $articles['billing'],
            $articles['users'],
            $articles['users/roles'],
            $articles['zebra'],
        ], inOrder: true)
        ->html();

    $roles = finCodexListRow($html, 'users/roles');
    $users = finCodexListRow($html, 'users');

    expect($roles)->toMatch('/data-fin-codex-slug-parent[^>]*>users\/</')
        ->and($roles)->toMatch('/data-fin-codex-slug-title[^>]*>Roles</')
        ->and($users)->not->toContain('data-fin-codex-slug-parent')
        ->and($users)->toMatch('/data-fin-codex-slug-title[^>]*>Users</');
});

it('shows source, published, visibility and format', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    useFixtureDocs();
    $this->usesPanel('admin', $user);

    Livewire::test(ListArticles::class)
        ->assertTableColumnExists('is_published')
        ->assertTableColumnStateSet('is_published', true, $articles['billing'])
        ->assertTableColumnStateSet('is_published', false, $articles['users/roles'])
        ->assertTableColumnFormattedStateSet('source', __('fin-codex::fin-codex.editor.source.both'), $articles['users/roles'])
        ->assertTableColumnFormattedStateSet('source', __('fin-codex::fin-codex.editor.source.both'), $articles['users'])
        ->assertTableColumnFormattedStateSet('source', __('fin-codex::fin-codex.editor.source.database'), $articles['billing'])
        ->assertTableColumnFormattedStateSet('visibility', Visibility::Public->label(), $articles['billing'])
        ->assertTableColumnFormattedStateSet('visibility', Visibility::Authenticated->label(), $articles['users'])
        ->assertTableColumnFormattedStateSet('format', ArticleFormat::Markdown->label(), $articles['billing'])
        ->assertTableColumnFormattedStateSet('format', ArticleFormat::Html->label(), $articles['users/roles']);
});

it('flags each language translated or missing with a tooltip, never outdated', function (): void {
    $user = finCodexListUser();
    finCodexListSeed();
    $this->usesPanel('admin', $user);

    $html = Livewire::test(ListArticles::class)->html();

    $billing = finCodexListRow($html, 'billing');
    $users = finCodexListRow($html, 'users');
    $zebra = finCodexListRow($html, 'zebra');

    // German on "users" was saved before English: the verdict service calls
    // that outdated, the list paints it translated like any other.
    expect(finCodexListFlagState($billing, 'en'))->toBe('present')
        ->and(finCodexListFlagState($billing, 'de'))->toBe('present')
        ->and(finCodexListFlagState($users, 'de'))->toBe('present')
        ->and(finCodexListFlagState($zebra, 'de'))->toBe('missing');

    expect(finCodexListFlagMarkup($billing, 'de'))->toContain('🇩🇪')
        ->and(finCodexListFlagMarkup($zebra, 'de'))->toContain('grayscale')
        ->and(finCodexListFlagMarkup($users, 'de'))->not->toContain('warning')
        ->and(finCodexListFlagMarkup($users, 'de'))->toContain(__('fin-codex::fin-codex.editor.state.present'));

    // The whole column is off below the md breakpoint: Filament's own table
    // CSS defines md:fi-visible as "hidden md:table-cell".
    expect($html)->toMatch('/<th[^>]*fi-ta-header-cell-languages[^>]*md:fi-visible/');
});

it('filters by published, visibility, format and source', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    useFixtureDocs();
    $this->usesPanel('admin', $user);

    Livewire::test(ListArticles::class)
        ->filterTable('is_published', true)
        ->assertCanSeeTableRecords([$articles['billing'], $articles['users'], $articles['zebra']])
        ->assertCanNotSeeTableRecords([$articles['users/roles']]);

    Livewire::test(ListArticles::class)
        ->filterTable('visibility', Visibility::Public->value)
        ->assertCanSeeTableRecords([$articles['billing'], $articles['users/roles'], $articles['zebra']])
        ->assertCanNotSeeTableRecords([$articles['users']]);

    Livewire::test(ListArticles::class)
        ->filterTable('format', ArticleFormat::Html->value)
        ->assertCanSeeTableRecords([$articles['users/roles']])
        ->assertCanNotSeeTableRecords([$articles['billing'], $articles['users'], $articles['zebra']]);

    Livewire::test(ListArticles::class)
        ->filterTable('source', 'both')
        ->assertCanSeeTableRecords([$articles['users'], $articles['users/roles']])
        ->assertCanNotSeeTableRecords([$articles['billing'], $articles['zebra']]);

    Livewire::test(ListArticles::class)
        ->filterTable('source', 'database')
        ->assertCanSeeTableRecords([$articles['billing'], $articles['zebra']])
        ->assertCanNotSeeTableRecords([$articles['users'], $articles['users/roles']]);
});

it('filters by missing locale, and offers no outdated filter', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    $this->usesPanel('admin', $user);

    $component = Livewire::test(ListArticles::class)
        ->filterTable('missing', 'de')
        ->assertCanSeeTableRecords([$articles['users/roles'], $articles['zebra']])
        ->assertCanNotSeeTableRecords([$articles['billing'], $articles['users']]);

    expect($component->instance()->getTable()->getFilter('outdated'))->toBeNull();
});

it('searches by slug and by title', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    $this->usesPanel('admin', $user);

    Livewire::test(ListArticles::class)
        ->searchTable('roles')
        ->assertCanSeeTableRecords([$articles['users/roles']])
        ->assertCanNotSeeTableRecords([$articles['billing'], $articles['users'], $articles['zebra']]);

    Livewire::test(ListArticles::class)
        ->searchTable('Abrechnung')
        ->assertCanSeeTableRecords([$articles['billing']])
        ->assertCanNotSeeTableRecords([$articles['users'], $articles['users/roles'], $articles['zebra']]);
});

it('offers edit but no delete on the table', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    $this->usesPanel('admin', $user);

    Livewire::test(ListArticles::class)
        ->assertTableActionExists('edit', record: $articles['billing'])
        ->assertTableActionDoesNotExist('delete', record: $articles['billing'])
        ->assertTableBulkActionDoesNotExist('delete');
});

it('reads under the panel locale', function (): void {
    $user = finCodexListUser();
    finCodexListSeed();
    useFixtureDocs();
    $this->usesPanel('admin', $user);

    app()->setLocale('de');

    $both = __('fin-codex::fin-codex.editor.source.both');
    $present = __('fin-codex::fin-codex.editor.state.present');

    expect($both)->toBe('Datei und Datenbank')
        ->and($present)->not->toBe('Translated');

    $html = Livewire::test(ListArticles::class)->html();

    // The flag's tooltip goes through @js, which escapes the umlaut, so the
    // markup carries the word the way Js::from() writes it.
    expect($html)->toContain($both)
        ->and(finCodexListFlagMarkup(finCodexListRow($html, 'users'), 'de'))->toContain(trim((string) Js::from($present), "'"));

    app()->setLocale('en');

    expect(__('fin-codex::fin-codex.editor.source.both'))->not->toBe($both)
        ->and(__('fin-codex::fin-codex.editor.state.present'))->toBe('Translated');
});

it('shows the panels an article\'s pages target, and filters by panel', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    $articles['billing']->contexts()->create(['panel_id' => 'admin', 'type' => ContextType::PageClass, 'key' => 'App\\Billing', 'sort_order' => 0]);
    $articles['billing']->contexts()->create(['panel_id' => null, 'type' => ContextType::Url, 'key' => '/billing/*', 'sort_order' => 1]);
    $articles['users']->contexts()->create(['panel_id' => 'staff', 'type' => ContextType::Route, 'key' => 'filament.staff.pages.dashboard', 'sort_order' => 0]);
    $this->usesPanel('admin', $user);

    $html = Livewire::test(ListArticles::class)->html();

    expect(finCodexListRow($html, 'billing'))->toContain(__('fin-codex::fin-codex.editor.contexts.any_panel'))
        ->toContain('admin')
        ->and(finCodexListRow($html, 'users'))->toContain('staff')
        ->and(finCodexListRow($html, 'zebra'))->not->toContain(__('fin-codex::fin-codex.editor.contexts.any_panel'));

    Livewire::test(ListArticles::class)
        ->filterTable('panel', 'admin')
        ->assertCanSeeTableRecords([$articles['billing']])
        ->assertCanNotSeeTableRecords([$articles['users'], $articles['zebra'], $articles['users/roles']]);

    Livewire::test(ListArticles::class)
        ->filterTable('panel', ContextPicker::ANY_PANEL)
        ->assertCanSeeTableRecords([$articles['billing']])
        ->assertCanNotSeeTableRecords([$articles['users'], $articles['zebra']]);

    Livewire::test(ListArticles::class)
        ->filterTable('panel', 'staff')
        ->assertCanSeeTableRecords([$articles['users']])
        ->assertCanNotSeeTableRecords([$articles['billing'], $articles['zebra']]);
});

it('shows titles in the panel language, falling back to the default language', function (): void {
    $user = finCodexListUser();
    finCodexListSeed();
    $this->usesPanel('admin', $user);

    app()->setLocale('de');

    $html = Livewire::test(ListArticles::class)->html();

    // billing and users have German titles; users/roles and zebra are English only.
    expect(finCodexListRow($html, 'billing'))->toContain('>Abrechnung<')
        ->and(finCodexListRow($html, 'users'))->toContain('>Benutzer<')
        ->and(finCodexListRow($html, 'users/roles'))->toContain('>Roles<')
        ->and(finCodexListRow($html, 'zebra'))->toContain('>Zebra<');

    app()->setLocale('en');

    $html = Livewire::test(ListArticles::class)->html();

    expect(finCodexListRow($html, 'billing'))->toContain('>Billing<')
        ->and(finCodexListRow($html, 'users'))->toContain('>Users<');
});
