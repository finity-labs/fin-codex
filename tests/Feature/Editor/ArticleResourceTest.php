<?php

use Filament\Facades\Filament;
use FinityLabs\FinCodex\Enums\NavigationGroup;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;

/*
 * FinCodexPlugin::register() puts the article resource on every panel that
 * carries the plugin: the articleResource() override when the host set one,
 * the built-in ArticleResource otherwise. Admin and staff register real
 * fixture subclasses (what a host does), portal keeps the built-in class and
 * the plain panel, which has no plugin, gets nothing. These rows prove the
 * registration, the navigation placement the plugin owns, and that the three
 * pages render with the Phase 3 drawer and button untouched.
 *
 * One panel per test method for page requests: FilamentManager is scoped and
 * boots only the first panel of a PHP request cycle.
 */

/** A fixture user signed in on the given panel guard. */
function finCodexResourceUser(string $guard = 'web'): User
{
    $user = User::create(['name' => 'Editor', 'email' => 'editor@example.com']);

    test()->actingAs($user, $guard);

    return $user;
}

/** An article with an English translation, seeded straight through the models. */
function finCodexResourceArticle(string $slug = 'getting-started', string $title = 'Getting started'): Article
{
    $article = Article::factory()->create(['slug' => $slug]);

    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => $title,
        'body' => 'The body.',
    ]);

    return $article;
}

it('registers the override on admin and staff and the built-in resource on portal, nothing on plain', function (): void {
    expect(array_values(Filament::getPanel('admin')->getResources()))
        ->toContain(AdminHelpArticleResource::class)
        ->not->toContain(ArticleResource::class)
        ->and(array_values(Filament::getPanel('staff')->getResources()))
        ->toContain(StaffHelpArticleResource::class)
        ->not->toContain(ArticleResource::class)
        ->and(array_values(Filament::getPanel('portal')->getResources()))
        ->toContain(ArticleResource::class)
        ->and(array_values(Filament::getPanel('plain')->getResources()))
        ->not->toContain(ArticleResource::class)
        ->not->toContain(AdminHelpArticleResource::class)
        ->not->toContain(StaffHelpArticleResource::class);
});

it('reads navigation group and sort from the plugin options', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(AdminHelpArticleResource::getNavigationGroup())->toBe('Help')
        ->and(AdminHelpArticleResource::getNavigationSort())->toBe(90)
        ->and(AdminHelpArticleResource::getNavigationLabel())->toBe('Help articles')
        ->and(AdminHelpArticleResource::getModelLabel())->toBe('Article')
        ->and(AdminHelpArticleResource::getPluralModelLabel())->toBe('Articles');

    Filament::setCurrentPanel(Filament::getPanel('staff'));

    expect(StaffHelpArticleResource::getNavigationGroup())->toBe('Support')
        ->and(StaffHelpArticleResource::getNavigationSort())->toBe(5);

    Filament::setCurrentPanel(Filament::getPanel('portal'));

    expect(ArticleResource::getNavigationGroup())->toBe(NavigationGroup::Help)
        ->and(ArticleResource::getNavigationSort())->toBeNull();
});

it('renders the list, create and edit pages on the admin panel', function (): void {
    finCodexResourceUser();
    $article = finCodexResourceArticle();

    $this->get('/admin/help-articles')->assertOk()->assertSee('getting-started');
    $this->get('/admin/help-articles/create')->assertOk();
    $this->get('/admin/help-articles/'.$article->getRouteKey().'/edit')->assertOk();

    expect(AdminHelpArticleResource::getUrl('index', panel: 'admin'))->toEndWith('/admin/help-articles');
});

it('renders the list page on staff and portal', function (string $panel, string $guard): void {
    finCodexResourceUser($guard);
    finCodexResourceArticle();

    $this->get('/'.$panel.'/help-articles')->assertOk()->assertSee('getting-started');
})->with([
    'staff (override)' => ['staff', 'staff'],
    'portal (built-in)' => ['portal', 'web'],
]);

it('keeps the help button and drawer on the article pages', function (): void {
    finCodexResourceUser();
    finCodexResourceArticle();

    $html = $this->get('/admin/help-articles')->assertOk()->getContent();

    expect(substr_count((string) $html, 'data-codex-drawer'))->toBe(1)
        ->and(substr_count((string) $html, 'data-fin-codex-help-button="admin"'))->toBe(1);
});
