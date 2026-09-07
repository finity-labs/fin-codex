<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\AdminHelpArticleResource;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * COV-02: closing a gap from the coverage row.
 *
 * The prefill travels as a query string, so the link is shareable, survives a
 * refresh and the back button, and is assertable without reaching into session
 * state. Every row here builds that URL with the resource's own getUrl(), the
 * builder the coverage page uses, and hands its query string to the mount —
 * so a parameter renamed on one side reddens these rows rather than silently
 * doing nothing in a browser.
 *
 * Helpers are prefixed finCodexActions*: finCodexCoverage* belongs to
 * CoverageReportTest, finCodexPage* to HelpCoverageTest, finCodexSurface* to
 * WarningsSurfaceTest, and Pest helpers are global.
 */

/** A fixture user signed in on the admin panel. */
function finCodexActionsUser(string $name = 'Admin'): User
{
    $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

    test()->usesPanel('admin', $user);

    return $user;
}

/**
 * The create page as the coverage page's "Write article" opens it: the URL is
 * built by the resource, then its query string is what the mount sees.
 *
 * @param  array<string, string>  $prefill
 */
function finCodexActionsCreate(array $prefill = []): Testable
{
    $url = AdminHelpArticleResource::getUrl('create', $prefill);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return Livewire::withQueryParams($query)->test(CreateArticle::class);
}

/**
 * The raw form state of a mounted create page — the repeater rows as they
 * are, uuid keys and all.
 *
 * @return array<string, mixed>
 */
function finCodexActionsState(Testable $page): array
{
    return $page->instance()->form->getRawState();
}

/** The context rows of one article as "{panel|*}:{type}:{key}" strings, in order. */
function finCodexActionsContexts(Article $article): array
{
    return ArticleContext::query()
        ->where('article_id', $article->id)
        ->orderBy('sort_order')
        ->get()
        ->map(fn (ArticleContext $context): string => ($context->panel_id ?? ContextPicker::ANY_PANEL).':'.$context->type->key().':'.$context->key)
        ->all();
}

/*
 * -----------------------------------------------------------------------
 * The create form, opened from a coverage row.
 * -----------------------------------------------------------------------
 */

it('opens with the page\'s own context and title already filled', function (): void {
    finCodexActionsUser();

    $state = finCodexActionsState(finCodexActionsCreate([
        'context_type' => ContextType::PageClass->key(),
        'context_key' => UserResource::class,
        'panel' => 'admin',
        'title' => 'Users',
    ]));

    $rows = array_values($state['contexts']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['panel_id'])->toBe('admin')
        ->and($rows[0]['type'])->toBe(ContextType::PageClass->key())
        ->and($rows[0]['key'])->toBe(UserResource::class)
        ->and($state['translations']['en']['title'])->toBe('Users');
});

it('keeps every default the plain create page has', function (): void {
    finCodexActionsUser();

    $plain = finCodexActionsState(finCodexActionsCreate());
    $prefilled = finCodexActionsState(finCodexActionsCreate([
        'context_type' => ContextType::PageClass->key(),
        'context_key' => UserResource::class,
        'panel' => 'admin',
        'title' => 'Users',
    ]));

    // Against the plain page, never against literals: Schema::fill() only
    // hydrates a component's default when it is handed null, so filling
    // straight from the prefill array would drop these three silently.
    expect($prefilled['format'])->toBe($plain['format'])
        ->and($prefilled['is_published'])->toBe($plain['is_published'])
        ->and($prefilled['visibility'])->toBe($plain['visibility'])
        ->and($plain['format'])->not->toBeNull();
});

it('saves in one press, with the context nobody had to touch', function (): void {
    finCodexActionsUser();

    finCodexActionsCreate([
        'context_type' => ContextType::PageClass->key(),
        'context_key' => UserResource::class,
        'panel' => 'admin',
        'title' => 'Users',
    ])
        ->set('data.slug', 'users-guide')
        ->set('data.translations.en.body', 'How users work.')
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->where('slug', 'users-guide')->firstOrFail();

    // A Select carries an implicit Rule::in() over its options, so a key the
    // picker does not offer would fail validation right here.
    expect(finCodexActionsContexts($article))->toBe(['admin:class:'.UserResource::class]);
});

