<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Editor\MediaPickerTable;
use FinityLabs\FinCodex\Editor\MediaRecorder;
use FinityLabs\FinCodex\Editor\MediaReferences;
use FinityLabs\FinCodex\Editor\SlugRules;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\TranslateWithAiAction;
use FinityLabs\FinModalTableSelect\Components\ModalTableSelect;
use FinityLabs\FinSupport\Panel\PanelUser;
use FinityLabs\LinCodex\Ai\AiAvailabilityCheck;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * One tab per configured language, in settings order, labelled with the flag
 * and the display name.
 *
 * Translations are form state keyed by locale ("translations.de.title"), not
 * a relationship repeater: the writer, not the form, decides that an emptied
 * non-default tab means "delete this translation", and the whole save then
 * fits in one transaction. Only the default-language tab is required — an
 * article exists in its default language and is translated later.
 *
 * The body is a MarkdownEditor, never a WYSIWYG: article bodies round-trip
 * to Markdown files, and a rich editor would smuggle HTML into them. An
 * HTML-format article gets no editor at all — its body is shown as source,
 * read-only, until the convert action turns it into Markdown.
 *
 * Tabs::livewireProperty() keeps the active tab on the page itself, so the
 * server knows which language the admin is looking at (05-07's preview reads
 * it), a locale key survives a round trip, and the tab the admin was on is
 * still the open one after a confirmation modal from the actions row under
 * the body - Copy from default, and Translate with AI beside it while the
 * installation can translate at all.
 *
 * A tab carries one badge at most: **Missing**, read from live form state,
 * so it disappears the moment the title and the body are both filled,
 * without a save. There is no "outdated" badge. OutdatedTranslations can
 * still say that the default language was saved after a translation, but
 * the editor does not show it: a corrected typo in the default text is
 * not a reason to alarm every other language, and a badge that fires on
 * a typo is soon ignored.
 */
final class TranslationTabs
{
    public static function make(?Article $record): Tabs
    {
        $languages = self::languages();
        $default = $languages['default'];

        /*
         * Once per build, never once per tab: each check loads the AI settings
         * group, and every tab would ask the same question and get the same
         * answer.
         */
        $available = app(AiAvailabilityCheck::class)->available();

        $displays = [];

        foreach ($languages['languages'] as $language) {
            $displays[$language['code']] = $language['display'] !== '' ? $language['display'] : $language['code'];
        }

        $defaultDisplay = $displays[$default] ?? $default;

        $tabs = [];

        foreach ($languages['languages'] as $language) {
            $code = $language['code'];
            $isDefault = $code === $default;
            $label = trim(self::flag($language['flag-icon']).' '.$language['display']);

            $tabs[$code] = Tab::make($label)
                ->extraAttributes(['data-fin-codex-locale' => $code])
                ->badge(static function (Get $get) use ($code, $isDefault): ?string {
                    if (blank($get("translations.{$code}.title")) || blank($get("translations.{$code}.body"))) {
                        return $isDefault ? null : __('fin-codex::fin-codex.editor.state.missing');
                    }

                    return null;
                })
                ->badgeColor('gray')
                ->schema(self::fields($code, $default, $record, $available, $displays[$code] ?? $code, $defaultDisplay));
        }

        return Tabs::make('translations')
            ->livewireProperty('activeLocale')
            ->tabs($tabs);
    }

    /**
     * The configured languages and the default locale. Read from settings,
     * falling back to the packaged defaults when the settings row is not
     * there yet (a fresh install before Phase 6's settings page saves one) or
     * the table does not exist.
     *
     * @return array{languages: list<array{code: string, display: string, 'flag-icon': string}>, default: string}
     */
    public static function languages(): array
    {
        try {
            $settings = app(CodexSettings::class);

            /** @var list<array{code: string, display: string, 'flag-icon': string}> $languages */
            $languages = array_values($settings->languages);

            return ['languages' => $languages, 'default' => $settings->default_locale];
        } catch (MissingSettings|QueryException) {
            $defaults = CodexSettings::defaults();

            /** @var list<array{code: string, display: string, 'flag-icon': string}> $languages */
            $languages = array_values((array) $defaults['languages']);

            return ['languages' => $languages, 'default' => (string) $defaults['default_locale']];
        }
    }

