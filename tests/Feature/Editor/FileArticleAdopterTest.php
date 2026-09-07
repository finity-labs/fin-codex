<?php

use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\FileArticleAdopter;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Sources\FilesystemSource;

/*
 * EDIT-02's write half: adopting one file article into the database.
 *
 * The adopter owns no writing of its own — lin-codex's ArticleImporter is
 * the only thing that creates the row, with the panel user's id on
 * created_by/updated_by and on the revisions it attributes. These rows prove
 * the three things the "Import and edit" action depends on: one slug is
 * imported and its neighbours are left as files, a second call finds the
 * existing row instead of writing a second one, and a failure is raised
 * rather than swallowed.
 *
 * enableRevisions() lives in tests/Harness/ModelHooksTest.php, so the
 * focused command is `vendor/bin/pest tests/Harness tests/Feature/Editor`.
 *
 * Since Phase 8 the adopter also enforces the `import` ability itself, so every
 * row here signs its user in. That is the fix a real caller needs too, not a
 * loosened gate: adoption is a write, and nobody performs it anonymously. The
 * refusal has rows of its own in tests/Feature/Auth/ActionAuthorizationTest.php.
 */

function finCodexAdopt(): FileArticleAdopter
{
    return app(FileArticleAdopter::class);
}

/** A fixture user, signed in on the default guard so the import gate answers. */
function finCodexAdoptUser(): User
{
    $user = User::create(['name' => 'Adopter', 'email' => 'adopter@example.com']);

    test()->actingAs($user);

    return $user;
}

it('imports one slug with the user and leaves the other files alone', function (): void {
    useFixtureDocs();
    $user = finCodexAdoptUser();

    $article = finCodexAdopt()->adopt('users/roles', $user->id);

    expect($article->slug)->toBe('users/roles')
        ->and($article->created_by)->toBe($user->id)
        ->and($article->updated_by)->toBe($user->id)
        ->and($article->source_path)->toBe('en/users/roles.md')
        ->and($article->visibility)->toBe(Visibility::Public)
        // Pitfall 7: "users" is still a file, so the child lands with no
        // parent_id. The tree and the gate are slug-derived, and lin-codex's
        // relinkChildren() fills the column in when "users" is imported.
        ->and($article->parent_id)->toBeNull()
        // intro and users stayed files: only the asked-for slug was imported.
        ->and(Article::query()->count())->toBe(1);

    $translations = ArticleTranslation::query()->where('article_id', $article->id)->get()->keyBy('locale');

    expect($translations)->toHaveCount(2)
        ->and($translations['en']->title)->toBe('Roles')
        ->and($translations['de']->title)->toBe('Rollen')
        ->and($translations['en']->search_text)->not->toBeNull()
        ->and($translations['de']->search_text)->not->toBeNull();
});

it('returns the existing row on a second call and creates nothing', function (): void {
    useFixtureDocs();
    $user = finCodexAdoptUser();

    $first = finCodexAdopt()->adopt('users/roles', $user->id);
    $stamp = $first->updated_at;

    $this->travel(5)->seconds();

    $second = finCodexAdopt()->adopt('users/roles', $user->id);

    expect($second->id)->toBe($first->id)
        ->and($second->updated_at?->equalTo($stamp))->toBeTrue()
        ->and(Article::query()->count())->toBe(1)
        ->and(ArticleTranslation::query()->count())->toBe(2);
});

it('records no revision when the import creates the row', function (): void {
    useFixtureDocs();
    enableRevisions(true);
    $user = finCodexAdoptUser();

    finCodexAdopt()->adopt('users/roles', $user->id);

    // A new translation records nothing, and the adopter never forces, so a
    // second call writes nothing either: no article ever gets an Import
    // revision through this path.
    expect(ArticleRevision::query()->count())->toBe(0);

    finCodexAdopt()->adopt('users/roles', $user->id);

    expect(ArticleRevision::query()->count())->toBe(0);
});

it('serves the database body afterwards while the file source still has the file', function (): void {
    useFixtureDocs();
    $user = finCodexAdoptUser();

    $article = finCodexAdopt()->adopt('users/roles', $user->id);

    app(ArticleWriter::class)->update($article, [
        'translations' => ['en' => ['title' => 'Roles', 'body' => 'DB body']],
    ], $user->id);

    forgetHelpMemo();

    $composite = app(ContentSource::class)->findBySlug('users/roles');
    $file = app(FilesystemSource::class)->findBySlug('users/roles');

    expect($composite?->id)->toBe($article->id)
        ->and($composite?->translation('en')?->body)->toBe('DB body')
        ->and($file?->id)->toBeNull()
        ->and($file?->translation('en')?->body)->toContain('Roles are assigned per user.');
});

it('throws when no file carries the slug', function (): void {
    useFixtureDocs();
    $user = finCodexAdoptUser();

    // The importer skips an unknown slug silently (nothing in the file set
    // matches --only), so the adopter's "no row afterwards" branch is what
    // reports it.
    $message = null;

    try {
        finCodexAdopt()->adopt('nope', $user->id);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('nope')
        ->and(Article::query()->count())->toBe(0);
});

it('throws with the importer failures when the write fails', function (): void {
    useFixtureDocs();
    finCodexAdoptUser();

    // codex_articles.created_by is a foreign key to users, so a user id
    // nobody owns makes the importer's per-article transaction roll back and
    // report the failure for every locale of the slug. The signed-in user is
    // the one the gate asks about; 424242 is only the attribution.
    $message = null;

    try {
        finCodexAdopt()->adopt('users/roles', 424242);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('en:users/roles')
        ->and($message)->toContain('de:users/roles')
        ->and(strtolower((string) $message))->toContain('constraint')
        ->and(Article::query()->count())->toBe(0);
});
