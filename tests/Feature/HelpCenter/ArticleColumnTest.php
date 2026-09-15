<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Locale\LocaleResolver;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * CENTER-04 and CENTER-05: the middle column and the headings rail.
 *
 * The article reads the way the drawer reads — breadcrumbs, title, the
 * other-language notice, the body, related — but with real anchors instead of
 * Livewire actions, and with the table of contents moved to the right rail. The
 * three states the column can be in (not found, nothing written yet, the
 * landing) are checked in that order, and the not-found one answers 200 so the
 * rail and the search stay usable beside it.
 *
 * Helpers are file-local and finCodexHelpColumn*-prefixed. A sibling test
 * file's functions only exist when that file is loaded, so a single-file run
 * cannot see HelpCenterPageTest's finCodexHelpCenter* functions; anything two
 * files need lives in tests/Pest.php, which every run loads.
 */

/**
 * A fixture user signed in on the web guard, created once per test: several
 * rows mount the page more than once and a second User::create() with the same
 * address would trip the unique index rather than prove anything.
 */
function finCodexHelpColumnUser(string $email = 'column@example.com'): User
{
    $user = finCodexUser($email, 'Reader');

    test()->actingAs($user, 'web');

    return $user;
}

/**
 * The three-article account section: the parent, the article under test with
 * two headings and an image, and the one it relates to.
 */
function finCodexHelpColumnSeed(): void
{
    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Account', 'body' => 'Everything about your account.'])
        ->create(['slug' => 'account']);

    Article::factory()->public()->published()
        ->withRelated(['account/password'])
        ->withTranslation('en', ['title' => 'Signing in', 'body' => "## Passwords\n\nType your **password**.\n\n![A screenshot](https://example.com/shot.png)\n\n### Resetting\n\nUse the link."])
        ->create(['slug' => 'account/signing-in']);

    Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Passwords', 'body' => 'How passwords work.'])
        ->create(['slug' => 'account/password']);

    forgetHelpMemo();
}

/** The page mounted on the admin panel for one slug, or the landing for null. */
function finCodexHelpColumnPage(?string $slug = null, string $panel = 'admin'): Testable
{
    test()->usesPanel($panel, finCodexHelpColumnUser());
    forgetHelpMemo();

    return Livewire::test(HelpCenter::class, $slug === null ? [] : [HelpCenter::SLUG_PARAMETER => $slug]);
}

/**
 * The middle column of a rendered page, and nothing else.
 *
 * Every row below is about the article, and since 15-04 the rail renders the
 * whole tree — carrying the same data-fin-codex-help-node markers and the same
 * article titles — ahead of the column in the same page. Reading the whole page
 * HTML would let the rail answer a question asked about the column. The grid
 * order is rail, article, headings, so the column is what lies between its own
 * class and the headings column's.
 */
function finCodexHelpColumnHtml(string $html): string
{
    $start = strpos($html, 'fin-codex-help__article');

    if ($start === false) {
        test()->fail('The page rendered no article column.');
    }

    $end = strpos($html, 'fin-codex-help__toc', $start);

    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
}

/**
 * Register the languages the core may pick from, with English the default.
 *
 * @param  list<string>  $codes
 */
function finCodexHelpColumnLanguages(array $codes): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = 'en';
    $settings->save();
}

/*
 * -----------------------------------------------------------------------
 * The article.
 * -----------------------------------------------------------------------
 */

it('renders the breadcrumbs, the title, the body and the related section in that order', function (): void {
    finCodexHelpColumnSeed();

    $html = finCodexHelpColumnHtml(finCodexHelpColumnPage('account/signing-in')->html());

    $crumb = strpos($html, 'data-fin-codex-help-node="account"');
    $title = strpos($html, 'Signing in');
    $body = strpos($html, 'codex-article__body');
    $related = strpos($html, 'data-fin-codex-help-node="account/password"');

    expect($crumb)->toBeInt()
        ->and($title)->toBeInt()
        ->and($body)->toBeInt()
        ->and($related)->toBeInt()
        ->and($crumb)->toBeLessThan($title)
        ->and($title)->toBeLessThan($body)
        ->and($body)->toBeLessThan($related)
        ->and($html)->toContain(__('lin-codex::lin-codex.ui.related'))
        ->toContain('<strong>password</strong>');
});

it('wraps the rendered body in codex-root so every token resolves', function (): void {
    finCodexHelpColumnSeed();

    $html = finCodexHelpColumnPage('account/signing-in')->html();

    $root = strpos($html, 'class="codex-root"');
    $bodyDiv = strpos($html, 'class="codex-article__body"');

    // Both, in this order: a row that only looked for codex-article__body
    // would pass while the page rendered with no colours, borders or spacing
    // and dark mode did nothing.
    expect($root)->toBeInt()
        ->and($bodyDiv)->toBeInt()
        ->and($root)->toBeLessThan($bodyDiv)
        ->and($html)->toContain('lang="en"');
});

it('links every breadcrumb and related entry as a real anchor into the help center', function (): void {
    finCodexHelpColumnSeed();

    $page = finCodexHelpColumnPage('account/signing-in');
    $html = $page->html();

    expect($html)->toContain('href="'.HelpCenter::getUrl([HelpCenter::SLUG_PARAMETER => 'account']).'"')
        ->toContain('href="'.HelpCenter::getUrl([HelpCenter::SLUG_PARAMETER => 'account/password']).'"')
        ->not->toContain("mountAction('open-");
});

