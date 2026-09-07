<?php

use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\RevisionsRelationManager;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\RevisionReason;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * REV-01: the article's whole history in one flat, newest-first table — when
 * it happened, in which language, who did it, why and what the title was —
 * and nothing at all while revisions are switched off in settings.
 *
 * Every table row mounts the MANAGER directly. A relation manager is lazy by
 * default, so the edit page's HTML carries a placeholder and no table markup
 * at all; the page-level rows at the bottom of this file assert presence, not
 * content.
 *
 * Revisions are seeded three at a time wherever the author column is in play:
 * Builder::hydrate() only arms preventsLazyLoading when the query returned
 * more than one row, so a single-row table would pass with a lazy author
 * relation and lie about strict models.
 */

/** A fixture user signed in on the admin guard. */
function finCodexRevisionUser(string $name = 'Reviser', string $email = 'reviser@example.com'): User
{
    return User::create(['name' => $name, 'email' => $email]);
}

/**
 * @param  list<string>  $codes
 */
function finCodexRevisionUseLanguages(array $codes, string $default = 'en'): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = $default;
    $settings->save();
}

/** An article with one complete English translation, the way the editor leaves it. */
function finCodexRevisionArticle(string $slug = 'users', string $title = 'Users'): Article
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
 * One revision, then a minute on the clock: created_at is second-precision
 * and the table sorts by it, so an ordering row cannot be a coin toss.
 */
function finCodexRevisionRow(
    Article $article,
    string $title,
    string $locale = 'en',
    ?RevisionReason $reason = null,
    ?int $userId = null,
): ArticleRevision {
    $revision = ArticleRevision::factory()->create([
        'article_id' => $article->id,
        'locale' => $locale,
        'title' => $title,
        'reason' => $reason ?? RevisionReason::Manual,
        'user_id' => $userId,
    ]);

    test()->travelTo(now()->addMinute());

    return $revision;
}

/**
 * pageClass is mandatory: RelationManager::getPageClass() returns a `string`
 * backed by a `?string` property, so a mount without it TypeErrors on the
 * first read.
 */
function finCodexRevisionManager(Article $article): Testable
{
    return Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $article,
        'pageClass' => EditArticle::class,
    ]);
}

it('renders three revisions with the author read through an eager-loaded relation', function (): void {
    enableRevisions(true);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    finCodexRevisionRow($article, 'First draft', userId: $user->id);
    finCodexRevisionRow($article, 'Second draft', userId: $user->id);
    finCodexRevisionRow($article, 'Third draft', userId: $user->id);

    finCodexRevisionManager($article)
        ->assertSee('First draft')
        ->assertSee('Second draft')
        ->assertSee('Third draft')
        ->assertSee('Reviser');
});

it('prints the unknown-author string for a revision with no user and for a deleted one', function (): void {
    enableRevisions(true);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $ghost = finCodexRevisionUser('Ghost', 'ghost@example.com');

    // The titles carry no author name, so "Ghost" in the HTML can only be
    // the author column.
    $article = finCodexRevisionArticle();
    finCodexRevisionRow($article, 'Anonymous change');
    finCodexRevisionRow($article, 'Change by the other user', userId: $ghost->id);
    finCodexRevisionRow($article, 'Named change', userId: $user->id);

    finCodexRevisionManager($article)
        ->assertSee('Ghost')
        ->assertSee(__('fin-codex::fin-codex.revisions.no_author'));

    $ghost->delete();

    finCodexRevisionManager($article)
        ->assertDontSee('Ghost')
        ->assertSee('Change by the other user')
        ->assertSee(__('fin-codex::fin-codex.revisions.no_author'));
});

it('badges the reason with the core enum label and follows the panel locale', function (): void {
    enableRevisions(true);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    finCodexRevisionRow($article, 'Written by hand', reason: RevisionReason::Manual, userId: $user->id);
    finCodexRevisionRow($article, 'Pulled from a file', reason: RevisionReason::Import, userId: $user->id);
    finCodexRevisionRow($article, 'Rewritten by a model', reason: RevisionReason::AiRewrite, userId: $user->id);

    expect(RevisionReason::Manual->label())->toBe('Manual')
        ->and(RevisionReason::Import->label())->toBe('Import');

    finCodexRevisionManager($article)
        ->assertSee(RevisionReason::Manual->label())
        ->assertSee(RevisionReason::Import->label())
        ->assertSee(RevisionReason::AiRewrite->label());

    app()->setLocale('de');

    // Read back through the enum so the row cannot hard-code a translation,
    // and prove the German words are not the English ones.
    $manual = RevisionReason::Manual->label();
    $rewrite = RevisionReason::AiRewrite->label();

    expect($manual)->not->toBe('Manual')
        ->and($rewrite)->not->toBe('AI rewrite');

    finCodexRevisionManager($article)
        ->assertSee($manual)
        ->assertSee($rewrite);
});