it('prefills a standalone route the same way', function (): void {
    finCodexActionsUser();

    $state = finCodexActionsState(finCodexActionsCreate([
        'context_type' => ContextType::Route->key(),
        'context_key' => 'filament.admin.pages.dashboard',
        'panel' => 'admin',
        'title' => 'Dashboard',
    ]));

    $rows = array_values($state['contexts']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['panel_id'])->toBe('admin')
        ->and($rows[0]['type'])->toBe(ContextType::Route->key())
        ->and($rows[0]['key'])->toBe('filament.admin.pages.dashboard')
        ->and($state['translations']['en']['title'])->toBe('Dashboard');
});

it('drops a key the picker does not offer instead of opening a form that cannot be saved', function (): void {
    finCodexActionsUser();

    $page = finCodexActionsCreate([
        'context_type' => ContextType::PageClass->key(),
        'context_key' => 'App\\Nope\\Missing',
        'panel' => 'admin',
        'title' => 'Missing',
    ]);

    $state = finCodexActionsState($page);

    expect($state['contexts'])->toBe([])
        ->and($state['translations']['en']['title'])->toBeNull();

    $page->assertNotified(__('fin-codex::fin-codex.coverage.prefill.dropped'));

    // And it is an ordinary empty form, not a broken one.
    $page->set('data.slug', 'anything')
        ->set('data.translations.en.title', 'Anything')
        ->set('data.translations.en.body', 'Body.')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Article::query()->where('slug', 'anything')->exists())->toBeTrue();
});

it('widens an unknown panel to any panel rather than refusing the prefill', function (): void {
    finCodexActionsUser();

    $rows = array_values(finCodexActionsState(finCodexActionsCreate([
        'context_type' => ContextType::PageClass->key(),
        'context_key' => UserResource::class,
        'panel' => 'nosuchpanel',
        'title' => 'Users',
    ]))['contexts']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['panel_id'])->toBe(ContextPicker::ANY_PANEL)
        ->and($rows[0]['key'])->toBe(UserResource::class);
});

it('changes nothing at all without a query string', function (): void {
    finCodexActionsUser();

    expect(finCodexActionsState(finCodexActionsCreate()))
        ->toBe(finCodexActionsState(finCodexActionsCreate()))
        ->and(finCodexActionsState(finCodexActionsCreate())['contexts'])->toBe([])
        ->and(finCodexActionsState(finCodexActionsCreate())['translations']['en']['title'])->toBeNull()
        ->and(Filament::getCurrentPanel()?->getId())->toBe('admin');
});

/*
 * -----------------------------------------------------------------------
 * The row actions on the coverage page itself.
 * -----------------------------------------------------------------------
 */

/** The admin coverage page with every row on one page, as HelpCoverageTest mounts it. */
function finCodexActionsPage(): Testable
{
    return Livewire::test(AdminHelpCoverage::class)->set('tableRecordsPerPage', 'all');
}

/** The report's own key for one screen, so a fixture change cannot make a row vacuous. */
function finCodexActionsRowKey(?string $panelId, ?string $helpClass): string
{
    foreach (app(CoverageReport::class)->rows() as $row) {
        if ($row->panelId === $panelId && $row->helpClass === $helpClass) {
            return $row->key;
        }
    }

    throw new RuntimeException('No coverage row for '.($panelId ?? 'no panel').' / '.($helpClass ?? 'no class'));
}

/** The rows the page is showing, keyed by the row key. */
function finCodexActionsRecords(Testable $page): Collection
{
    return $page->instance()->getTableRecords()->getCollection();
}

/** A public database article with one English translation and no context. */
function finCodexActionsArticle(string $slug): Article
{
    return Article::factory()->public()
        ->withTranslation('en', ['title' => Str::headline($slug), 'body' => 'About '.$slug.'.'])
        ->create(['slug' => $slug]);
}

/**
 * A docs tree with one file article whose front matter carries a stored
 * context on the admin users screen. The fixture docs cannot serve here: the
 * article they cover is covered by a HasHelp DECLARATION, and a declared row
 * is exactly the row that must not offer an import.
 */