it('shows the other-language notice between the title and the body', function (): void {
    finCodexHelpColumnLanguages(['en', 'de']);
    finCodexHelpColumnSeed();
    app()->setLocale('de');

    $html = finCodexHelpColumnHtml(finCodexHelpColumnPage('account/signing-in')->html());

    $expected = app(LocaleResolver::class)->fallbackNotice('en');
    $title = strpos($html, 'Signing in');
    $notice = strpos($html, $expected);
    $body = strpos($html, 'codex-article__body');

    expect($notice)->toBeInt()
        ->and($title)->toBeInt()
        ->and($notice)->toBeGreaterThan($title)
        ->and($notice)->toBeLessThan($body);
});

it('renders the lightbox markup for an image without touching the drawer Alpine component', function (): void {
    finCodexHelpColumnSeed();

    $html = finCodexHelpColumnPage('account/signing-in')->html();

    expect($html)->toContain('data-codex-lightbox')
        ->toContain('codex-lightbox__image')
        ->toContain('codex-lightbox__close')
        ->toContain('lightbox: null')
        ->not->toContain('codexDrawer');
});

/*
 * -----------------------------------------------------------------------
 * The three states.
 * -----------------------------------------------------------------------
 */

it('shows the core not-found line with HTTP 200 for a slug the reader cannot have', function (): void {
    Article::factory()->unpublished()
        ->withTranslation('en', ['title' => 'Draft', 'body' => 'Not yet.'])
        ->create(['slug' => 'draft']);

    // Bound to the staff panel only, so the panel scope hides it from admin.
    Article::factory()->public()->published()
        ->withContext(ContextType::Route, 'filament.staff.pages.dashboard', 'staff', 0)
        ->withTranslation('en', ['title' => 'Staff only', 'body' => 'For staff.'])
        ->create(['slug' => 'staff-only']);

    forgetHelpMemo();
    finCodexHelpColumnUser();

    $notFound = (string) __('lin-codex::lin-codex.ui.not_found');

    foreach (['nothing/here', 'draft', 'staff-only'] as $slug) {
        $this->get(route('filament.admin.pages.help', [HelpCenter::SLUG_PARAMETER => $slug]))
            ->assertOk()
            ->assertSee($notFound);
    }
});

it('shows pick a topic and the top-level sections on the bare landing', function (): void {
    finCodexHelpColumnSeed();

    $html = finCodexHelpColumnHtml(finCodexHelpColumnPage()->html());

    expect($html)->toContain(__('lin-codex::lin-codex.ui.pick_a_topic'))
        ->toContain('data-fin-codex-help-node="account"')
        ->toContain('Account')
        // The landing lists the TOP level only; the rail carries the whole tree.
        ->not->toContain('data-fin-codex-help-node="account/signing-in"');
});

it('shows the empty state and a link into the editor when nothing is written yet', function (): void {
    $html = finCodexHelpColumnPage()->html();

    expect($html)->toContain(__('fin-codex::fin-codex.help_center.empty'))
        ->toContain(__('fin-codex::fin-codex.help_center.empty_description'))
        ->toContain(__('fin-codex::fin-codex.help_center.write_article'))
        ->toContain(FinCodexPlugin::articleResourceClass()::getUrl('create'))
        ->not->toContain(__('lin-codex::lin-codex.ui.pick_a_topic'));
});

it('drops the editor link for a viewer who may not create articles', function (): void {
    test()->usesPanel('admin', finCodexHelpColumnUser());

    // After the panel boot, never before: booting the plugin re-registers the
    // shipped policy for the panel, which would overwrite this one.
    Gate::policy(Article::class, DenyAllArticlePolicy::class);
    forgetHelpMemo();

    expect(ArticleAbility::allows('create'))->toBeFalse()
        ->and(Livewire::test(HelpCenter::class)->html())
        ->toContain(__('fin-codex::fin-codex.help_center.empty'))
        ->not->toContain(__('fin-codex::fin-codex.help_center.write_article'));
});

it('drops the editor link on a panel that only reads help, and does not throw', function (): void {
    // The empty state's link is the one place the page could reach for a route
    // that does not exist: this page is registered on every panel, but the
    // article resource only on the panels that author.
    app(PanelRegistry::class)->register(
        Panel::make()->id('reader')->path('reader')->plugin(FinCodexPlugin::make()->authoring(false)),
    );

    $html = finCodexHelpColumnPage(null, 'reader')->html();

    expect(Filament::getPanel('reader')->getResources())->toBe([])
        ->and($html)->toContain(__('fin-codex::fin-codex.help_center.empty'))
        ->not->toContain(__('fin-codex::fin-codex.help_center.write_article'));
});

/*
 * -----------------------------------------------------------------------
 * The "On this page" rail (CENTER-05).
 * -----------------------------------------------------------------------
 */

it('lists the article headings in the right column, anchored to the ids the renderer wrote', function (): void {
    finCodexHelpColumnSeed();

    $html = finCodexHelpColumnPage('account/signing-in')->html();

    expect($html)->toContain('fin-codex-help__toc')
        ->toContain(__('lin-codex::lin-codex.ui.on_this_page'))
        ->toContain('href="#passwords"')
        ->toContain('href="#resetting"')
        ->toContain('id="passwords"');
});

it('drops the whole headings column for an article without headings, the landing and the not-found state', function (): void {
    finCodexHelpColumnSeed();

    expect(finCodexHelpColumnPage('account')->html())->not->toContain('fin-codex-help__toc')
        ->and(finCodexHelpColumnPage()->html())->not->toContain('fin-codex-help__toc')
        ->and(finCodexHelpColumnPage('nothing/here')->html())->not->toContain('fin-codex-help__toc');
});