it('shows the flag and the code of every language in the locale column', function (): void {
    enableRevisions(true);
    finCodexRevisionUseLanguages(['en', 'de']);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    finCodexRevisionRow($article, 'English draft', userId: $user->id);
    finCodexRevisionRow($article, 'Deutscher Entwurf', 'de', userId: $user->id);
    finCodexRevisionRow($article, 'English rewrite', userId: $user->id);

    $html = finCodexRevisionManager($article)->html();

    expect($html)
        ->toContain(TranslationTabs::flag('gb').' en')
        ->toContain(TranslationTabs::flag('de').' de');
});

it('lists every language in one flat table, newest first', function (): void {
    enableRevisions(true);
    finCodexRevisionUseLanguages(['en', 'de']);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    $oldest = finCodexRevisionRow($article, 'Oldest', userId: $user->id);
    $middle = finCodexRevisionRow($article, 'Middle', 'de', userId: $user->id);
    $newest = finCodexRevisionRow($article, 'Newest', userId: $user->id);

    finCodexRevisionManager($article)
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
});

it('narrows the table to one language with the locale filter', function (): void {
    enableRevisions(true);
    finCodexRevisionUseLanguages(['en', 'de']);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    $english = finCodexRevisionRow($article, 'English draft', userId: $user->id);
    $german = finCodexRevisionRow($article, 'Deutscher Entwurf', 'de', userId: $user->id);
    $englishAgain = finCodexRevisionRow($article, 'English rewrite', userId: $user->id);

    finCodexRevisionManager($article)
        ->filterTable('locale', 'de')
        ->assertCanSeeTableRecords([$german])
        ->assertCanNotSeeTableRecords([$english, $englishAgain]);
});

it('shows its empty state for an article with no revisions', function (): void {
    enableRevisions(true);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();

    finCodexRevisionManager($article)
        ->assertSee(__('fin-codex::fin-codex.revisions.empty'));
});

it('carries the translated title', function (): void {
    enableRevisions(true);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();

    expect(RevisionsRelationManager::getTitle($article, EditArticle::class))
        ->toBe(__('fin-codex::fin-codex.revisions.title'));
});

it('gates the manager on the settings switch', function (): void {
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();

    enableRevisions(true);
    expect(RevisionsRelationManager::canViewForRecord($article, EditArticle::class))->toBeTrue();

    enableRevisions(false);
    expect(RevisionsRelationManager::canViewForRecord($article, EditArticle::class))->toBeFalse();
});

/*
 * REV-01's second half, proven on the PAGE. Membership only, never equality
 * and never a whole-page absence claim: 06-03 adds a media manager with no
 * canViewForRecord() override, and Filament's default allows when no policy
 * exists, so the real keys become ['revisions', 'media'] and ['media'] and a
 * lazy placeholder sits on the page even with revisions off.
 */

/** The relation-manager keys the edit page cached for this mount. */
function finCodexRevisionPageManagers(Testable $page): array
{
    $instance = $page->instance();

    expect($instance)->toBeInstanceOf(EditArticle::class);

    return array_keys($instance->getCachedRelationManagers());
}

it('carries the revisions manager on the edit page while revisions are on', function (): void {
    enableRevisions(true);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    finCodexRevisionRow($article, 'First draft', userId: $user->id);
    finCodexRevisionRow($article, 'Second draft', userId: $user->id);

    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);

    // The placeholder IS the proof: the manager is lazy, so the table markup
    // is not in the page HTML at all until the browser loads it.
    expect(finCodexRevisionPageManagers($page))->toContain('revisions')
        ->and($page->html())->toContain('__lazyLoad');
});

it('drops the revisions manager from the edit page while revisions are off', function (): void {
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    finCodexRevisionRow($article, 'First draft', userId: $user->id);
    finCodexRevisionRow($article, 'Second draft', userId: $user->id);

    enableRevisions(false);

    // A FRESH mount: getCachedRelationManagers() memoises on the instance, so
    // toggling the setting and re-reading the same page would answer stale.
    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);

    expect(finCodexRevisionPageManagers($page))->not->toContain('revisions')
        ->and($page->html())->not->toContain(__('fin-codex::fin-codex.revisions.title'));
});

it('403s the next round trip on a manager whose gate closed', function (): void {
    enableRevisions(true);
    $user = finCodexRevisionUser();
    $this->usesPanel('admin', $user);

    $article = finCodexRevisionArticle();
    finCodexRevisionRow($article, 'First draft', userId: $user->id);
    finCodexRevisionRow($article, 'Second draft', userId: $user->id);

    // The initial mount is allowed because CanAuthorizeAccess checks the gate
    // in a Livewire *hydrate* hook, which the first render never runs; only
    // the next request pays it.
    $manager = finCodexRevisionManager($article);

    enableRevisions(false);

    $manager->set('tableSearch', 'x')->assertForbidden();
});