function finCodexActionsFileDocs(): string
{
    $dir = sys_get_temp_dir().'/fin-codex-coverage-docs';

    if (is_dir($dir)) {
        foreach ((array) glob($dir.'/en/*.md') as $file) {
            @unlink((string) $file);
        }
    }

    @mkdir($dir.'/en', 0777, true);

    // Single-quoted YAML, so the class name's backslashes stay backslashes.
    $context = 'admin:class:'.UserResource::class;

    file_put_contents($dir.'/en/handbook.md', <<<MD
        ---
        visibility: public
        contexts:
          - '{$context}'
        ---

        # Handbook

        The handbook.
        MD);

    config()->set('lin-codex.sources.filesystem.paths', [$dir]);

    app()->forgetInstance(FilesystemSource::class);

    forgetHelpMemo();

    return $dir;
}

it('offers "Write article" on a gap, carrying that screen and its panel into the form', function (): void {
    finCodexActionsUser();
    forgetHelpMemo();

    $key = finCodexActionsRowKey('admin', UserResource::class);
    $page = finCodexActionsPage();
    $label = (string) finCodexActionsRecords($page)[$key]['label'];

    // The same builder the page uses, so a renamed parameter reddens this row
    // rather than opening an empty form in a browser.
    $expected = AdminHelpArticleResource::getUrl('create', [
        'context_type' => ContextType::PageClass->key(),
        'context_key' => UserResource::class,
        'panel' => 'admin',
        'title' => $label,
    ], panel: 'admin');

    $page->assertTableActionVisible('write', $key)
        ->assertTableActionVisible('attach', $key)
        ->assertTableActionHasUrl('write', $expected, $key);

    expect($expected)->toContain('context_type='.ContextType::PageClass->key())
        ->toContain('context_key='.urlencode(UserResource::class))
        ->toContain('panel=admin');
});

it('prefills a route context for a screen that is not a Filament page', function (): void {
    Route::get('/shop', fn (): string => '')->name('shop.index')->middleware('web');

    finCodexActionsUser();
    forgetHelpMemo();

    $page = finCodexActionsPage()->filterTable('panel', CoverageReport::OUTSIDE_PANELS);
    $label = (string) finCodexActionsRecords($page)['shop.index']['label'];

    $expected = AdminHelpArticleResource::getUrl('create', [
        'context_type' => ContextType::Route->key(),
        'context_key' => 'shop.index',
        'panel' => ContextPicker::ANY_PANEL,
        'title' => $label,
    ], panel: 'admin');

    $page->assertTableActionHasUrl('write', $expected, 'shop.index');

    expect($expected)->toContain('context_type='.ContextType::Route->key())
        ->toContain('context_key=shop.index')
        ->toContain('panel='.urlencode(ContextPicker::ANY_PANEL));
});

