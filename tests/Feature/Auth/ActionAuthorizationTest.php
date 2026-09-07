<?php

use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Editor\FileArticleAdopter;
use FinityLabs\FinCodex\Resources\ArticleResource\Livewire\FileArticlesTable;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\RevisionsRelationManager;
use FinityLabs\FinCodex\Tests\Fixtures\Pages\AdminHelpCoverage;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\Resources\UserResource;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * AUTH-01's second half: the actions Filament does not gate for us.
 *
 * Filament's own comment in CanBeAuthorized is explicit — "Actions do not have
 * automatic policy-based authorization" — so revision restore, HTML conversion
 * and the two file-article imports are open to every panel user until an
 * ->authorize() lands on them. Each row here therefore comes in a pair: the
 * button is there for a user the policy allows, and gone for one it refuses.
 * An "it is visible" row on its own would pass just as well with no check at
 * all.
 *
 * Every gate is the Closure form. Filament unshifts the action's OWN record as
 * the gate subject, so ->authorize('restore') inside the revisions manager
 * would ask about an ArticleRevision — a model with no policy anywhere in this
 * package — and the button would vanish for everybody. The row named for it is
 * the one that reddens if somebody ever shortens the closure to a string.
 *
 * The deny rows swap the policy AFTER the component is mounted. That is not a
 * convenience: with a deny-all policy in force from the start, Filament's own
 * resource gate 403s the edit page before any action is built, so the mount
 * would fail for the wrong reason. Swapping mid-flight is also the realistic
 * story — a permission revoked while the admin has the page open.
 */

/**
 * The policy a host really writes: the five standard abilities, granted, and
 * not one word of our vocabulary. restore and convert must survive it through
 * ArticleAbility's update fallback, and import through the create one.
 */
class FinCodexGatedStandardPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, Article $article): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, Article $article): bool
    {
        return true;
    }
}

/**
 * A host that lets this user write new articles but not touch existing ones.
 *
 * It is the only shape that reaches the coverage page's attach re-check: the
 * button asks `create` at class level, because the article to be changed does
 * not exist until the modal's Select is submitted.
 */
class FinCodexGatedNoUpdatePolicy extends FinCodexGatedStandardPolicy
{
    public function update(Authenticatable $user, Article $article): bool
    {
        return false;
    }
}

/** A fixture user signed in on the admin panel's guard. */
function finCodexGatedUser(string $email = 'gated@example.com'): User
{
    $user = User::create(['name' => 'Gated', 'email' => $email]);

    test()->usesPanel('admin', $user);

    return $user;
}

/** An article with one complete English translation, the way the editor leaves it. */
function finCodexGatedArticle(string $slug = 'users', string $title = 'Users'): Article
{
    $article = Article::factory()->create(['slug' => $slug]);

    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => $title,
        'body' => $title.' body',
    ]);

    return $article;
}

/**
 * Rewrite the English translation, which is what makes the core record a
 * revision of the text that was there before. Nothing hand-writes one.
 */
function finCodexGatedRewrite(Article $article, string $title): ArticleRevision
{
    ArticleTranslation::query()
        ->where('article_id', $article->id)
        ->where('locale', 'en')
        ->sole()
        ->fill(['title' => $title, 'body' => $title.' body'])
        ->save();

    return ArticleRevision::query()
        ->where('article_id', $article->id)
        ->orderByDesc('id')
        ->sole();
}

/**
 * The revisions tab, mounted on its own. pageClass is mandatory:
 * RelationManager::getPageClass() returns a string backed by a ?string
 * property, so a mount without it TypeErrors.
 */
function finCodexGatedManager(Article $article): Testable
{
    return Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $article,
        'pageClass' => EditArticle::class,
    ]);
}

/** A published, public HTML article — the only kind convert offers itself on. */
function finCodexGatedHtmlArticle(string $slug = 'legacy'): Article
{
    return Article::factory()->html()->public()->published()
        ->withTranslation('en', ['title' => 'Hi', 'body' => '<h2>Hi</h2><p>Text.</p>'])
        ->create(['slug' => $slug])
        ->fresh();
}

