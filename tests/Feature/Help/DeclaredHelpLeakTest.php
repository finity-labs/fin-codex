<?php

use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Coverage\RouteCoverage;
use FinityLabs\LinCodex\Data\SourceWarning;
use FinityLabs\LinCodex\Livewire\HelpDrawer;
use FinityLabs\LinCodex\Models\Article;
use Livewire\Livewire;

/*
 * HELP-02's gate clause, in the VisibilityLeakTest shape: a declared
 * article is an ordinary article to lin-codex, so ArticleGate hides it from
 * a guest (or a user signed in on another guard) exactly as it hides a
 * stored one, across the drawer's page list, tree, search and open() and
 * through the JSON API. No fin-codex code decides visibility; the last row
 * pins that on the two source files. users and staff-users are declared
 * on UserResource (Plan 04-01) and seeded authenticated here.
 */

function finCodexLeakSeedDeclared(): void
{
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users', 'body' => 'Members only.'])
        ->create(['slug' => 'users']);
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Staff users', 'body' => 'Staff only.'])
        ->create(['slug' => 'staff-users']);
}

function finCodexLeakUser(string $email): User
{
    return User::create(['name' => 'Member', 'email' => $email]);
}

function finCodexLeakApiQuery(): string
{
    return '/codex/api/context?'.http_build_query([
        'class' => UserResource::class,
        'panel' => 'admin',
        'route' => 'filament.admin.resources.users.index',
        'path' => '/admin/users',
    ]);
}

it('hides a declared authenticated article from a user signed in on another guard through the page list, tree, search and open', function (): void {
    finCodexLeakSeedDeclared();
    $this->actingAs(finCodexLeakUser('staff@example.com'), 'staff');

    Livewire::test(HelpDrawer::class, ['pageClass' => UserResource::class, 'panelId' => 'admin', 'guard' => 'web'])
        ->assertSet('guard', 'web')
        ->assertDontSeeHtml('data-codex-page-article="users"')
        ->assertSeeHtml('data-codex-page-count="0"')
        ->call('goTo', 'tree')
        ->assertDontSeeHtml('data-codex-tree-node="users"')
        ->assertDontSeeHtml('data-codex-tree-node="staff-users"')
        ->call('goTo', 'search')
        ->set('query', 'Members')
        ->assertDontSee('Members only.')
        ->call('open', 'users')
        ->assertSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertDontSee('Members only.');
});

it('shows it to a user signed in on the panel guard', function (): void {
    finCodexLeakSeedDeclared();
    $this->actingAs(finCodexLeakUser('web@example.com'), 'web');

    Livewire::test(HelpDrawer::class, ['pageClass' => UserResource::class, 'panelId' => 'admin', 'guard' => 'web'])
        ->assertSet('guard', 'web')
        ->assertSeeHtml('data-codex-page-article="users"')
        ->assertSeeHtml('data-codex-page-count="1"')
        ->call('goTo', 'tree')
        ->assertSeeHtml('data-codex-tree-node="users"')
        ->call('goTo', 'search')
        ->set('query', 'Members')
        ->assertSee('Users')
        ->call('open', 'users')
        ->assertDontSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertSee('Members only.');
});

it('hides it from a guest through the JSON API and shows it to a web user', function (): void {
    finCodexLeakSeedDeclared();

    $guest = $this->getJson(finCodexLeakApiQuery())->assertOk()->json('data');

    $this->actingAs(finCodexLeakUser('web@example.com'), 'web');
    $member = $this->getJson(finCodexLeakApiQuery())->assertOk()->json('data');

    expect($guest)->toBe([])
        ->and(array_map(static fn (array $entry): string => (string) $entry['slug'], $member))->toBe(['users'])
        ->and($member[0]['title'])->toBe('Users');
});

it('hides an unpublished declared article without warning about it', function (): void {
    Article::factory()->unpublished()->withTranslation('en', ['title' => 'Users', 'body' => 'Draft body.'])
        ->create(['slug' => 'users']);

    $html = $this->actingAs(finCodexLeakUser('web@example.com'), 'web')->get('/admin/users')->assertOk()->getContent();
    $messages = array_map(static fn (SourceWarning $warning): string => $warning->message(), app(ContentSource::class)->warnings());

    expect($html)->toContain('data-codex-page-count="0"')
        ->not->toContain('data-codex-page-article')
        ->not->toContain('Draft body.')
        ->not->toMatch('/codex-help-button__badge/')
        ->and(implode("\n", $messages))->not->toContain('article users for')
        ->toContain('article user-roles for panel admin');
});

/*
 * Coverage answers "is there help for this route"; the gate answers "may
 * this reader see it". A members-only article is still a mapping, so the
 * report counts it with nobody signed in.
 */
it('still counts a hidden declared article as coverage', function (): void {
    finCodexLeakSeedDeclared();

    $rows = [];

    foreach (app(RouteCoverage::class)->report() as $row) {
        $rows[$row->name] = $row;
    }

    expect(auth('web')->check())->toBeFalse()
        ->and($rows['filament.admin.resources.users.index']->matchedBy)->toBe('admin:route:filament.admin.resources.users.index')
        ->and($rows['filament.admin.resources.users.index']->slug)->toBe('users')
        ->and($rows['filament.staff.resources.users.index']->slug)->toBe('staff-users');
});

it('has no visibility check of its own', function (): void {
    $root = dirname(__DIR__, 3).'/src/Help/';
    $decorator = (string) file_get_contents($root.'DeclaredContextsSource.php');
    $scanner = (string) file_get_contents($root.'DeclaredContexts.php');

    foreach (['Visibility::', '->published', 'ArticleGate', 'ViewerResolver', 'auth('] as $token) {
        expect($decorator)->not->toContain($token)
            ->and($scanner)->not->toContain($token);
    }

    expect($decorator)->toContain('implements ContentSource');
});
