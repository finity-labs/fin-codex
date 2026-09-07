<?php

use Filament\Actions\Action;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\MediaRelationManager;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * MEDIA-01, second half: the Media tab.
 *
 * Every table row mounts the MANAGER directly, the way the revisions rows do —
 * a relation manager is lazy, so the edit page carries a placeholder and no
 * table markup at all.
 *
 * The delete has two stops and both are proved here: the modal loses its
 * submit button while a body still shows the file, and calling the action
 * anyway (a stale browser, or this test) halts without writing.
 */

/** A fixture user signed in on the admin guard. */
function finCodexMediaUser(string $name = 'Curator', string $email = 'curator@example.com'): User
{
    return User::create(['name' => $name, 'email' => $email]);
}

/** The fake media disk with a URL root, plus the core config that names it. */
function finCodexMediaDisk(): void
{
    Storage::fake('media', ['url' => '/media']);
    config()->set('lin-codex.media.disk', 'media');
}

function finCodexMediaArticle(string $slug = 'users'): Article
{
    return Article::factory()->create(['slug' => $slug]);
}

/**
 * One media row, with the file actually written to the fake disk unless the
 * caller asks for a row whose file is missing.
 *
 * @param  array<string, mixed>  $attributes
 */
function finCodexMediaRow(?Article $article, array $attributes = [], bool $withFile = true): Media
{
    $media = Media::factory()->create([
        'disk' => 'media',
        'path' => 'codex/'.($attributes['name'] ?? 'shot.png'),
        'mime_type' => 'image/png',
        'size' => 1_536,
        'article_id' => $article?->id,
        ...$attributes,
    ]);

    if ($withFile) {
        rescue(fn () => Storage::disk($media->disk)->put($media->path, 'binary'), report: false);
    }

    return $media;
}

function finCodexMediaBody(Article $article, string $body, string $locale = 'en'): ArticleTranslation
{
    return ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => $locale,
        'title' => mb_strtoupper($locale).' title',
        'body' => $body,
    ]);
}

/** pageClass is mandatory: getPageClass() returns a string backed by a ?string. */
function finCodexMediaManager(Article $article): Testable
{
    return Livewire::test(MediaRelationManager::class, [
        'ownerRecord' => $article,
        'pageClass' => EditArticle::class,
    ]);
}

/**
 * Mount the delete action on one row and hand back the mounted action.
 *
 * Mount ONCE per component: a second mountTableAction() while an action is
 * mounted is read as a nested action and throws, and so does callTableAction()
 * afterwards. Unmount before calling.
 */
function finCodexMediaDeleteAction(Testable $component, Media $media): Action
{
    $component->mountTableAction('delete', $media);

    $action = $component->instance()->getMountedAction();

    expect($action)->toBeInstanceOf(Action::class);

    return $action;
}

/** The rendered body of a mounted delete modal. */
function finCodexMediaDeleteModal(Action $action): string
{
    $content = $action->getModalContent();

    expect($content)->not->toBeNull();

    return $content->toHtml();
}

it('lists this article uploads and nothing else', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    $other = finCodexMediaArticle('roles');

    $first = finCodexMediaRow($article, ['name' => 'first.png']);
    $second = finCodexMediaRow($article, ['name' => 'second.png']);
    $foreign = finCodexMediaRow($other, ['name' => 'foreign.png']);
    // article_id null: the file's article was deleted. Out of scope by
    // construction, because the manager hangs off Article::media().
    $orphan = finCodexMediaRow(null, ['name' => 'orphan.png']);

    finCodexMediaManager($article)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$foreign, $orphan]);
});

it('prints a readable size, the mime type and the uploader', function (): void {
    finCodexMediaDisk();
    $user = finCodexMediaUser();
    $this->usesPanel('admin', $user);

    $article = finCodexMediaArticle();
    finCodexMediaRow($article, ['name' => 'shot.png', 'size' => 1_536, 'uploaded_by' => $user->id]);
    finCodexMediaRow($article, ['name' => 'anon.png', 'uploaded_by' => null]);

    finCodexMediaManager($article)
        ->assertSee('shot.png')
        ->assertSee('1.5 KB')
        ->assertDontSee('1536')
        ->assertSee('image/png')
        ->assertSee('Curator')
        ->assertSee(__('fin-codex::fin-codex.media.no_uploader'));
});