/** Register a policy for the article model for the rest of the test. */
function finCodexGatedPolicy(string $policy): void
{
    Gate::policy(Article::class, $policy);
}

/** The English title and body as they stand in the database. */
function finCodexGatedText(Article $article): array
{
    $translation = ArticleTranslation::query()
        ->where('article_id', $article->id)
        ->where('locale', 'en')
        ->sole();

    return [$translation->title, $translation->body];
}

/*
 * -----------------------------------------------------------------------
 * Revision restore.
 * -----------------------------------------------------------------------
 */

it('offers the revision restore to a user the shipped policy allows', function (): void {
    enableRevisions(true);
    finCodexGatedUser();

    $article = finCodexGatedArticle('users', 'v1');
    $revision = finCodexGatedRewrite($article, 'v2');

    finCodexGatedManager($article)->assertTableActionVisible('restore', $revision);
});

it('takes the revision restore away from a user the policy refuses', function (): void {
    enableRevisions(true);
    finCodexGatedUser();

    $article = finCodexGatedArticle('users', 'v1');
    $revision = finCodexGatedRewrite($article, 'v2');

    $manager = finCodexGatedManager($article);

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    $manager->assertTableActionHidden('restore', $revision);
});

it('writes no revision when a refused restore is called anyway', function (): void {
    enableRevisions(true);
    finCodexGatedUser();

    $article = finCodexGatedArticle('users', 'v1');
    $revision = finCodexGatedRewrite($article, 'v2');
    $revisions = ArticleRevision::query()->count();

    $manager = finCodexGatedManager($article);

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    // Not callTableAction(): that asserts the action is visible first, which is
    // the thing being denied. This is the forged press — mount, then call —
    // and Filament answers it by unmounting and doing nothing.
    $manager->mountTableAction('restore', $revision)->callMountedTableAction();

    expect(finCodexGatedText($article))->toBe(['v2', 'v2 body'])
        ->and(ArticleRevision::query()->count())->toBe($revisions);
});

it('keeps the revision restore for a host policy that only knows update', function (): void {
    enableRevisions(true);
    finCodexGatedUser();

    $article = finCodexGatedArticle('users', 'v1');
    $revision = finCodexGatedRewrite($article, 'v2');

    finCodexGatedPolicy(FinCodexGatedStandardPolicy::class);

    finCodexGatedManager($article)
        ->assertTableActionVisible('restore', $revision)
        ->callTableAction('restore', $revision)
        ->assertHasNoTableActionErrors();

    expect(finCodexGatedText($article))->toBe(['v1', 'v1 body']);
});

/*
 * The row that reddens the moment somebody shortens the closure to
 * ->authorize('restore'): Filament would then ask the Gate about the
 * ArticleRevision the row carries, and this package registers no policy for
 * that model at all.
 */
it('asks about the article, not about the revision row it is standing on', function (): void {
    enableRevisions(true);
    finCodexGatedUser();

    $article = finCodexGatedArticle('users', 'v1');
    $revision = finCodexGatedRewrite($article, 'v2');

    expect(Gate::getPolicyFor(ArticleRevision::class))->toBeNull()
        ->and(Gate::getPolicyFor(Article::class))->not->toBeNull();

    finCodexGatedManager($article)->assertTableActionVisible('restore', $revision);
});

/*
 * -----------------------------------------------------------------------
 * HTML conversion.
 * -----------------------------------------------------------------------
 */

it('offers the conversion to a user the shipped policy allows', function (): void {
    finCodexGatedUser();

    $article = finCodexGatedHtmlArticle();

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->assertActionVisible('convert');
});

it('takes the conversion away from a user the policy refuses', function (): void {
    finCodexGatedUser();

    $article = finCodexGatedHtmlArticle();

    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    $page->assertActionHidden('convert');
});

