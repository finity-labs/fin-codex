<?php

use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\FinCodex\Help\DeclaredContextsSource;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\EditUser;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ContextData;
use FinityLabs\LinCodex\Data\SourceWarning;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Enums\SourceWarningKind;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sources\ArticleSet;
use FinityLabs\LinCodex\Sources\CompositeSource;

/*
 * HELP-02 at the source level: the bound ContentSource is fin-codex's
 * decorator around lin-codex's own source. It appends the panel-scoped
 * synthetic contexts of Plan 04-01 to every declared article that exists,
 * marks them on the article's meta, warns once per declared slug that has
 * no article, and leaves everything else (undeclared articles, the tree,
 * the search documents) exactly as the inner source answers it. Articles
 * are seeded before the source is first resolved in each test.
 */

function finCodexSource(): ContentSource
{
    return app(ContentSource::class);
}

/**
 * @param  list<ContextData>  $contexts
 *
 * @return list<string>
 */
function finCodexSourceStrings(array $contexts): array
{
    return array_map(static fn (ContextData $context): string => $context->toString(), $contexts);
}

/**
 * @return list<string>
 */
function finCodexSourceDeclaredUsersStrings(): array
{
    return [
        'admin:class:'.UserResource::class,
        'admin:route:filament.admin.resources.users.index',
        'admin:route:filament.admin.resources.users.create',
        'admin:route:filament.admin.resources.users.edit',
        'staff:class:'.UserResource::class,
        'staff:route:filament.staff.resources.users.index',
        'staff:route:filament.staff.resources.users.create',
        'staff:route:filament.staff.resources.users.edit',
    ];
}

/**
 * @return list<string>
 */
function finCodexSourceWarningMessages(): array
{
    return array_map(static fn (SourceWarning $warning): string => $warning->message(), finCodexSource()->warnings());
}

it('decorates the bound content source and survives a forgotten instance', function (): void {
    $first = finCodexSource();

    expect($first)->toBeInstanceOf(DeclaredContextsSource::class)
        ->and($first->inner())->toBeInstanceOf(CompositeSource::class)
        ->and(finCodexSource())->toBe($first);

    app()->forgetInstance(ContentSource::class);
    $second = finCodexSource();

    expect($second)->toBeInstanceOf(DeclaredContextsSource::class)
        ->not->toBe($first)
        ->and($second->inner())->toBeInstanceOf(CompositeSource::class)
        ->and(app(DeclaredContexts::class))->toBe(app(DeclaredContexts::class));
});

it('appends the synthetic contexts and the meta marker to a declared article', function (): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Users', 'body' => 'About users.'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin', 0)
        ->withMeta(['author' => 'Ada'])
        ->create(['slug' => 'users']);

    $users = finCodexSource()->all()['users'];
    $declared = finCodexSourceDeclaredUsersStrings();

    expect(finCodexSourceStrings($users->contexts))->toBe(['admin:class:'.UserResource::class, ...$declared])
        ->and(array_map(static fn (ContextData $context): int => $context->sortOrder, $users->contexts))
        ->toBe([0, -1_000_000, -1_000_000, -1_000_000, -1_000_000, -999_999, -999_999, -999_999, -999_999])
        ->and(array_map(static fn (ContextData $context): ?string => $context->panelId, $users->contexts))
        ->toBe(['admin', 'admin', 'admin', 'admin', 'admin', 'staff', 'staff', 'staff', 'staff'])
        ->and($users->meta[DeclaredContextsSource::META_KEY])->toBe($declared)
        ->and($users->meta['author'])->toBe('Ada')
        ->and(array_keys($users->meta))->toBe(['author', 'fin-codex-declared'])
        ->and(finCodexSource()->findBySlug('users'))->toEqual($users)
        ->and($users->slug)->toBe('users')
        ->and($users->published)->toBeTrue();
});

it('leaves undeclared articles untouched', function (): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Stored only', 'body' => 'Stored.'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin', 0)
        ->create(['slug' => 'stored-only']);

    $decorated = finCodexSource()->all()['stored-only'];
    $inner = app(CompositeSource::class)->all()['stored-only'];

    expect($decorated)->toEqual($inner)
        ->and($decorated->meta)->not->toHaveKey(DeclaredContextsSource::META_KEY)
        ->and(finCodexSourceStrings($decorated->contexts))->toBe(['admin:class:'.UserResource::class])
        ->and(finCodexSource()->findBySlug('stored-only'))->toEqual($inner)
        ->and(finCodexSource()->findBySlug('missing'))->toBeNull();
});

