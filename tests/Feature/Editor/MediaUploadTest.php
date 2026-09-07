<?php

use FinityLabs\FinCodex\Editor\MediaRecorder;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Models\Media;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * EDIT-07: an image dropped into the Markdown body.
 *
 * Every row drives the real editor page, so the proof covers Filament's own
 * attachment pipeline as well as ours: the mime validation that runs before
 * our closure is ever called, the disk and directory read from the core's
 * config, the codex_media row with the uploader, and the article id that is
 * null on create until afterCreate() links it.
 */

/** A fixture user signed in on the panel under test. */
function finCodexUploadUser(string $name = 'Uploader'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/** A real 1x1 PNG, so Filament's mimetypes: rule sees an actual image (Pitfall 5). */
function finCodexUploadPng(string $name = 'shot.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true),
    );
}

/** An SVG: a real file, a real image to a human, and not one of the accepted types. */
function finCodexUploadSvg(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
}

/**
 * The absolute schema key of one locale's body field, resolved from the
 * schema rather than hard-coded: it is "form.{tab}.{statePath}", which is
 * not the key any other Filament API speaks (Pitfall 4).
 */
function finCodexUploadKey(Testable $component, string $code): string
{
    foreach ($component->instance()->form->getFlatFields(withHidden: true) as $field) {
        if ($field->getStatePath() === "data.translations.{$code}.body") {
            return (string) $field->getKey();
        }
    }

    return "form.{$code}.translations.{$code}.body";
}

/** Upload one file into a locale's body field and return the URL Filament hands the editor. */
function finCodexUpload(Testable $component, UploadedFile $file, string $code = 'en'): ?string
{
    $component->set("componentFileAttachments.data.translations.{$code}.body", $file);

    $url = $component->instance()->callSchemaComponentMethod(
        finCodexUploadKey($component, $code),
        'saveUploadedFileAttachmentAndGetUrl',
    );

    return is_string($url) ? $url : null;
}

/**
 * A complete create-form state.
 *
 * @return array<string, mixed>
 */
function finCodexUploadState(string $body = 'How users work.', string $slug = 'users'): array
{
    return [
        'slug' => $slug,
        'icon' => null,
        'sort_order' => 0,
        'format' => ArticleFormat::Markdown->value,
        'is_published' => true,
        'visibility' => Visibility::Public->value,
        'keywords' => [],
        'related' => [],
        'contexts' => [],
        'translations' => ['en' => ['title' => 'Users', 'excerpt' => null, 'body' => $body]],
    ];
}

function finCodexUploadSeed(string $slug = 'users', string $body = 'How users work.'): Article
{
    $article = Article::factory()->create(['slug' => $slug]);

    ArticleTranslation::factory()->create([
        'article_id' => $article->id,
        'locale' => 'en',
        'title' => ucfirst($slug),
        'body' => $body,
    ]);

    return $article;
}

beforeEach(function (): void {
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], ['en']);
    $settings->default_locale = 'en';
    $settings->save();
});

it('stores an image on the lin-codex disk and directory and records the uploader', function (): void {
    Storage::fake('public');
    $user = finCodexUploadUser();
    $this->usesPanel('admin', $user);

    $url = finCodexUpload(Livewire::test(CreateArticle::class), finCodexUploadPng());

    expect($url)->toStartWith('/storage/codex/')->toEndWith('.png');

    $media = Media::query()->sole();

    expect($media->disk)->toBe('public')
        ->and($media->path)->toStartWith('codex/')
        ->and($media->name)->toBe('shot.png')
        ->and($media->mime_type)->toBe('image/png')
        ->and($media->size)->toBeGreaterThan(0)
        ->and($media->uploaded_by)->toBe($user->id)
        ->and($media->article_id)->toBeNull()
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue()
        ->and($url)->toBe(Storage::disk('public')->url($media->path));
});

it('refuses a non-image before anything is stored', function (): void {
    Storage::fake('public');
    $this->usesPanel('admin', finCodexUploadUser());

    $url = finCodexUpload(Livewire::test(CreateArticle::class), finCodexUploadSvg());

    expect($url)->toBeNull()
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('honours a changed media disk and directory', function (): void {
    Storage::fake('media', ['url' => '/media']);
    $this->usesPanel('admin', finCodexUploadUser());

    config()->set('lin-codex.media.disk', 'media');
    config()->set('lin-codex.media.directory', 'help-images');

    $url = finCodexUpload(Livewire::test(CreateArticle::class), finCodexUploadPng());

    $media = Media::query()->sole();

    expect($media->disk)->toBe('media')
        ->and($media->path)->toStartWith('help-images/')
        ->and($url)->toContain('help-images')
        ->and(Storage::disk('media')->exists($media->path))->toBeTrue();
});

it('links the article id at upload time on the edit page', function (): void {
    Storage::fake('public');
    $user = finCodexUploadUser();
    $this->usesPanel('admin', $user);

    $article = finCodexUploadSeed();

    finCodexUpload(Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()]), finCodexUploadPng());

    $media = Media::query()->sole();

    expect($media->article_id)->toBe($article->id)
        ->and($media->uploaded_by)->toBe($user->id);
});

it('back-fills the article id after create for uploads referenced in the body', function (): void {
    Storage::fake('public');
    $this->usesPanel('admin', finCodexUploadUser());

    $component = Livewire::test(CreateArticle::class);
    $url = finCodexUpload($component, finCodexUploadPng());

    $unreferenced = Media::factory()->create();

    expect(Media::query()->whereNull('article_id')->count())->toBe(2);

    $component
        ->fillForm(finCodexUploadState('Look: ![shot]('.$url.')'))
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->where('slug', 'users')->sole();
    $used = Media::query()->where('name', 'shot.png')->sole();

    expect($used->article_id)->toBe($article->id)
        ->and($unreferenced->fresh()->article_id)->toBeNull();
});

it('links orphans on save of an edited body too', function (): void {
    Storage::fake('public');
    $this->usesPanel('admin', finCodexUploadUser());

    $article = finCodexUploadSeed();
    $orphan = Media::factory()->create();
    $url = Storage::disk($orphan->disk)->url($orphan->path);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['translations' => ['en' => ['body' => 'See ![shot]('.$url.')']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($orphan->fresh()->article_id)->toBe($article->id);
});

it('does not delete or relink rows that already belong to another article', function (): void {
    Storage::fake('public');
    $this->usesPanel('admin', finCodexUploadUser());

    $billing = finCodexUploadSeed('billing');
    $foreign = Media::factory()->forArticle($billing)->create();
    $orphan = Media::factory()->create();
    $unreferenced = Media::factory()->create();

    $users = finCodexUploadSeed('users', implode(' ', [
        '![a]('.Storage::disk($foreign->disk)->url($foreign->path).')',
        '![b]('.Storage::disk($orphan->disk)->url($orphan->path).')',
    ]));

    expect(app(MediaRecorder::class)->linkOrphans($users))->toBe(1)
        ->and($foreign->fresh()->article_id)->toBe($billing->id)
        ->and($orphan->fresh()->article_id)->toBe($users->id)
        ->and($unreferenced->fresh()->article_id)->toBeNull()
        ->and(Media::query()->count())->toBe(3);
});
