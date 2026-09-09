<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\Reports;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\StaffHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\LinCodex\Enums\ContextType;
use Illuminate\Support\Facades\Route;

/*
 * EDIT-04's option sets. The picker exists so that a context key is chosen
 * from what the application actually registers instead of typed, so every
 * row here asks the real panel registry and the real route collection of the
 * four fixture panels. No panel is entered: the option sets are a registry
 * read, not a request.
 */

function finCodexPicker(): ContextPicker
{
    return app(ContextPicker::class);
}

it('lists any panel first and then every registered panel', function (): void {
    $panels = finCodexPicker()->panels();

    expect(array_key_first($panels))->toBe(ContextPicker::ANY_PANEL)
        ->and(array_keys($panels))->toBe([ContextPicker::ANY_PANEL, 'admin', 'staff', 'plain', 'portal'])
        ->and($panels[ContextPicker::ANY_PANEL])->toBe('Any panel')
        ->and($panels['admin'])->toBe('admin')
        ->and($panels['portal'])->toBe('portal');
});

it('lists the three context types with the core labels', function (): void {
    expect(finCodexPicker()->types())->toBe([
        'class' => ContextType::PageClass->label(),
        'route' => ContextType::Route->label(),
        'url' => ContextType::Url->label(),
    ])->and(ContextType::PageClass->label())->toBe('Page class');
});

it('lists resources and pages of one panel as class keys with navigation labels', function (): void {
    $admin = finCodexPicker()->classKeys('admin');

    expect($admin[UserResource::class])->toBe('Users')
        ->and($admin[Reports::class])->toBe('Reports')
        ->and($admin[Dashboard::class])->toBe('Dashboard')
        ->and($admin[AdminHelpArticleResource::class])->toBe('Help articles')
        ->and(array_key_exists(StaffHelpArticleResource::class, $admin))->toBeFalse();

    expect(array_keys(finCodexPicker()->classKeys('plain')))->toBe([Dashboard::class]);

    $union = finCodexPicker()->classKeys(null);

    expect(array_key_exists(AdminHelpArticleResource::class, $union))->toBeTrue()
        ->and(array_key_exists(StaffHelpArticleResource::class, $union))->toBeTrue()
        ->and(array_key_exists(ArticleResource::class, $union))->toBeTrue()
        ->and($union[UserResource::class])->toBe('Users');

    foreach (array_keys($union) as $key) {
        expect($key)->not->toStartWith('\\');
    }

    $labels = array_values($union);
    $sorted = $labels;
    sort($sorted);

    expect($labels)->toBe($sorted);
});

it('lists named GET routes per panel filtered by the coverage ignore list', function (): void {
    Route::get('/fin-codex-scratch', fn (): string => '')->name('fin-codex-test.scratch');

    $admin = finCodexPicker()->routeKeys('admin');

    expect($admin['filament.admin.resources.users.index'])->toContain('Users')
        ->and($admin['filament.admin.pages.reports'])->toBe('Reports')
        ->and($admin['filament.admin.pages.dashboard'])->toBe('Dashboard')
        ->and(array_key_exists('filament.admin.auth.login', $admin))->toBeFalse()
        ->and(array_key_exists('filament.staff.pages.dashboard', $admin))->toBeFalse()
        ->and(array_key_exists('livewire.preview-file', $admin))->toBeFalse()
        ->and(array_key_exists('fin-codex-test.scratch', $admin))->toBeFalse();

    foreach (array_keys($admin) as $name) {
        expect($name)->toStartWith('filament.admin.');
    }

    $all = finCodexPicker()->routeKeys(null);

    expect(array_key_exists('filament.admin.pages.dashboard', $all))->toBeTrue()
        ->and(array_key_exists('filament.staff.pages.dashboard', $all))->toBeTrue()
        ->and(array_key_exists('filament.admin.auth.login', $all))->toBeFalse()
        ->and(array_key_exists('filament.staff.auth.password-reset.request', $all))->toBeFalse()
        ->and(array_key_exists('livewire.preview-file', $all))->toBeFalse()
        ->and(array_key_exists('lin-codex.api.article', $all))->toBeFalse()
        ->and(array_key_exists('storage.local', $all))->toBeFalse();

    // A closure route and a plain controller route have no page class, so the
    // route name is its own label.
    expect($all['fin-codex-test.scratch'])->toBe('fin-codex-test.scratch')
        ->and($all['filament.exports.download'])->toBe('filament.exports.download');

    expect(array_keys($all))->toBe(collect(array_keys($all))->sort()->values()->all());
});

