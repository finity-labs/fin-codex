<?php

use Filament\Actions\Action;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\RevisionsRelationManager;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\RevisionReason;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * REV-02: reading a past version the way a reader would see it, and putting
 * it back with one confirmed click that is itself undoable.
 *
 * Every row mounts the relation manager directly — it is lazy, so the edit
 * page carries a placeholder and no table markup at all — and drives the row
 * actions with mountTableAction()/callTableAction(), which hand Filament the
 * record key the browser would send.
 *
 * The restore rows never assert against a write of ours: RevisionManager::
 * restore() is the only writer here, and what is proven is that fin-codex
 * hands it the right revision and the right user.
 */

/** A fixture user signed in on the panel guard. */
function finCodexRestoreUser(string $name = 'Restorer', string $email = 'restorer@example.com'): User
{
    return User::create(['name' => $name, 'email' => $email]);
}

/** An article with one complete English translation, the way the editor leaves it. */
function finCodexRestoreArticle(string $slug = 'users', string $title = 'Users'): Article
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
 * pageClass is mandatory: RelationManager::getPageClass() returns a `string`
 * backed by a `?string` property, so a mount without it TypeErrors.
 */
function finCodexRestoreManager(Article $article): Testable
{
    return Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $article,
        'pageClass' => EditArticle::class,
    ]);
}

/** A body using the three lin-codex syntaxes the panel's Markdown editor knows nothing about. */
function finCodexRestoreBody(): string
{
    return "> [!WARNING] Before you delete\n> Their articles stay published.\n\n"
        .":::steps\n1. Open the users page\n\n2. Click **Add**\n:::\n\n"
        .'![Users list](/storage/codex/users.png "The users page")';
}

/** Mount a row action and hand back the mounted instance. */
function finCodexRestoreAction(Testable $component, string $name, ArticleRevision $revision): Action
{
    $component->mountTableAction($name, $revision);

    $action = $component->instance()->getMountedAction();

    expect($action)->toBeInstanceOf(Action::class);

    return $action;
}

/**
 * Rewrite a translation the way the editor does, which is what makes the
 * core record a revision of the text that was there before.
 */
function finCodexRestoreEdit(Article $article, string $title, string $body, string $locale = 'en'): ArticleTranslation
{
    $translation = ArticleTranslation::query()
        ->where('article_id', $article->id)
        ->where('locale', $locale)
        ->sole();

    $translation->fill(['title' => $title, 'body' => $body])->save();

    return $translation;
}

/** The one revision this article has in the given locale, newest first. */
function finCodexRestoreLatest(Article $article, ?RevisionReason $reason = null): ArticleRevision
{
    return ArticleRevision::query()
        ->where('article_id', $article->id)
        ->when($reason !== null, fn ($query) => $query->where('reason', $reason))
        ->orderByDesc('id')
        ->sole();
}

/** The rendered modal body of the preview action for one revision. */
function finCodexRestoreModal(Testable $component, ArticleRevision $revision): string
{
    $content = finCodexRestoreAction($component, 'preview', $revision)->getModalContent();

    expect($content)->not->toBeNull();

    return $content->toHtml();
}

it('renders a revision through the core renderer, not through a Markdown parser', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle();
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'How users work',
        'body' => finCodexRestoreBody(),
        'user_id' => $user->id,
    ]);

    $html = finCodexRestoreModal(finCodexRestoreManager($article), $revision);

    expect($html)
        ->toContain('data-fin-codex-revision-preview')
        ->toContain('codex-root')
        ->toContain('How users work')
        ->toContain('codex-callout codex-callout--warning')
        ->toContain('Before you delete')
        ->toContain('<ol class="codex-steps">')
        ->toContain('codex-step__number')
        ->toContain('<figure class="codex-figure">')
        ->toContain('<figcaption>The users page</figcaption>')
        ->not->toContain('[!WARNING]')
        ->not->toContain(':::steps');
});

it('names the language and the time in the preview heading', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle();
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'de',
        'title' => 'Benutzer',
        'body' => 'Wie Benutzer funktionieren.',
        'user_id' => $user->id,
    ]);

    $heading = finCodexRestoreAction(finCodexRestoreManager($article), 'preview', $revision)->getModalHeading();

    expect((string) $heading)
        ->toContain('DE')
        ->toContain($revision->created_at->toDayDateTimeString());
});

