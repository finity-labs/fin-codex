<?php

use FinityLabs\FinCodex\Editor\MediaPickerTable;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\FinModalTableSelect\Components\ModalTableSelect;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Models\Media;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Reusing an upload: the "Insert file" picker under each Markdown body.
 *
 * An upload belongs to the article it was dropped into, but any article may
 * use it. The picker lists every file, from every article, and appends the
 * chosen one's Markdown to the body — an image as an image, a document as
 * a link; it carries no state of its own into the save.
 */

function finCodexInsertUser(): User
{
    return User::create(['name' => 'Inserter', 'email' => 'inserter@example.com']);
}

/** The fake media disk with a URL root, plus the core config that names it. */
function finCodexInsertDisk(): void
{
    Storage::fake('media', ['url' => '/media']);
    config()->set('lin-codex.media.disk', 'media');
}

function finCodexInsertArticle(string $slug = 'users', string $body = 'How users work.', ArticleFormat $format = ArticleFormat::Markdown): Article
{
    $article = Article::factory()->create(['slug' => $slug, 'format' => $format]);

    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => ucfirst($slug),
        'body' => $body,
    ]);

    return $article;
}

/** @param  array<string, mixed>  $attributes */
function finCodexInsertMedia(?Article $article, array $attributes = []): Media
{
    return Media::factory()->create([
        'disk' => 'media',
        'path' => 'codex/2026/09/'.($attributes['name'] ?? 'shot.png'),
        'mime_type' => 'image/png',
        'size' => 2_048,
        'article_id' => $article?->id,
        ...$attributes,
    ]);
}

function finCodexInsertPicker(Testable $component, string $code = 'en'): ?ModalTableSelect
{
    $found = $component->instance()->form->getComponent(
        fn (mixed $schemaComponent): bool => $schemaComponent instanceof ModalTableSelect
            && $schemaComponent->getName() === "insert_file_{$code}",
        withHidden: true,
    );

    return $found instanceof ModalTableSelect ? $found : null;
}

beforeEach(function (): void {
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], ['en']);
    $settings->default_locale = 'en';
    $settings->save();
});

it('appends the chosen image to the body and clears the picker, and the save keeps it', function (): void {
    finCodexInsertDisk();
    $this->usesPanel('admin', finCodexInsertUser());

    $article = finCodexInsertArticle();
    $other = finCodexInsertArticle('billing', 'Billing.');
    $media = finCodexInsertMedia($other, ['name' => 'invoice [draft].png', 'path' => 'codex/2026/09/invoice.png']);

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->set('data.insert_file_en', (string) $media->id);

    // Alt text is the file name without its extension and without the
    // brackets Markdown would misread.
    expect($component->get('data.translations.en.body'))->toBe("How users work.\n\n![invoice draft](/media/codex/2026/09/invoice.png)\n")
        ->and($component->get('data.insert_file_en'))->toBeNull();

    $component->call('save')->assertHasNoFormErrors();

    expect($article->translations()->where('locale', 'en')->sole()->body)
        ->toContain('![invoice draft](/media/codex/2026/09/invoice.png)');
});

it('starts an empty body with the image alone', function (): void {
    finCodexInsertDisk();
    $this->usesPanel('admin', finCodexInsertUser());

    $media = finCodexInsertMedia(null, ['name' => 'shot.png']);

    $component = Livewire::test(CreateArticle::class)
        ->set('data.insert_file_en', (string) $media->id);

    expect($component->get('data.translations.en.body'))->toBe("![shot](/media/codex/2026/09/shot.png)\n");
});

it('links a document by its file name instead of embedding it', function (): void {
    finCodexInsertDisk();
    $this->usesPanel('admin', finCodexInsertUser());

    $article = finCodexInsertArticle();
    $guide = finCodexInsertMedia($article, ['name' => 'user [guide].pdf', 'path' => 'codex/2026/09/guide.pdf', 'mime_type' => 'application/pdf']);

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->set('data.insert_file_en', (string) $guide->id);

    expect($component->get('data.translations.en.body'))->toBe("How users work.\n\n[user guide.pdf](/media/codex/2026/09/guide.pdf)\n");
});

it('offers every file from every article and carries nothing into the save', function (): void {
    finCodexInsertDisk();
    $this->usesPanel('admin', finCodexInsertUser());

    $article = finCodexInsertArticle();
    $other = finCodexInsertArticle('billing', 'Billing.');
    $own = finCodexInsertMedia($article, ['name' => 'own.png']);
    $theirs = finCodexInsertMedia($other, ['name' => 'theirs.png']);
    $orphan = finCodexInsertMedia(null, ['name' => 'orphan.png']);
    $guide = finCodexInsertMedia($article, ['name' => 'guide.pdf', 'mime_type' => 'application/pdf']);

    $picker = finCodexInsertPicker(Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]));

    expect($picker)->toBeInstanceOf(ModalTableSelect::class);

    /** @var ModalTableSelect $picker */
    expect($picker->getTableConfiguration())->toBe(MediaPickerTable::class)
        ->and($picker->isDehydrated())->toBeFalse()
        ->and($picker->getStandaloneQuery()->pluck('id')->sort()->values()->all())->toBe([$own->id, $theirs->id, $orphan->id, $guide->id]);
});

it('leaves the body alone when the chosen row is a file no disk can serve', function (): void {
    finCodexInsertDisk();
    $this->usesPanel('admin', finCodexInsertUser());

    $article = finCodexInsertArticle();
    $gone = finCodexInsertMedia($article, ['name' => 'lost.png', 'disk' => 'no-such-disk']);

    $component = Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->set('data.insert_file_en', (string) $gone->id);

    expect($component->get('data.translations.en.body'))->toBe('How users work.')
        ->and($component->get('data.insert_file_en'))->toBeNull();
});

it('offers no picker on an HTML article, whose body is read-only', function (): void {
    finCodexInsertDisk();
    $this->usesPanel('admin', finCodexInsertUser());

    $article = finCodexInsertArticle('legacy', '<p>Legacy.</p>', ArticleFormat::Html);

    expect(finCodexInsertPicker(Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])))->toBeNull();
});
