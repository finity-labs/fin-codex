<?php

use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Livewire\Livewire;

/*
 * EDIT-01 through the page the admin actually opens: the Articles tab reads
 * like a manual's table of contents, with the parent path muted in front of
 * the title, where each article comes from, its publishing state and one flag
 * per configured language in one of three states.
 *
 * usesPanel() comes first in every row (the table is built by the panel's
 * resource) and the seed travels the clock once, so exactly one article has an
 * outdated German translation and second-precision timestamps cannot make the
 * verdict a coin toss (Pitfall 6).
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
 *               a minute later so German is outdated
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

it('flags each language present, missing or outdated with a tooltip', function (): void {
    $user = finCodexListUser();
    finCodexListSeed();
    $this->usesPanel('admin', $user);

    $html = Livewire::test(ListArticles::class)->html();

    $billing = finCodexListRow($html, 'billing');
    $users = finCodexListRow($html, 'users');
    $zebra = finCodexListRow($html, 'zebra');

    expect(finCodexListFlagState($billing, 'en'))->toBe('present')
        ->and(finCodexListFlagState($billing, 'de'))->toBe('present')
        ->and(finCodexListFlagState($users, 'de'))->toBe('outdated')
        ->and(finCodexListFlagState($zebra, 'de'))->toBe('missing');

    expect(finCodexListFlagMarkup($billing, 'de'))->toContain('🇩🇪')
        ->and(finCodexListFlagMarkup($zebra, 'de'))->toContain('grayscale')
        ->and(finCodexListFlagMarkup($users, 'de'))->toContain('warning')
        ->and(finCodexListFlagMarkup($users, 'de'))->toContain(__('fin-codex::fin-codex.editor.state.outdated'));

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

it('filters by missing and outdated locale', function (): void {
    $user = finCodexListUser();
    $articles = finCodexListSeed();
    $this->usesPanel('admin', $user);

    Livewire::test(ListArticles::class)
        ->filterTable('missing', 'de')
        ->assertCanSeeTableRecords([$articles['users/roles'], $articles['zebra']])
        ->assertCanNotSeeTableRecords([$articles['billing'], $articles['users']]);

    Livewire::test(ListArticles::class)
        ->filterTable('outdated', 'de')
        ->assertCanSeeTableRecords([$articles['users']])
        ->assertCanNotSeeTableRecords([$articles['billing'], $articles['users/roles'], $articles['zebra']]);

    // English is the default language, so nothing can be outdated against it.
    Livewire::test(ListArticles::class)
        ->filterTable('outdated', 'en')
        ->assertCanNotSeeTableRecords([
            $articles['billing'],
            $articles['users'],
            $articles['users/roles'],
            $articles['zebra'],
        ]);
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
    $outdated = __('fin-codex::fin-codex.editor.state.outdated');

    expect($both)->toBe('Datei und Datenbank')
        ->and($outdated)->toBe('Veraltet');

    $html = Livewire::test(ListArticles::class)->html();

    expect($html)->toContain($both)
        ->and(finCodexListFlagMarkup(finCodexListRow($html, 'users'), 'de'))->toContain($outdated);

    app()->setLocale('en');

    expect(__('fin-codex::fin-codex.editor.source.both'))->not->toBe($both)
        ->and(__('fin-codex::fin-codex.editor.state.outdated'))->not->toBe($outdated);
});