    /**
     * The regional-indicator emoji for a two-letter flag-icon code ("de" is
     * U+1F1E9 U+1F1EA). Nothing in the stack draws flag images, and an emoji
     * needs no asset; anything that is not two ASCII letters renders nothing.
     */
    public static function flag(string $code): string
    {
        if (preg_match('/^[a-z]{2}$/', $code) !== 1) {
            return '';
        }

        return (string) mb_chr(0x1F1E6 + ord($code[0]) - ord('a'), 'UTF-8')
            .(string) mb_chr(0x1F1E6 + ord($code[1]) - ord('a'), 'UTF-8');
    }

    /**
     * Title, excerpt and body of one locale, plus the actions row - Copy from
     * default first, Translate with AI second - on every tab but the default
     * one, where both hide and the row goes with them. An HTML article keeps
     * only the copy: there is no editable body to translate into. On create,
     * typing in the default-language title suggests the slug; on edit the
     * record already has one and the title never touches it.
     *
     * @return list<Action|Component>
     */
    private static function fields(string $code, string $default, ?Article $record, bool $aiAvailable, string $display, string $defaultDisplay): array
    {
        $isDefault = $code === $default;

        // A non-default tab is optional as a whole, never by half: the writer
        // deletes a tab emptied of both and refuses one emptied of either,
        // so the form says which field is missing before the writer has to.
        $title = TextInput::make("translations.{$code}.title")
            ->label(__('fin-codex::fin-codex.editor.form.title'))
            ->required($isDefault)
            ->requiredWith($isDefault ? [] : "translations.{$code}.body")
            ->maxLength(255)
            ->live(onBlur: true);

        if ($isDefault && $record === null) {
            $title = $title->afterStateUpdated(self::suggestSlug());
        }

        $isHtml = $record?->format === ArticleFormat::Html;

        return [
            $title,
            Textarea::make("translations.{$code}.excerpt")
                ->label(__('fin-codex::fin-codex.editor.form.excerpt'))
                ->rows(3),
            $isHtml ? self::htmlBody($code) : self::body($code)->required($isDefault)->requiredWith($isDefault ? [] : "translations.{$code}.title"),
            ...($isHtml ? [] : [self::insertFile($code)]),
            Actions::make($isHtml
                ? [self::copyFromDefault($code, $default)]
                : [
                    self::copyFromDefault($code, $default),
                    TranslateWithAiAction::make($code, $default, $display, $defaultDisplay, $aiAvailable),
                ]),
        ];
    }

    /**
     * A picker over every file already uploaded, for reuse across articles.
     * An upload belongs to the article it was dropped into, but nothing
     * stops a second article from using it — the media tab's delete
     * refuses while any body references the file, whichever article's.
     * Choosing one appends its Markdown to the body — an image as an image,
     * a document as a link with the file name as its text — and clears the
     * picker again; the field carries no state of its own into the save.
     *
     * Selection-only, so the field is its label line and the action on it;
     * the chosen file shows up in the body, not in the picker.
     */
    private static function insertFile(string $code): ModalTableSelect
    {
        return ModalTableSelect::make("insert_file_{$code}")
            ->label(__('fin-codex::fin-codex.editor.form.insert_file'))
            ->standalone(Media::class, 'name')
            ->tableConfiguration(MediaPickerTable::class)
            ->selectionOnly()
            ->selectAction(static fn (Action $action): Action => $action
                ->link()
                ->label(__('fin-codex::fin-codex.editor.form.insert_file_action'))
                ->icon(Heroicon::OutlinedPaperClip)
                ->modalHeading(__('fin-codex::fin-codex.editor.form.insert_file_heading')))
            ->dehydrated(false)
            ->afterStateUpdated(static function (mixed $state, Get $get, Set $set, ModalTableSelect $component) use ($code): void {
                $component->state(null);

                $media = is_scalar($state) ? Media::query()->find($state) : null;
                $url = $media instanceof Media ? app(MediaReferences::class)->urlFor($media) : null;

                if (! $media instanceof Media || $url === null) {
                    return;
                }

                // Relative to the form root, like copyFromDefault(): neither
                // Tabs nor Tab carries a state path of its own.
                $body = $get("translations.{$code}.body");
                $body = is_string($body) ? rtrim($body) : '';

                $markdown = str_starts_with($media->mime_type, 'image/')
                    ? '!['.self::linkText(Str::beforeLast($media->name, '.')).']('.$url.')'
                    : '['.self::linkText($media->name).']('.$url.')';

                $set("translations.{$code}.body", ($body === '' ? '' : $body."\n\n").$markdown."\n");
            });
    }