it('converts nothing when a refused conversion is called anyway', function (): void {
    finCodexGatedUser();

    $article = finCodexGatedHtmlArticle();

    // Mounted while the modal was still legitimately reachable, so the press
    // below is the forged one.
    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->mountAction('convert');

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    // Straight onto the component already in memory, deliberately. A fresh
    // Livewire request would 403 on EditRecord::authorizeAccess() before any
    // action was built, and prove the resource gate rather than this one.
    $page->instance()->callMountedAction();

    expect($article->fresh()->format)->toBe(ArticleFormat::Html)
        ->and(ArticleRevision::query()->count())->toBe(0);
});

it('keeps the conversion for a host policy that only knows update', function (): void {
    finCodexGatedUser();

    $article = finCodexGatedHtmlArticle();

    finCodexGatedPolicy(FinCodexGatedStandardPolicy::class);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->assertActionVisible('convert');
});

/*
 * The format check and the ability check compose: Filament ANDs every reason
 * an action can be hidden, so a Markdown article stays without a convert
 * button however permissive the policy is.
 */
it('still hides the conversion on a Markdown article the policy allows', function (): void {
    finCodexGatedUser();

    $article = Article::factory()->public()->published()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'How users work.'])
        ->create(['slug' => 'users']);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->assertActionHidden('convert');
});

/*
 * -----------------------------------------------------------------------
 * File to database import: the two buttons, and the choke point behind them.
 * -----------------------------------------------------------------------
 */

/**
 * A docs tree with one file article whose front matter already claims the
 * admin users screen, so the coverage row for that screen is covered,
 * file-only and offers an import.
 *
 * The shared fixture docs cannot serve here: they cover that screen through a
 * HasHelp declaration in code, and a declared row is exactly the row that
 * offers no import at all.
 */
function finCodexGatedFileDocs(): void
{
    $dir = sys_get_temp_dir().'/fin-codex-gated-docs';

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
}

it('offers the file import to a user the shipped policy allows', function (): void {
    useFixtureDocs();
    finCodexGatedUser();

    Livewire::test(FileArticlesTable::class)->assertTableActionVisible('import', 'users/roles');
});

it('takes the file import away from a user the policy refuses', function (): void {
    useFixtureDocs();
    finCodexGatedUser();

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    Livewire::test(FileArticlesTable::class)->assertTableActionHidden('import', 'users/roles');
});

/*
 * The import check is class-level — the row is an array and there is no
 * article yet — so it falls back to `create`, never to `update`: Laravel drops
 * a class-string subject before calling the policy method, and a normally
 * written update($user, $article) would be an ArgumentCountError rather than
 * an answer.
 */
it('keeps the file import for a host policy that only knows create', function (): void {
    useFixtureDocs();
    finCodexGatedUser();

    finCodexGatedPolicy(FinCodexGatedStandardPolicy::class);

    Livewire::test(FileArticlesTable::class)->assertTableActionVisible('import', 'users/roles');
});

/*
 * The buttons hide for the look of the thing. This is the enforcement: a third
 * call site added next year cannot import around them, and opening a file-only
 * article for editing is an adoption too.
 */
