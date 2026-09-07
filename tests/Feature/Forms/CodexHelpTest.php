<?php

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use FinityLabs\FinCodex\Forms\CodexHelp;
use FinityLabs\FinCodex\Tests\Fixtures\Livewire\HintForm;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource\Pages\EditUser;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Livewire\Livewire;

/*
 * HELP-03 and HELP-04. A field hint is one Filament Action: an icon-only
 * question-mark button whose tooltip is the article title in the reader's
 * language, whose href is the core's help-center URL and whose Alpine click
 * handler dispatches codex:open (with slug and heading) only when a drawer
 * is on the page, so the Phase 2 drawer opens and scrolls with no server
 * round trip and, without a drawer, the browser follows the link. The icon
 * is absent whenever the core's gate says no. The fixture UserResource
 * carries the macro form on name (users#assigning-roles) and the explicit
 * action on email (users/roles); HintForm carries the macro outside every
 * panel. The de/hu rows read their expectation back through __() so a
 * missing translation fails instead of matching the English fallback.
 */

/**
 * The rendered hint anchor with its attributes entity-decoded, or null.
 */
function finCodexHintAnchor(string $html): ?string
{
    return preg_match('/<a[^>]*fi-ac-icon-btn-action[^>]*>/', $html, $m) === 1
        ? html_entity_decode($m[0], ENT_QUOTES)
        : null;
}

function finCodexHintUser(string $email): User
{
    return User::create(['name' => 'Member', 'email' => $email]);
}

function finCodexHintSeedUsers(): void
{
    Article::factory()->public()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'How users work.'])
        ->withTranslation('de', ['title' => 'Benutzer', 'body' => 'So funktionieren Benutzer.'])
        ->create(['slug' => 'users']);
}

/**
 * @param  list<string>  $codes
 */
function finCodexHintUseLanguages(array $codes): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = 'en';
    $settings->save();
}

it('builds an icon-button action with the help-center URL and the drawer handler', function (): void {
    $action = CodexHelp::make('users', 'assigning-roles');

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getName())->toBe('codex-help-users-assigning-roles')
        ->and($action->getUrl())->toBe('/help/users#assigning-roles')
        ->and($action->getLabel())->toBe('Open help')
        ->and($action->getAlpineClickHandler())
        ->toContain("document.querySelector('[data-codex-drawer]')")
        ->toContain('$event.preventDefault()')
        ->toContain("new CustomEvent('codex:open'")
        ->toContain('JSON.parse(')
        ->toContain('assigning-roles');

    $plain = CodexHelp::make('users');

    expect($plain->getName())->toBe('codex-help-users')
        ->and($plain->getUrl())->toBe('/help/users')
        ->and($plain->getAlpineClickHandler())->not->toContain('heading');

    $child = CodexHelp::make('users/roles');

    expect($child->getName())->toBe('codex-help-users-roles')
        ->and($child->getUrl())->toBe('/help/users/roles');
});

it('renders the hint as an icon button with the title tooltip on a resource form', function (): void {
    finCodexHintSeedUsers();

    $html = $this->actingAs(finCodexHintUser('web@example.com'), 'web')
        ->get('/admin/users/create')->assertOk()->getContent();
    $anchor = finCodexHintAnchor($html);

    expect($anchor)->not->toBeNull()
        ->toContain('href="/help/users#assigning-roles"')
        ->toContain('aria-label="Open help"')
        ->toContain("content: 'Users'")
        ->toContain("document.querySelector('[data-codex-drawer]')")
        ->toContain('codex:open')
        ->toContain('assigning-roles')
        ->toContain('fi-icon-btn')
        ->toContain('fi-size-sm')
        ->not->toContain('wire:click')
        ->and(substr_count($html, 'fi-ac-icon-btn-action'))->toBe(1)
        ->and($html)->toContain('x-on:codex:open.window');
});

it('renders both hints when both articles exist and tells them apart by name', function (): void {
    finCodexHintSeedUsers();
    Article::factory()->public()->withTranslation('en', ['title' => 'Roles', 'body' => 'Roles.'])->create(['slug' => 'users/roles']);

    $html = $this->actingAs(finCodexHintUser('web@example.com'), 'web')
        ->get('/admin/users/create')->assertOk()->getContent();

    preg_match_all('/<a[^>]*fi-ac-icon-btn-action[^>]*>/', $html, $m);
    $anchors = array_map(static fn (string $tag): string => html_entity_decode($tag, ENT_QUOTES), $m[0]);

    expect($anchors)->toHaveCount(2)
        ->and($anchors[0])->toContain('href="/help/users#assigning-roles"')->toContain("content: 'Users'")
        ->and($anchors[1])->toContain('href="/help/users/roles"')->toContain("content: 'Roles'");
});

