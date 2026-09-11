<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Feature\Editor;

use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\MediaRecorder;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Tests\Fixtures\UuidUser;
use FinityLabs\FinCodex\Tests\UuidUserTestCase;
use FinityLabs\FinSupport\Panel\PanelUser;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\Media;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The editor on a host whose user model uses HasUuids.
 *
 * Every author column lin-codex ships is sized from that model, so the panel
 * user's UUID is what the writer, the media recorder and the revision
 * manager store — the pages used to record nobody, because the id was
 * narrowed to ?int on the way in.
 */
class UuidUserKeyTest extends UuidUserTestCase
{
    protected UuidUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(CodexSettings::class);
        $settings->languages = array_map([CodexSettings::class, 'languageEntry'], ['en']);
        $settings->default_locale = 'en';
        $settings->revisions_enabled = true;
        $settings->save();

        $this->user = UuidUser::create(['name' => 'Editor', 'email' => 'editor@example.com']);
        $this->usesPanel('admin', $this->user);
    }

    public function test_the_author_columns_are_strings_on_a_uuid_keyed_host(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('codex_articles', 'created_by'));
        $this->assertSame('varchar', Schema::getColumnType('codex_articles', 'updated_by'));
        $this->assertSame('varchar', Schema::getColumnType('codex_article_revisions', 'user_id'));
        $this->assertSame('varchar', Schema::getColumnType('codex_media', 'uploaded_by'));
    }

    public function test_the_create_page_stamps_the_uuid_panel_user_on_the_article(): void
    {
        Livewire::test(CreateArticle::class)
            ->fillForm($this->formState())
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->where('slug', 'users')->sole();

        $this->assertSame($this->user->getKey(), $article->created_by);
        $this->assertSame($this->user->getKey(), $article->updated_by);
        $this->assertTrue($article->creator?->is($this->user));
    }

    public function test_the_edit_page_stamps_the_uuid_panel_user_and_the_revision_author(): void
    {
        $article = app(ArticleWriter::class)->create($this->formState(), null);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm($this->formState(['translations' => ['en' => ['body' => 'Rewritten.']]]))
            ->call('save')
            ->assertHasNoFormErrors();

        $article->refresh();

        $this->assertSame($this->user->getKey(), $article->updated_by);
        $this->assertSame($this->user->getKey(), ArticleRevision::query()->latest('id')->firstOrFail()->user_id);
    }

    public function test_the_writer_stores_the_uuid_author_on_create_and_update(): void
    {
        $writer = app(ArticleWriter::class);

        $article = $writer->create($this->formState(), $this->user->getKey());

        $this->assertSame($this->user->getKey(), $article->created_by);
        $this->assertSame($this->user->getKey(), $article->updated_by);

        $writer->update($article, $this->formState(['translations' => ['en' => ['body' => 'Again.']]]), $this->user->getKey());

        $this->assertSame($this->user->getKey(), $article->fresh()?->updated_by);
    }

    public function test_the_media_tab_upload_records_the_uuid_uploader(): void
    {
        Storage::fake('public');

        $article = app(ArticleWriter::class)->create($this->formState(), null);

        // What the Media tab's action closure does once Filament has validated
        // the drop zone: the stored row, with the panel user in uploaded_by.
        app(MediaRecorder::class)->store($this->temporaryPng(), $article, PanelUser::id());

        $media = Media::query()->sole();

        $this->assertSame($this->user->getKey(), $media->uploaded_by);
        $this->assertSame($article->getKey(), $media->article_id);
        $this->assertTrue($media->uploader?->is($this->user));
    }

    public function test_an_image_dropped_into_the_body_records_the_uuid_uploader(): void
    {
        Storage::fake('public');

        $component = Livewire::test(CreateArticle::class);
        $component->set('componentFileAttachments.data.translations.en.body', $this->png());
        $component->instance()->callSchemaComponentMethod(
            $this->bodyFieldKey($component),
            'saveUploadedFileAttachmentAndGetUrl',
        );

        $this->assertSame($this->user->getKey(), Media::query()->sole()->uploaded_by);
    }

    /**
     * A complete create-form state, $overrides merged recursively.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function formState(array $overrides = []): array
    {
        return array_replace_recursive([
            'slug' => 'users',
            'icon' => 'heroicon-o-users',
            'sort_order' => 2,
            'format' => ArticleFormat::Markdown->value,
            'is_published' => true,
            'visibility' => Visibility::Public->value,
            'keywords' => ['people'],
            'related' => [],
            'translations' => [
                'en' => ['title' => 'Users', 'excerpt' => 'Manage users.', 'body' => 'How users work.'],
            ],
        ], $overrides);
    }

    /** A real 1x1 PNG, so Filament's mimetypes: rule sees an actual image. */
    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'shot.png',
            (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true),
        );
    }

    /** The same PNG as Livewire hands a Filament upload field: on the temporary disk. */
    private function temporaryPng(): TemporaryUploadedFile
    {
        Storage::fake(FileUploadConfiguration::disk());

        $file = $this->png();
        $name = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded($file);

        FileUploadConfiguration::storage()->putFileAs('/'.FileUploadConfiguration::path(), $file, $name);

        return TemporaryUploadedFile::createFromLivewire('/'.$name);
    }

    /** The absolute schema key of the English body field, resolved from the schema. */
    private function bodyFieldKey(Testable $component): string
    {
        foreach ($component->instance()->form->getFlatFields(withHidden: true) as $field) {
            if ($field->getStatePath() === 'data.translations.en.body') {
                return (string) $field->getKey();
            }
        }

        return 'form.en.translations.en.body';
    }
}