it('refuses an adoption the policy denies, whoever is calling', function (): void {
    useFixtureDocs();
    $user = finCodexGatedUser();

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    expect(fn (): Article => app(FileArticleAdopter::class)->adopt('users/roles', $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Article::query()->count())->toBe(0);
});

it('adopts exactly as before for a user the policy allows', function (): void {
    useFixtureDocs();
    $user = finCodexGatedUser();

    $article = app(FileArticleAdopter::class)->adopt('users/roles', $user->id);

    expect($article->slug)->toBe('users/roles')
        ->and($article->created_by)->toBe($user->id)
        ->and(Article::query()->count())->toBe(1);
});

/*
 * -----------------------------------------------------------------------
 * The coverage page's gap-closing row actions.
 * -----------------------------------------------------------------------
 */

/** The admin coverage page with every row on one page. */
function finCodexGatedCoverage(): Testable
{
    return Livewire::test(AdminHelpCoverage::class)->set('tableRecordsPerPage', 'all');
}

/** The report's own key for one screen, so a fixture change cannot make a row vacuous. */
function finCodexGatedRowKey(?string $panelId, ?string $helpClass): string
{
    foreach (app(CoverageReport::class)->rows() as $row) {
        if ($row->panelId === $panelId && $row->helpClass === $helpClass) {
            return $row->key;
        }
    }

    throw new RuntimeException('No coverage row for '.($panelId ?? 'no panel').' / '.($helpClass ?? 'no class'));
}

/** The context rows of one article as "{panel|*}:{type}:{key}" strings, in order. */
function finCodexGatedContexts(Article $article): array
{
    return ArticleContext::query()
        ->where('article_id', $article->id)
        ->orderBy('sort_order')
        ->get()
        ->map(fn (ArticleContext $context): string => ($context->panel_id ?? ContextPicker::ANY_PANEL).':'.$context->type->key().':'.$context->key)
        ->all();
}

/** A public database article with one English translation and no context. */
function finCodexGatedHandbook(): Article
{
    return Article::factory()->public()
        ->withTranslation('en', ['title' => 'Handbook', 'body' => 'About the handbook.'])
        ->create(['slug' => 'handbook']);
}

it('offers both gap actions to a user the shipped policy allows', function (): void {
    finCodexGatedUser();
    forgetHelpMemo();

    $key = finCodexGatedRowKey('admin', UserResource::class);

    finCodexGatedCoverage()
        ->assertTableActionVisible('write', $key)
        ->assertTableActionVisible('attach', $key);
});

it('takes both gap actions away from a user the policy refuses', function (): void {
    finCodexGatedUser();
    forgetHelpMemo();

    $key = finCodexGatedRowKey('admin', UserResource::class);

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    finCodexGatedCoverage()
        ->assertTableActionHidden('write', $key)
        ->assertTableActionHidden('attach', $key);
});

it('offers the coverage import to a user the shipped policy allows', function (): void {
    finCodexGatedUser();
    finCodexGatedFileDocs();

    $key = finCodexGatedRowKey('admin', UserResource::class);

    finCodexGatedCoverage()->assertTableActionVisible('import', $key);
});

it('takes the coverage import away from a user the policy refuses', function (): void {
    finCodexGatedUser();
    finCodexGatedFileDocs();

    $key = finCodexGatedRowKey('admin', UserResource::class);

    finCodexGatedPolicy(DenyAllArticlePolicy::class);

    finCodexGatedCoverage()->assertTableActionHidden('import', $key);
});

/*
 * The attach button and the write it performs ask two different questions, and
 * they have to. The button can only be a class-level `create` check, because
 * the article being changed does not exist until the modal's Select comes
 * back; the write itself changes an existing article, which is `update`. A
 * host that lets this user start new articles but not touch old ones therefore
 * sees the button and gets the refusal — the same warning notification a
 * duplicate context already produces.
 */
it('shows the attach button on a create-only policy and refuses the write itself', function (): void {
    finCodexGatedUser();
    $article = finCodexGatedHandbook();
    forgetHelpMemo();

    $key = finCodexGatedRowKey('admin', UserResource::class);

    finCodexGatedPolicy(FinCodexGatedNoUpdatePolicy::class);

    finCodexGatedCoverage()
        ->assertTableActionVisible('attach', $key)
        ->callTableAction('attach', $key, ['article' => 'handbook'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('fin-codex::fin-codex.coverage.attach.duplicate'));

    expect(finCodexGatedContexts($article))->toBe([]);
});

it('attaches as before for a user the shipped policy allows', function (): void {
    finCodexGatedUser();
    $article = finCodexGatedHandbook();
    forgetHelpMemo();

    $key = finCodexGatedRowKey('admin', UserResource::class);

    finCodexGatedCoverage()
        ->callTableAction('attach', $key, ['article' => 'handbook'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('fin-codex::fin-codex.coverage.attach.attached'));

    expect(finCodexGatedContexts($article))->toBe(['admin:class:'.UserResource::class]);
});