it('renders the hint inside Livewire::test of the edit page', function (): void {
    finCodexHintSeedUsers();
    $user = finCodexHintUser('web@example.com');
    $this->usesPanel('admin', $user);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertSeeHtml('href="/help/users#assigning-roles"')
        ->assertSeeHtml('codex:open')
        ->assertDontSeeHtml('wire:click="mountAction');
});

it('translates the label and the tooltip title', function (string $locale, string $title): void {
    finCodexHintSeedUsers();
    finCodexHintUseLanguages(['en', 'de']);
    app()->setLocale($locale);

    $label = __('fin-codex::fin-codex.hint.open');

    expect($label)->not->toBe('Open help');

    $html = $this->actingAs(finCodexHintUser('web@example.com'), 'web')
        ->get('/admin/users/create')->assertOk()->getContent();
    $anchor = finCodexHintAnchor($html);

    expect($anchor)->not->toBeNull()
        ->toContain('aria-label="'.$label.'"')
        ->toContain("content: '".$title."'")
        ->not->toContain('aria-label="Open help"');
})->with([
    'de (an enabled language)' => ['de', 'Benutzer'],
    'hu (not enabled, falls back to the default)' => ['hu', 'Users'],
]);

it('renders no hint when the viewer may not read the article', function (callable $seed): void {
    $seed();

    $html = $this->actingAs(finCodexHintUser('web@example.com'), 'web')
        ->get('/admin/users/create')->assertOk()->getContent();

    expect($html)->not->toContain('fi-ac-icon-btn-action')
        ->not->toContain('/help/')
        ->toContain('data.name');
})->with([
    'unknown slug' => [fn () => null],
    'unpublished' => [fn () => Article::factory()->unpublished()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users'])],
    'unpublished ancestor' => [function (): void {
        Article::factory()->unpublished()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
        Article::factory()->public()->withTranslation('en', ['title' => 'Roles'])->create(['slug' => 'users/roles']);
    }],
]);

it('hides an authenticated article from a guest outside a panel', function (): void {
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);

    Livewire::test(HintForm::class)
        ->assertDontSeeHtml('fi-ac-icon-btn-action')
        ->assertDontSeeHtml('/help/');
});

it('shows it to a user on the default guard outside a panel', function (): void {
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    $this->actingAs(finCodexHintUser('member@example.com'), 'web');

    Livewire::test(HintForm::class)
        ->assertSeeHtml('href="/help/users"')
        ->assertSeeHtml('fi-ac-icon-btn-action');
});

it('uses the lin-codex config guard outside a panel', function (): void {
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    config(['lin-codex.auth.guard' => 'staff']);
    $this->actingAs(finCodexHintUser('member@example.com'), 'web');

    Livewire::test(HintForm::class)
        ->assertDontSeeHtml('fi-ac-icon-btn-action')
        ->assertDontSeeHtml('/help/');
});

it('uses the panel guard inside a panel and hides it from a user on another guard', function (): void {
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    $this->usesPanel('staff');
    $this->actingAs(finCodexHintUser('member@example.com'), 'web');

    Livewire::test(HintForm::class)
        ->assertDontSeeHtml('fi-ac-icon-btn-action')
        ->assertDontSeeHtml('/help/');
});

it('shows it to a user signed in on the panel guard', function (): void {
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    $this->usesPanel('staff', finCodexHintUser('member@example.com'));

    Livewire::test(HintForm::class)
        ->assertSeeHtml('href="/help/users"')
        ->assertSeeHtml('fi-ac-icon-btn-action');
});

it('reaches every field through the macro', function (): void {
    $select = Select::make('role')->codexHelp('users');

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getHintActions())->toHaveCount(1)
        ->and($select->getHintActions()[0])->toBeInstanceOf(Action::class)
        ->and($select->getHintActions()[0]->getName())->toBe('codex-help-users');

    $input = TextInput::make('x')->hintAction(CodexHelp::make('users'));

    expect($input->getHintActions())->toHaveCount(1)
        ->and($input->getHintActions()[0]->getName())->toBe('codex-help-users');
});