it('honours a changed ignore list', function (): void {
    config()->set('lin-codex.coverage.ignore', ['filament.admin.pages.*']);

    $admin = finCodexPicker()->routeKeys('admin');

    expect(array_key_exists('filament.admin.pages.dashboard', $admin))->toBeFalse()
        ->and(array_key_exists('filament.admin.pages.reports', $admin))->toBeFalse()
        ->and($admin['filament.admin.auth.login'])->toBe('filament.admin.auth.login')
        ->and($admin['filament.admin.resources.users.index'])->toContain('Users');
});

it('resolves labels for every type and null for blanks', function (): void {
    $picker = finCodexPicker();

    expect($picker->label('admin', 'class', UserResource::class))->toBe('Users')
        ->and($picker->label(null, 'route', 'filament.staff.pages.reports'))->toBe('Reports')
        ->and($picker->label(ContextPicker::ANY_PANEL, 'url', '/admin/*'))->toBe('/admin/*')
        ->and($picker->label('admin', 'class', 'Nope'))->toBeNull()
        ->and($picker->label(null, null, null))->toBeNull()
        ->and($picker->label('admin', 'class', ''))->toBeNull()
        ->and($picker->label('admin', 'route', 'filament.admin.auth.login'))->toBeNull();
});

it('describes class keys as picker rows with kind, path and panels', function (): void {
    $admin = collect(finCodexPicker()->classRows('admin'))->keyBy('key');

    expect($admin[UserResource::class])->toBe([
        'key' => UserResource::class,
        'label' => 'Users',
        'kind' => 'Resource',
        'uri' => '/admin/users',
        'panel' => ['admin'],
    ])
        ->and($admin[Reports::class]['kind'])->toBe('Page')
        ->and($admin[Reports::class]['uri'])->toBe('/admin/reports')
        ->and($admin[Dashboard::class]['uri'])->toBe('/admin')
        ->and($admin->keys()->all())->toBe(array_keys(finCodexPicker()->classKeys('admin')));

    $union = collect(finCodexPicker()->classRows(null))->keyBy('key');

    // A class several panels register is one row that lists them all and
    // keeps the first panel's path.
    expect($union[Dashboard::class]['panel'])->toContain('admin', 'staff', 'plain')
        ->and($union[Dashboard::class]['uri'])->toBe('/admin')
        ->and($union[StaffHelpArticleResource::class]['panel'])->toBe(['staff']);
});

it('describes route keys as picker rows with path and panel', function (): void {
    Route::get('/fin-codex-scratch', fn (): string => '')->name('fin-codex-test.scratch');

    $admin = collect(finCodexPicker()->routeRows('admin'))->keyBy('key');

    expect($admin['filament.admin.resources.users.index']['uri'])->toBe('/admin/users')
        ->and($admin['filament.admin.resources.users.index']['panel'])->toBe('admin')
        ->and($admin['filament.admin.pages.reports']['label'])->toBe('Reports')
        ->and($admin->keys()->all())->toBe(array_keys(finCodexPicker()->routeKeys('admin')));

    $all = collect(finCodexPicker()->routeRows(null))->keyBy('key');

    // A route outside Filament belongs to no panel.
    expect($all['fin-codex-test.scratch'])->toBe([
        'key' => 'fin-codex-test.scratch',
        'label' => 'fin-codex-test.scratch',
        'uri' => '/fin-codex-scratch',
        'panel' => null,
    ])
        ->and($all['filament.staff.pages.dashboard']['panel'])->toBe('staff');
});
