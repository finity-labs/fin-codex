<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Schemas;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Editor\MediaRecorder;
use FinityLabs\FinCodex\Editor\OutdatedTranslations;
use FinityLabs\FinCodex\Editor\SlugRules;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Database\QueryException;
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
 * still the open one after the copy action's confirmation modal.
 *
 * A tab carries at most one badge, and the two badges have different
 * sources. **Missing** is read from live form state, so it disappears the
 * moment the title and the body are both filled, without a save.
 * **Outdated** is read from the stored timestamps through
 * OutdatedTranslations, which knows nothing about the form: it is therefore
 * fixed for the whole page render and only clears on the next mount after a
 * save. That is the honest answer — until the tab is saved, the stored
 * translation really is older than the stored default one.
 */
final class TranslationTabs
{
    public static function make(?Article $record): Tabs
    {
        $languages = self::languages();
        $default = $languages['default'];
        $verdict = self::verdicts($record);

        $tabs = [];

        foreach ($languages['languages'] as $language) {
            $code = $language['code'];
            $isDefault = $code === $default;
            $label = trim(self::flag($language['flag-icon']).' '.$language['display']);

            $tabs[$code] = Tab::make($label)
                ->extraAttributes(['data-fin-codex-locale' => $code])
                ->badge(static function (Get $get) use ($code, $isDefault, $verdict): ?string {
                    if (blank($get("translations.{$code}.title")) || blank($get("translations.{$code}.body"))) {
                        return $isDefault ? null : __('fin-codex::fin-codex.editor.state.missing');
                    }

                    if (! $isDefault && $verdict($code) === OutdatedTranslations::OUTDATED) {
                        return __('fin-codex::fin-codex.editor.state.outdated');
                    }

                    return null;
                })
                ->badgeColor(static fn (?string $badge): string => $badge === __('fin-codex::fin-codex.editor.state.outdated') ? 'warning' : 'gray')
                ->schema(self::fields($code, $default, $record));
        }

        return Tabs::make('translations')
            ->livewireProperty('activeLocale')
            ->tabs($tabs);
    }

    /**
     * One verdict lookup for the whole tab set, resolved on first use.
     *
     * Asking OutdatedTranslations per tab would cost one settings query and
     * one translations query per language (CodexSettings is not a shared
     * binding in a package install), and the create page must not query at
     * all — it has no record to compare anything against.
     *
     * @return callable(string): ?string locale => PRESENT|MISSING|OUTDATED, null without a record
     */
    private static function verdicts(?Article $record): callable
    {
        /** @var array<string, string>|null $verdicts */
        $verdicts = null;

        return static function (string $code) use ($record, &$verdicts): ?string {
            if ($record === null) {
                return null;
            }

            $verdicts ??= app(OutdatedTranslations::class)->verdicts($record);

            return $verdicts[$code] ?? null;
        };
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
     * Title, excerpt and body of one locale, plus the copy-from-default
     * action on every tab but the default one. On create, typing in the
     * default-language title suggests the slug; on edit the record already
     * has one and the title never touches it.
     *
     * @return list<Action|Component>
     */
    private static function fields(string $code, string $default, ?Article $record): array
    {
        $isDefault = $code === $default;

        $title = TextInput::make("translations.{$code}.title")
            ->label(__('fin-codex::fin-codex.editor.form.title'))
            ->required($isDefault)
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
            $isHtml ? self::htmlBody($code) : self::body($code)->required($isDefault),
            self::copyFromDefault($code, $default),
        ];
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
            ->fileAttachmentsDirectory(static fn (): string => (string) config('lin-codex.media.directory', 'codex'))
            ->fileAttachmentsAcceptedFileTypes(['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'])
            ->fileAttachmentsMaxSize(4096)
            ->saveUploadedFileAttachmentUsing(
                static fn (TemporaryUploadedFile $file, ?Article $record): string => app(MediaRecorder::class)
                    ->store($file, $record, self::userId()),
            );
    }

    /** The panel user's id, or null for a panel without an authenticated user. */
    private static function userId(): ?int
    {
        $id = Filament::auth()->id();

        return is_numeric($id) ? (int) $id : null;
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