it('binds the theme wrapper like the drawer', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle();
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'Users',
        'body' => 'How users work.',
        'user_id' => $user->id,
    ]);

    expect(finCodexRestoreModal(finCodexRestoreManager($article), $revision))
        ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"')
        ->not->toContain('class="light"');

    // The portal panel has darkMode(false), so the wrapper is light before
    // Alpine ever runs.
    $this->usesPanel('portal', finCodexRestoreUser('Portal', 'portal@example.com'));

    expect(finCodexRestoreModal(finCodexRestoreManager($article), $revision))
        ->toContain('class="light"')
        ->toContain('x-bind:class="{ light: $store.theme === \'light\' }"');
});

it('renders an HTML revision through the sanitizer even when the article is now Markdown', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    // The article is Markdown today; the revision remembers the format its
    // body was written in, and that is the one the preview must use.
    $article = finCodexRestoreArticle();
    expect($article->format)->toBe(ArticleFormat::Markdown);

    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'Legacy',
        'body' => '<h2>Hi</h2><script>alert(1)</script><p>x</p>',
        'format' => ArticleFormat::Html,
        'user_id' => $user->id,
    ]);

    expect(finCodexRestoreModal(finCodexRestoreManager($article), $revision))
        ->toContain('<h2 id="hi"')
        ->toContain('Hi')
        ->not->toContain('<script')
        ->not->toContain('alert(1)');
});

it('writes nothing when a revision is previewed', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle();
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'An older title',
        'body' => 'An older body.',
        'user_id' => $user->id,
    ]);

    finCodexRestoreModal(finCodexRestoreManager($article), $revision);

    $translation = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->sole();

    expect(ArticleRevision::query()->count())->toBe(1)
        ->and($translation->title)->toBe('Users')
        ->and($translation->body)->toBe('Users body');
});

it('confirms the restore with a modal naming the language and the timestamp', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle();
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'de',
        'title' => 'Benutzer',
        'body' => 'Wie Benutzer funktionieren.',
        'user_id' => $user->id,
    ]);

    $action = finCodexRestoreAction(finCodexRestoreManager($article), 'restore', $revision);

    expect($action->isConfirmationRequired())->toBeTrue()
        ->and((string) $action->getModalHeading())
        ->toContain('DE')
        ->toContain($revision->created_at->toDayDateTimeString())
        ->and((string) $action->getModalDescription())
        ->toBe(__('fin-codex::fin-codex.revisions.restore.description', ['locale' => 'DE']))
        ->toContain('DE')
        ->not->toContain(':locale');
});

it('puts the title and the body of a revision back', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle('users', 'v1');
    finCodexRestoreEdit($article, 'v2', 'v2 body');

    // The edit is what recorded the v1 revision — nothing here hand-writes one.
    $revision = finCodexRestoreLatest($article);
    expect($revision->title)->toBe('v1');

    finCodexRestoreManager($article)->callTableAction('restore', $revision);

    $translation = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->sole();

    expect($translation->title)->toBe('v1')
        ->and($translation->body)->toBe('v1 body');
});

it('reloads the edit page after a restore, so the form holds the restored text', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle('users', 'v1');
    finCodexRestoreEdit($article, 'v2', 'v2 body');

    finCodexRestoreManager($article)
        ->callTableAction('restore', finCodexRestoreLatest($article))
        ->assertRedirect(ArticleResource::getUrl('edit', ['record' => $article]));
});

it('snapshots the current text first, with the Restore reason and the panel user', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle('users', 'v1');
    finCodexRestoreEdit($article, 'v2', 'v2 body');

    $revision = finCodexRestoreLatest($article);
    $before = ArticleRevision::query()->count();

    finCodexRestoreManager($article)->callTableAction('restore', $revision);

    $snapshot = finCodexRestoreLatest($article, RevisionReason::Restore);

    expect(ArticleRevision::query()->count())->toBe($before + 1)
        ->and($snapshot->reason)->toBe(RevisionReason::Restore)
        ->and($snapshot->user_id)->toBe($user->id)
        ->and($snapshot->locale)->toBe('en')
        ->and($snapshot->title)->toBe('v2')
        ->and($snapshot->body)->toBe('v2 body');
});