it('attaches the screen to an article the admin already has, and the row goes green', function (): void {
    $user = finCodexActionsUser();
    $article = finCodexActionsArticle('handbook');
    forgetHelpMemo();

    $key = finCodexActionsRowKey('admin', UserResource::class);

    finCodexActionsPage()
        ->callTableAction('attach', $key, ['article' => 'handbook'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('fin-codex::fin-codex.coverage.attach.attached'));

    expect(finCodexActionsContexts($article))->toBe(['admin:class:'.UserResource::class])
        ->and($article->fresh()->updated_by)->toBe($user->id);

    // The report memoises one reading of the source per request, so the row
    // flips on the next render, not inside this one.
    forgetHelpMemo();

    $row = finCodexActionsRecords(finCodexActionsPage())[$key];

    expect($row['covered'])->toBeTrue()
        ->and($row['slug'])->toBe('handbook');
});

it('takes both gap actions away once the row is covered, so nobody attaches twice', function (): void {
    finCodexActionsUser();
    $article = finCodexActionsArticle('handbook');
    forgetHelpMemo();

    $key = finCodexActionsRowKey('admin', UserResource::class);

    finCodexActionsPage()->callTableAction('attach', $key, ['article' => 'handbook']);

    forgetHelpMemo();

    finCodexActionsPage()
        ->assertTableActionHidden('attach', $key)
        ->assertTableActionHidden('write', $key);

    expect(finCodexActionsContexts($article))->toBe(['admin:class:'.UserResource::class]);
});

it('writes nothing when the row went green while the modal was open', function (): void {
    finCodexActionsUser();
    $article = finCodexActionsArticle('handbook');
    forgetHelpMemo();

    $key = finCodexActionsRowKey('admin', UserResource::class);

    // The modal is open on a row that is still a gap.
    $page = finCodexActionsPage();
    $page->mountTableAction('attach', $key);

    expect($page->instance()->getMountedAction())->not->toBeNull();

    // Another admin attaches the same context while the modal sits open.
    app(ArticleWriter::class)->appendContext($article, [
        'panel_id' => 'admin',
        'type' => ContextType::PageClass->key(),
        'key' => UserResource::class,
    ], null);

    forgetHelpMemo();

    // Filament re-resolves the record before it runs a mounted action, sees a
    // covered row and refuses: the action stays mounted and nothing is
    // written. So the writer's own duplicate refusal (proven in
    // ArticleWriterTest) is a second line of defence rather than the one the
    // admin ever meets — nothing reaches it through this page.
    $page->setTableActionData(['article' => 'handbook'])
        ->callMountedTableAction();

    $mounted = $page->instance()->getMountedAction();

    expect($mounted?->getName())->toBe('attach')
        ->and($mounted?->isVisible())->toBeFalse()
        ->and(finCodexActionsContexts($article))->toBe(['admin:class:'.UserResource::class]);
});

it('offers only articles that live in the database, because a file has no row to hang a context on', function (): void {
    finCodexActionsUser();
    finCodexActionsArticle('handbook');
    useFixtureDocs();

    // The Dashboard row, not the users one: the fixture docs cover users
    // through a declaration, and a covered row offers no attach at all.
    $key = finCodexActionsRowKey('admin', Dashboard::class);
    $page = finCodexActionsPage();
    $page->mountTableAction('attach', $key);

    $schema = $page->instance()->getMountedAction()->getSchema(Schema::make($page->instance()));
    $select = $schema?->getComponent(fn (Component $component): bool => $component instanceof Select);
    $options = $select instanceof Select ? $select->getOptions() : [];

    expect(array_keys($options))->toContain('handbook')
        ->not->toContain('intro')
        ->not->toContain('users/roles');
});

it('takes a covered row straight to the article that covers it', function (): void {
    finCodexActionsUser();
    $article = finCodexActionsArticle('handbook');

    app(ArticleWriter::class)->appendContext($article, [
        'panel_id' => 'admin',
        'type' => ContextType::PageClass->key(),
        'key' => UserResource::class,
    ], null);

    forgetHelpMemo();

    $key = finCodexActionsRowKey('admin', UserResource::class);
    $expected = AdminHelpArticleResource::getUrl('edit', ['record' => $article->id], panel: 'admin');

    finCodexActionsPage()
        ->assertTableColumnExists('slug', fn ($column): bool => $column->getUrl() === $expected, $key)
        ->assertTableActionHidden('write', $key)
        ->assertTableActionHidden('attach', $key)
        ->assertTableActionHidden('import', $key);
});

it('says plainly when a screen is covered by a declaration in code, and offers no way to edit it', function (): void {
    finCodexActionsUser();
    useFixtureDocs();

    $key = finCodexActionsRowKey('admin', UserResource::class);
    $page = finCodexActionsPage();
    $row = finCodexActionsRecords($page)[$key];

    expect($row['covered'])->toBeTrue()
        ->and($row['declared'])->toBeTrue();

    $page->assertTableColumnExists('slug', fn ($column): bool => $column->getUrl() === null, $key)
        ->assertTableColumnHasDescription('slug', __('fin-codex::fin-codex.coverage.declared'), $key)
        ->assertTableActionHidden('import', $key)
        ->assertTableActionHidden('write', $key);
});

it('imports the file first when the article covering a screen has no database row yet', function (): void {
    $user = finCodexActionsUser();
    finCodexActionsFileDocs();

    $key = finCodexActionsRowKey('admin', UserResource::class);
    $page = finCodexActionsPage();
    $row = finCodexActionsRecords($page)[$key];

    expect($row['covered'])->toBeTrue()
        ->and($row['file_only'])->toBeTrue()
        ->and($row['declared'])->toBeFalse();

    $page->assertTableColumnExists('slug', fn ($column): bool => $column->getUrl() === null, $key)
        ->assertTableActionVisible('import', $key)
        ->callTableAction('import', $key)
        ->assertHasNoTableActionErrors();

    $article = Article::query()->where('slug', 'handbook')->firstOrFail();

    $page->assertRedirect(AdminHelpArticleResource::getUrl('edit', ['record' => $article], panel: 'admin'));

    expect($article->created_by)->toBe($user->id)
        ->and($article->source_path)->toBe('en/handbook.md');
});