it('answers findByContext through the decorated map', function (): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Users', 'body' => 'About users.'])
        ->create(['slug' => 'users']);

    $source = finCodexSource();
    $slugs = static fn (array $articles): array => array_map(static fn ($article): string => $article->slug, $articles);

    expect($slugs($source->findByContext(ContextType::Route, 'filament.admin.resources.users.index', 'admin')))->toBe(['users'])
        ->and($source->findByContext(ContextType::Route, 'filament.admin.resources.users.index', null))->toBe([])
        ->and($slugs($source->findByContext(ContextType::PageClass, UserResource::class, 'staff')))->toBe(['users'])
        ->and($source->findByContext(ContextType::PageClass, UserResource::class, 'portal'))->toBe([])
        ->and($source->findByContext(ContextType::Route, 'filament.admin.resources.users.index', 'admin'))
        ->toEqual((new ArticleSet($source->all()))->findByContext(ContextType::Route, 'filament.admin.resources.users.index', 'admin'));
});

it('delegates tree and search documents to the inner source', function (): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Users', 'body' => 'About users.'])
        ->create(['slug' => 'users']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Stored only', 'body' => 'Stored.'])
        ->create(['slug' => 'stored-only']);

    $source = finCodexSource();
    $inner = app(CompositeSource::class);

    expect($source->tree())->toEqual($inner->tree())
        ->and($source->tree())->toHaveCount(2)
        ->and($source->allForSearch())->toEqual($inner->allForSearch())
        ->and($source->allForSearch())->toHaveCount(2);
});

it('warns once per unknown declared slug, class and panel', function (): void {
    $warnings = finCodexSource()->warnings();
    $messages = finCodexSourceWarningMessages();

    expect($messages)->toHaveCount(8)
        ->toContain(UserResource::class.': declares unknown article users for panel admin')
        ->toContain(UserResource::class.': declares unknown article user-roles for panel admin')
        ->toContain(UserResource::class.': declares unknown article staff-users for panel staff')
        ->toContain(UserResource::class.': declares unknown article users for panel staff')
        ->toContain(EditUser::class.': declares unknown article editing-users for panel admin')
        ->toContain(EditUser::class.': declares unknown article editing-users for panel staff')
        ->toContain(Reports::class.': declares unknown article reports for panel admin')
        ->toContain(Reports::class.': declares unknown article reports for panel staff')
        ->and($warnings[0]->kind)->toBe(SourceWarningKind::InvalidSlug)
        ->and($warnings[0]->path)->toBe(UserResource::class)
        ->and($warnings[0]->slug)->toBe('users')
        ->and($warnings[0]->locale)->toBeNull()
        ->and($warnings[0]->detail)->toBe('declares unknown article users for panel admin')
        ->and(implode("\n", $messages))->not->toContain('was dropped');
});

/*
 * users and reports are each declared in two panels, so seeding those two
 * articles silences four of the eight warnings.
 */
it('drops the warning once the article exists, even unpublished', function (): void {
    Article::factory()->unpublished()->withTranslation('en', ['title' => 'Users', 'body' => 'About users.'])
        ->create(['slug' => 'users']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Reports', 'body' => 'About reports.'])
        ->create(['slug' => 'reports']);

    $messages = finCodexSourceWarningMessages();
    $users = finCodexSource()->all()['users'];

    expect($messages)->toHaveCount(4)
        ->and(implode("\n", $messages))->not->toContain('article users for')
        ->not->toContain('article reports for')
        ->and($messages)->toBe([
            UserResource::class.': declares unknown article user-roles for panel admin',
            EditUser::class.': declares unknown article editing-users for panel admin',
            UserResource::class.': declares unknown article staff-users for panel staff',
            EditUser::class.': declares unknown article editing-users for panel staff',
        ])
        ->and($users->published)->toBeFalse()
        ->and(finCodexSourceStrings($users->contexts))->toBe(finCodexSourceDeclaredUsersStrings())
        ->and($users->meta[DeclaredContextsSource::META_KEY])->toBe(finCodexSourceDeclaredUsersStrings());
});