it('restores the restore, which is what the confirmation promises', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle('users', 'v1');
    finCodexRestoreEdit($article, 'v2', 'v2 body');

    finCodexRestoreManager($article)->callTableAction('restore', finCodexRestoreLatest($article));

    // The snapshot the restore just wrote holds v2; restoring it is the undo.
    finCodexRestoreManager($article)->callTableAction('restore', finCodexRestoreLatest($article, RevisionReason::Restore));

    $translation = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->sole();

    expect($translation->title)->toBe('v2')
        ->and($translation->body)->toBe('v2 body');
});

it('recreates a translation that was deleted since, with nothing to snapshot', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle();
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'de',
        'title' => 'Benutzer',
        'body' => 'Wie Benutzer funktionieren.',
        'user_id' => $user->id,
    ]);

    $before = ArticleRevision::query()->count();

    finCodexRestoreManager($article)->callTableAction('restore', $revision);

    $german = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'de')->sole();

    expect($german->title)->toBe('Benutzer')
        ->and($german->body)->toBe('Wie Benutzer funktionieren.')
        ->and(ArticleRevision::query()->count())->toBe($before);
});

it('leaves search_text current on the restored translation', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle('users', 'v1');
    finCodexRestoreEdit($article, 'v2', 'Something else entirely.');

    finCodexRestoreManager($article)->callTableAction('restore', finCodexRestoreLatest($article));

    $translation = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->sole();

    expect($translation->search_text)->toContain('v1 body')
        ->and($translation->search_text)->not->toContain('Something else entirely');
});

it('follows the revision format when it differs from the article', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRestoreArticle();
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => 'Legacy',
        'body' => '<h2>Hi</h2>',
        'format' => ArticleFormat::Html,
        'user_id' => $user->id,
    ]);

    finCodexRestoreManager($article)->callTableAction('restore', $revision);

    expect($article->fresh()->format)->toBe(ArticleFormat::Html);
});

it('restores the middle row of a table with several revisions', function (): void {
    enableRevisions(true);
    $user = finCodexRestoreUser();
    $this->usesPanel('admin', $user);

    // Three rows arm preventsLazyLoading on a collection hydrate; the action's
    // record is re-resolved with a single-row find(), and setRelation('article')
    // removes the last lazy-load risk from the core's first line.
    $article = finCodexRestoreArticle('users', 'v1');
    finCodexRestoreEdit($article, 'v2', 'v2 body');
    finCodexRestoreEdit($article, 'v3', 'v3 body');
    finCodexRestoreEdit($article, 'v4', 'v4 body');

    $middle = ArticleRevision::query()->where('article_id', $article->id)->where('title', 'v2')->sole();

    finCodexRestoreManager($article)
        ->assertCanSeeTableRecords([$middle])
        ->callTableAction('restore', $middle)
        ->assertHasNoActionErrors();

    $translation = ArticleTranslation::query()->where('article_id', $article->id)->where('locale', 'en')->sole();

    expect($translation->title)->toBe('v2')
        ->and($translation->body)->toBe('v2 body');
});

/*
 * Attribution and authorization answer to different guards, and this row is
 * where they part company. Phase 8 gates the restore on the article's `restore`
 * ability, which the Gate reads off the application's default guard, while the
 * snapshot's author is the PANEL's user — null here, because the portal panel
 * runs on `web` and nobody is signed in there. A row with nobody signed in
 * anywhere no longer restores at all, which is the point of the gate.
 */
it('attributes the snapshot to nobody when the panel has no user of its own', function (): void {
    enableRevisions(true);
    $this->usesPanel('portal');
    $this->actingAs(finCodexRestoreUser('Elsewhere', 'elsewhere@example.com'), 'staff');

    $article = finCodexRestoreArticle('users', 'v1');
    finCodexRestoreEdit($article, 'v2', 'v2 body');

    finCodexRestoreManager($article)->callTableAction('restore', finCodexRestoreLatest($article));

    $snapshot = finCodexRestoreLatest($article, RevisionReason::Restore);

    expect($snapshot->user_id)->toBeNull()
        ->and($snapshot->title)->toBe('v2');
});