it('paints a thumbnail for an image and a placeholder for everything else', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    finCodexMediaRow($article, ['name' => 'shot.png']);
    finCodexMediaRow($article, ['name' => 'manual.pdf', 'mime_type' => 'application/pdf']);
    // An image row on a disk the host has removed from filesystems.disks. This
    // is why the column is a ViewColumn over a rescued URL and not an
    // ImageColumn, which would call Storage::disk() unrescued once per row.
    finCodexMediaRow($article, ['name' => 'lost.png', 'disk' => 'gone'], withFile: false);

    $html = finCodexMediaManager($article)->html();

    expect($html)
        ->toContain('data-fin-codex-media-thumb')
        ->toContain('<img src="/media/codex/shot.png"')
        ->and(substr_count($html, (string) __('fin-codex::fin-codex.media.no_preview')))->toBe(2);
});

it('offers no upload action on the media table', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    finCodexMediaRow($article, ['name' => 'shot.png']);

    // Files arrive through the Markdown editor, which writes the reference in
    // the same breath. A file uploaded here would be one nothing points at.
    expect(finCodexMediaManager($article)->instance()->getTable()->getHeaderActions())->toBe([]);
});

it('refuses to delete a file a body still shows, and names it', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    $media = finCodexMediaRow($article, ['name' => 'shot.png']);

    // The reference lives in a DIFFERENT article, in a different language.
    $quoter = finCodexMediaArticle('roles');
    finCodexMediaBody($quoter, 'Siehe ![shot](/media/codex/shot.png)', 'de');

    $component = finCodexMediaManager($article);
    $action = finCodexMediaDeleteAction($component, $media);

    expect(finCodexMediaDeleteModal($action))
        ->toContain('data-fin-codex-media-in-use')
        ->toContain('data-fin-codex-media-ref="roles:de"')
        ->toContain(__('fin-codex::fin-codex.media.delete.in_use_row', ['slug' => 'roles', 'locale' => 'de']))
        ->toContain((string) __('fin-codex::fin-codex.media.delete.in_use_hint'))
        ->and($action->getModalSubmitAction())->toBeNull();

    // The submit button is already gone; this is the second stop, the one a
    // stale browser hits. Halt is caught by InteractsWithActions, so the call
    // simply does nothing.
    $component->unmountTableAction();
    $component->callTableAction('delete', $media);

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
    Storage::disk('media')->assertExists($media->path);
});

it('deletes an unreferenced file from the database and the disk', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    $media = finCodexMediaRow($article, ['name' => 'shot.png']);
    finCodexMediaBody($article, 'How users work, with no pictures at all.');

    $component = finCodexMediaManager($article);
    $action = finCodexMediaDeleteAction($component, $media);

    expect(finCodexMediaDeleteModal($action))
        ->toContain((string) __('fin-codex::fin-codex.media.delete.free', ['disk' => 'media']))
        ->and($action->getModalSubmitAction())->not->toBeNull();

    $component->unmountTableAction();
    $component->callTableAction('delete', $media);

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
    Storage::disk('media')->assertMissing('codex/shot.png');
});

it('deletes cleanly when the file is already gone from the disk', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    $media = finCodexMediaRow($article, ['name' => 'shot.png'], withFile: false);

    Storage::disk('media')->assertMissing($media->path);

    finCodexMediaManager($article)->callTableAction('delete', $media);

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
});

it('deletes cleanly when the disk is no longer in the config', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    $media = finCodexMediaRow($article, ['name' => 'lost.png', 'disk' => 'gone'], withFile: false);

    // Storage::disk('gone') throws, and so does url() on it. The row is the
    // record; the file is a side effect the delete cannot depend on.
    finCodexMediaManager($article)->callTableAction('delete', $media);

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
});

it('leaves the article other files alone', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    $doomed = finCodexMediaRow($article, ['name' => 'doomed.png']);
    $kept = finCodexMediaRow($article, ['name' => 'kept.png']);

    finCodexMediaManager($article)->callTableAction('delete', $doomed);

    expect(Media::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and(Media::query()->whereKey($kept->id)->exists())->toBeTrue();

    Storage::disk('media')->assertMissing($doomed->path);
    Storage::disk('media')->assertExists($kept->path);
});

/*
 * The page-level rows: the two managers side by side on the edit page.
 *
 * Mount ONE page per row. getCachedRelationManagers() memoises on the
 * instance and activeRelationManager carries #[Url(as: 'relation')], which
 * reads the test request's query string, so two mounts in one row can leak
 * state between them.
 */

/** The wire:click handler Filament renders for one relation-manager tab. */
function finCodexMediaTabHandler(string $key): string
{
    return sprintf('wire:click="$set(\'activeRelationManager\', \'%s\')"', $key);
}

