<?php

use Filament\Facades\Filament;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\ScopedArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
 * FinCodexPlugin::articleResource() names a subclass, and the built-in pages
 * keep `$resource = ArticleResource::class`; Filament resolves the form, the
 * table, the query, the relation managers and the URLs through
 * static::getResource(), so the pages answer that with the panel's override
 * or nothing a host overrides beyond navigation ever takes effect.
 */

function finCodexOverridePlugin(string $panel): FinCodexPlugin
{
    $plugin = Filament::getPanel($panel)->getPlugin('fin-codex');

    if (! $plugin instanceof FinCodexPlugin) {
        throw new RuntimeException("Panel {$panel} has no FinCodexPlugin.");
    }

    return $plugin;
}

function finCodexOverrideArticle(string $slug): Article
{
    return Article::factory()->public()->published()
        ->withTranslation('en', ['title' => $slug, 'body' => "About {$slug}."])
        ->create(['slug' => $slug]);
}

it('answers getResource() with the serving panel\'s override, the default panel\'s outside a panel, and the built-in class on a panel without one', function (): void {
    expect(Filament::getCurrentPanel())->toBeNull()
        ->and(ListArticles::getResource())->toBe(AdminHelpArticleResource::class)
        ->and(FinCodexPlugin::articleResourceClass('staff'))->toBe(StaffHelpArticleResource::class)
        ->and(FinCodexPlugin::articleResourceClass('portal'))->toBe(ArticleResource::class)
        ->and(FinCodexPlugin::articleResourceClass('plain'))->toBe(ArticleResource::class);

    $this->usesPanel('staff');

    expect(ListArticles::getResource())->toBe(StaffHelpArticleResource::class)
        ->and(CreateArticle::getResource())->toBe(StaffHelpArticleResource::class)
        ->and(EditArticle::getResource())->toBe(StaffHelpArticleResource::class);
});

it('scopes the list and the edit page through an overriding resource', function (): void {
    $scoped = finCodexOverrideArticle('scoped/one');
    $other = finCodexOverrideArticle('other');
    $user = User::create(['name' => 'Editor', 'email' => 'editor@example.com']);

    $this->usesPanel('admin', $user);
    finCodexOverridePlugin('admin')->articleResource(ScopedArticleResource::class);

    Livewire::test(ListArticles::class)
        ->assertCanSeeTableRecords([$scoped])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(EditArticle::class, ['record' => $scoped->getRouteKey()])->assertOk();

    expect(fn () => Livewire::test(EditArticle::class, ['record' => $other->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});