    /** A name minus the brackets Markdown would misread. */
    private static function linkText(string $name): string
    {
        return trim(str_replace(['[', ']'], '', $name));
    }

    /**
     * An HTML article's body: its own source, read-only.
     *
     * A MarkdownEditor would parse the HTML as Markdown and hand it back
     * mangled, and a disabled one renders its state through Markdown too, so
     * the tags would show up as text. A rich editor is out for the reason the
     * class docblock gives. What is left is the source in a plain textarea
     * the admin can read and copy but not save.
     *
     * dehydrated(false) keeps the body key out of the form data entirely, and
     * ArticleWriter reads a missing body key as "keep the stored one", so a
     * title or excerpt edit on an HTML article cannot touch the body even by
     * accident. Not required either: there is nothing here to fill in — the
     * body exists, and the only way to change it is the convert action.
     */
    private static function htmlBody(string $code): Textarea
    {
        return Textarea::make("translations.{$code}.body")
            ->label(__('fin-codex::fin-codex.editor.form.body'))
            ->helperText(__('fin-codex::fin-codex.editor.html_readonly'))
            ->extraAttributes(['data-fin-codex-html-body' => $code])
            ->rows(24)
            ->disabled()
            ->dehydrated(false);
    }

    /**
     * The Markdown body, with image uploads pointed at the core's media disk.
     *
     * The disk and the directory are read from lin-codex's config through
     * closures, so they are resolved when the editor renders and not when
     * the schema class is loaded; the accepted types are the five raster
     * formats a browser draws, never a vector one. Filament refuses anything
     * else before MediaRecorder is called at all.
     *
     * Attachment visibility is deliberately never set here: MarkdownEditor
     * throws on that setter, because static Markdown cannot carry a
     * temporary signed URL. Attachments are public, which is what the images
     * in a help article are.
     */
    private static function body(string $code): MarkdownEditor
    {
        return MarkdownEditor::make("translations.{$code}.body")
            ->label(__('fin-codex::fin-codex.editor.form.body'))
            ->fileAttachmentsDisk(static fn (): string => (string) config('lin-codex.media.disk', 'public'))
            ->fileAttachmentsDirectory(static fn (): string => app(MediaRecorder::class)->directory())
            ->fileAttachmentsAcceptedFileTypes(['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'])
            ->fileAttachmentsMaxSize(4096)
            ->saveUploadedFileAttachmentUsing(
                static fn (TemporaryUploadedFile $file, ?Article $record): string => app(MediaRecorder::class)
                    ->store($file, $record, self::userId()),
            );
    }

    /** The panel user's id, or null for a panel without an authenticated user. */
    private static function userId(): int|string|null
    {
        return PanelUser::id();
    }

    /**
     * Prefill one language from the default one, after a confirmation.
     *
     * Get reads the *unsaved* default tab, which is the only reading that
     * makes sense here: the admin has usually just written the English text
     * and wants the German tab to start from it. Reading the stored row
     * would copy whatever was saved last time and quietly ignore the edit in
     * front of them.
     *
     * Exactly three fields travel — title, excerpt and body. The slug, the
     * icon and everything else in the sidebar belong to the article, not to
     * a language.
     */
    private static function copyFromDefault(string $code, string $default): Action
    {
        return Action::make('copy_from_default')
            ->label(__('fin-codex::fin-codex.editor.copy.label'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible($code !== $default)
            ->requiresConfirmation()
            ->modalHeading(__('fin-codex::fin-codex.editor.copy.heading'))
            ->modalDescription(__('fin-codex::fin-codex.editor.copy.description'))
            ->action(static function (Get $get, Set $set) use ($code, $default): void {
                foreach (['title', 'excerpt', 'body'] as $field) {
                    $set("translations.{$code}.{$field}", $get("translations.{$default}.{$field}"));
                }
            });
    }

    /**
     * Write the slug suggested by the title, but only while the admin has
     * not typed one of their own: slug_suggested remembers what was last
     * suggested, so a slug the admin edited is never overwritten. Get and Set
     * resolve against the form root here because neither Tabs nor Tab carries
     * a state path.
     */
    private static function suggestSlug(): callable
    {
        return static function (Set $set, Get $get, ?string $state): void {
            $current = (string) $get('slug');
            $suggested = (string) $get('slug_suggested');

            if ($current !== '' && $current !== $suggested) {
                return;
            }

            $slug = SlugRules::suggest($state);

            $set('slug', $slug);
            $set('slug_suggested', $slug);
        };
    }
}
