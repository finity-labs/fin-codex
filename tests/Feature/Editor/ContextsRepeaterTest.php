<?php

use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\ContextsRepeater;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * EDIT-04 through the pages. Every row drives CreateArticle or EditArticle,
 * so the proof covers the whole path the admin walks: the cascading selects,
 * the drag order Filament hands back, the mutators that dehydrate the rows,
 * ArticleWriter's one transaction, and finally the core resolving the
 * article from the context that was picked. usesPanel() comes first
 * everywhere; the writer stores whoever the panel names.
 */

function finCodexContextsUser(string $name = 'Contexts'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * A complete create-form state carrying the given repeater rows.
 *
 * @param  list<array<string, mixed>>  $rows
 *
 * @return array<string, mixed>
 */
function finCodexContextsState(array $rows, string $slug = 'billing'): array
{
    return [
        'slug' => $slug,
        'icon' => null,
        'sort_order' => 0,
        'format' => ArticleFormat::Markdown->value,
        'is_published' => true,
        'visibility' => Visibility::Public->value,
        'keywords' => [],
        'related' => [],
        'translations' => ['en' => ['title' => 'Billing', 'excerpt' => null, 'body' => 'How billing works.']],
        'contexts' => $rows,
    ];
}

/**
 * The stored rows in sort order, flattened to what the picker put there.
 *
 * @return list<array{0: ?string, 1: ContextType, 2: string, 3: int}>
 */
function finCodexContextsRows(Article $article): array
{
    return $article->contexts()->orderBy('sort_order')->get()
        ->map(static fn (ArticleContext $context): array => [
            $context->panel_id,
            $context->type,
            $context->key,
            $context->sort_order,
        ])
        ->all();
}

/**
 * The repeater rows in form state, flattened and stripped of the generated
 * item keys.
 *
 * @return list<array{0: ?string, 1: ?string, 2: ?string, 3: ?string}>
 */
function finCodexContextsFormRows(Testable $component): array
{
    $rows = data_get($component->instance()->data, 'contexts');

    return array_map(
        static fn (array $row): array => [$row['panel_id'] ?? null, $row['type'] ?? null, $row['key'] ?? null, $row['url'] ?? null],
        array_values(is_array($rows) ? $rows : []),
    );
}

it('saves picker rows in drag order with any-panel as null', function (): void {
    $user = finCodexContextsUser();
    $this->usesPanel('admin', $user);

    Livewire::test(CreateArticle::class)
        ->fillForm(finCodexContextsState([
            ['panel_id' => 'admin', 'type' => 'class', 'key' => UserResource::class],
            ['panel_id' => ContextPicker::ANY_PANEL, 'type' => 'route', 'key' => 'filament.staff.pages.reports'],
            ['panel_id' => 'admin', 'type' => 'url', 'url' => '/admin/users/*'],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->where('slug', 'billing')->sole();

    expect(finCodexContextsRows($article))->toBe([
        ['admin', ContextType::PageClass, UserResource::class, 0],
        [null, ContextType::Route, 'filament.staff.pages.reports', 1],
        ['admin', ContextType::Url, '/admin/users/*', 2],
    ]);
});

it('fills the repeater from stored rows on edit and reorders on save', function (): void {
    $user = finCodexContextsUser();
    $this->usesPanel('admin', $user);

    $article = Article::factory()
        ->public()
        ->withTranslation('en', ['title' => 'Billing', 'body' => 'How billing works.'])
        ->withContext(ContextType::PageClass, UserResource::class, 'admin', 0)
        ->withContext(ContextType::Route, 'filament.staff.pages.reports', null, 1)
        ->withContext(ContextType::Url, '/admin/users/*', 'admin', 2)
        ->create(['slug' => 'billing']);

    $oldIds = $article->contexts()->pluck('id')->all();

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);

    expect(finCodexContextsFormRows($component))->toBe([
        ['admin', 'class', UserResource::class, ''],
        [ContextPicker::ANY_PANEL, 'route', 'filament.staff.pages.reports', ''],
        ['admin', 'url', '', '/admin/users/*'],
    ]);

    $component
        ->fillForm(['contexts' => array_reverse(ContextsRepeater::fill($article))])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(finCodexContextsRows($article->fresh()))->toBe([
        ['admin', ContextType::Url, '/admin/users/*', 0],
        [null, ContextType::Route, 'filament.staff.pages.reports', 1],
        ['admin', ContextType::PageClass, UserResource::class, 2],
    ])->and(ArticleContext::query()->whereIn('id', $oldIds)->count())->toBe(0);
});

it('drops blank rows and requires a key or pattern', function (): void {
    $user = finCodexContextsUser();
    $this->usesPanel('admin', $user);

    Livewire::test(CreateArticle::class)
        ->fillForm(finCodexContextsState([['panel_id' => 'admin', 'type' => 'class', 'key' => null]]))
        ->call('create')
        ->assertHasFormErrors(['contexts.0.key']);

    Livewire::test(CreateArticle::class)
        ->fillForm(finCodexContextsState([['panel_id' => 'admin', 'type' => 'url', 'url' => null]]))
        ->call('create')
        ->assertHasFormErrors(['contexts.0.url']);

    expect(Article::query()->count())->toBe(0);

    // dehydrate() is the safety net under the form-level required() rules.
    expect(ContextsRepeater::dehydrate([
        ['panel_id' => 'admin', 'type' => 'class', 'key' => UserResource::class, 'url' => ''],
        ['panel_id' => ContextPicker::ANY_PANEL, 'type' => 'class', 'key' => '', 'url' => ''],
        ['panel_id' => ContextPicker::ANY_PANEL, 'type' => 'url', 'key' => '', 'url' => '/x/*'],
        ['panel_id' => 'admin', 'type' => '', 'key' => 'x', 'url' => ''],
    ]))->toBe([
        ['panel_id' => 'admin', 'type' => 'class', 'key' => UserResource::class],
        ['panel_id' => null, 'type' => 'url', 'key' => '/x/*'],
    ]);
});

it('cascades: changing the panel or type clears the key', function (): void {
    $user = finCodexContextsUser();
    $this->usesPanel('admin', $user);

    $component = Livewire::test(CreateArticle::class)
        ->fillForm(finCodexContextsState([['panel_id' => 'admin', 'type' => 'class', 'key' => UserResource::class]]));

    $item = array_key_first(data_get($component->instance()->data, 'contexts'));

    $component
        ->assertSet("data.contexts.{$item}.key", UserResource::class)
        ->set("data.contexts.{$item}.panel_id", 'staff')
        ->assertSet("data.contexts.{$item}.key", null)
        ->set("data.contexts.{$item}.key", UserResource::class)
        ->set("data.contexts.{$item}.type", 'route')
        ->assertSet("data.contexts.{$item}.key", null)
        ->assertSet("data.contexts.{$item}.url", null);
});

it('shows the resolved label per row', function (): void {
    $user = finCodexContextsUser();
    $this->usesPanel('admin', $user);

    Livewire::test(CreateArticle::class)
        ->fillForm(finCodexContextsState([
            ['panel_id' => 'admin', 'type' => 'class', 'key' => UserResource::class],
            ['panel_id' => 'admin', 'type' => 'url', 'url' => '/admin/users/*'],
        ]))
        ->assertSee('data-fin-codex-context-label="Users"', escape: false)
        ->assertSee('data-fin-codex-context-label="/admin/users/*"', escape: false);
});

it('lists declared contexts read-only above the repeater and never saves them', function (): void {
    $user = finCodexContextsUser();
    $this->usesPanel('admin', $user);

    $declared = Article::factory()->public()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'How users work.'])
        ->create(['slug' => 'users']);

    $component = Livewire::test(EditArticle::class, ['record' => $declared->getRouteKey()]);
    $html = $component->html();

    expect($html)->toContain('data-fin-codex-declared')
        ->toContain('data-fin-codex-declared-panel="admin"')
        ->toContain('data-fin-codex-declared-panel="staff"')
        ->toContain('admin:class:'.UserResource::class)
        ->toContain('staff:class:'.UserResource::class)
        ->toContain((string) __('fin-codex::fin-codex.editor.contexts.declared'));

    expect(data_get($component->instance()->data, 'contexts'))->toBe([]);

    $component->call('save')->assertHasNoFormErrors();

    expect($declared->contexts()->count())->toBe(0);

    // An undeclared article, and the create page, carry no declared list.
    $plain = Article::factory()->public()
        ->withTranslation('en', ['title' => 'Billing', 'body' => 'How billing works.'])
        ->create(['slug' => 'billing']);

    expect(Livewire::test(EditArticle::class, ['record' => $plain->getRouteKey()])->html())
        ->not->toContain('data-fin-codex-declared')
        ->and(Livewire::test(CreateArticle::class)->html())
        ->not->toContain('data-fin-codex-declared');
});

it('makes the article resolvable by the saved context through the core', function (): void {
    $user = finCodexContextsUser();
    $this->usesPanel('admin', $user);

    Livewire::test(CreateArticle::class)
        ->fillForm(finCodexContextsState([
            ['panel_id' => 'admin', 'type' => 'class', 'key' => UserResource::class],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    forgetHelpMemo();

    $found = app(ContentSource::class)->findByContext(ContextType::PageClass, UserResource::class, 'admin');

    expect(array_map(static fn (ArticleData $article): string => $article->slug, $found))->toContain('billing');

    $html = $this->get('/admin/users')->assertOk()->getContent();

    expect($html)->toContain('data-codex-page-article="billing"')
        ->toContain('data-codex-page-count="1"');
});