/**
 * The relation-manager tab strip only: from its container id to the end of
 * the nav, so a title found here is a TAB LABEL and not a word from the
 * rendered panel below it.
 *
 * role="tablist" alone is no anchor — the form's own language tabs render one
 * on every edit and create page, so the count is 1 with no relation manager
 * tab strip at all and 2 with one.
 */
function finCodexMediaTabStrip(string $html): string
{
    $start = strpos($html, 'id="content.relationManagerTabs"');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, '</nav>', $start);

    return $end === false ? '' : substr($html, $start, $end - $start);
}

it('shows a two-tab strip keyed revisions and media', function (): void {
    finCodexMediaDisk();
    enableRevisions(true);
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    finCodexMediaBody($article, 'How users work.');
    finCodexMediaRow($article, ['name' => 'shot.png']);

    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);
    $html = $page->html();

    // The two string keys are OUR contract: they are the ?relation= deep-link
    // values and the wire:click payloads, so the literals are asserted.
    expect(array_keys($page->instance()->getCachedRelationManagers()))->toBe(['revisions', 'media'])
        ->and(finCodexMediaTabStrip($html))->not->toBe('')
        ->and(str_contains($html, finCodexMediaTabHandler('revisions')))->toBeTrue()
        ->and(str_contains($html, finCodexMediaTabHandler('media')))->toBeTrue()
        ->and(substr_count($html, 'role="tablist"'))->toBe(2);
});

it('labels both tabs with their translated titles', function (): void {
    finCodexMediaDisk();
    enableRevisions(true);
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    finCodexMediaBody($article, 'How users work.');
    finCodexMediaRow($article, ['name' => 'shot.png']);

    $strip = finCodexMediaTabStrip(
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])->html(),
    );

    expect($strip)
        ->toContain((string) __('fin-codex::fin-codex.revisions.title'))
        ->toContain((string) __('fin-codex::fin-codex.media.title'));
});

it('renders only the active tab panel', function (): void {
    finCodexMediaDisk();
    enableRevisions(true);
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    finCodexMediaBody($article, 'How users work.');
    finCodexMediaRow($article, ['name' => 'shot.png']);

    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);
    $page->assertSet('activeRelationManager', 'revisions');

    // Same active-tab-only branch 05-06 and 05-07 pinned for the language
    // tabs: the other tab is a button with no panel markup at all.
    expect($page->html())
        ->toContain('content.relationManagerTabs.revisions')
        ->not->toContain('content.relationManagerTabs.media');

    $page->set('activeRelationManager', 'media')->assertSet('activeRelationManager', 'media');

    expect($page->html())
        ->toContain('content.relationManagerTabs.media')
        ->not->toContain('content.relationManagerTabs.revisions');
});

it('renders the media manager bare when revisions are switched off', function (): void {
    finCodexMediaDisk();
    $this->usesPanel('admin', finCodexMediaUser());

    $article = finCodexMediaArticle();
    finCodexMediaBody($article, 'How users work.');
    finCodexMediaRow($article, ['name' => 'shot.png']);

    enableRevisions(false);

    // A FRESH mount: getCachedRelationManagers() memoises on the instance.
    $page = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]);
    $html = $page->html();

    // One visible manager renders with no tab strip at all, which is the
    // concrete reason 06-04's toggle helper text says the Revisions tab
    // disappears. The one remaining tablist is the form's language tabs.
    expect(array_keys($page->instance()->getCachedRelationManagers()))->toBe(['media'])
        ->and(finCodexMediaTabStrip($html))->toBe('')
        ->and($html)->not->toContain('content.relationManagerTabs')
        ->and($html)->not->toContain((string) __('fin-codex::fin-codex.revisions.title'))
        ->and(substr_count($html, 'role="tablist"'))->toBe(1);
});

it('shows neither manager on the create page', function (): void {
    finCodexMediaDisk();
    enableRevisions(true);
    $this->usesPanel('admin', finCodexMediaUser());

    // A regression guard, not a feature: CreateRecord does not use
    // HasRelationManagers at all.
    $html = Livewire::test(CreateArticle::class)->html();

    expect(finCodexMediaTabStrip($html))->toBe('')
        ->and($html)->not->toContain('content.relationManagerTabs')
        ->and($html)->not->toContain((string) __('fin-codex::fin-codex.revisions.title'))
        ->and($html)->not->toContain((string) __('fin-codex::fin-codex.media.title'));
});
