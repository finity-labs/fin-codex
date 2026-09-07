<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Editor\SlugRules;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sources\SlugPath;

/**
 * The article form: content wide, settings in the sidebar, like any other
 * Filament resource. The language tabs take two of three columns; identity,
 * publishing and discovery sit in the third.
 *
 * The record is read from the schema rather than passed in: EditRecord binds
 * the model before form() runs, so $schema->getRecord() is the Article on
 * edit and null on create, which is what the slug suggestion, the HTML
 * format lock and the declared-contexts list need to know.
 */
final class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        $record = $record instanceof Article ? $record : null;

        return $schema
            ->columns(3)
            ->components([
                TranslationTabs::make($record)->columnSpan(2),
                Group::make([
                    self::identity(),
                    self::publishing(),
                    self::discovery(),
                    ContextsRepeater::section($record),
                ])->columnSpan(1),
            ]);
    }

    /**
     * Slug, parent, icon and order. The slug is the whole path and the tree:
     * the parent is derived from it and shown read-only, never picked, and
     * the core overwrites parent_id from the slug on every save.
     */
    private static function identity(): Section
    {
        return Section::make(__('fin-codex::fin-codex.editor.form.identity'))->schema([
            TextInput::make('slug')
                ->label(__('fin-codex::fin-codex.editor.form.slug'))
                ->required()
                ->maxLength(191)
                ->rules(SlugRules::rules())
                ->validationMessages(['regex' => __('fin-codex::fin-codex.editor.validation.slug_format')])
                ->unique(ignoreRecord: true)
                ->live(onBlur: true)
                ->helperText(__('fin-codex::fin-codex.editor.form.slug_help')),

            Hidden::make('slug_suggested')->dehydrated(false),

            TextEntry::make('parent')
                ->label(__('fin-codex::fin-codex.editor.form.parent'))
                ->state(fn (Get $get): string => SlugPath::parentOf((string) $get('slug'))
                    ?? (string) __('fin-codex::fin-codex.editor.form.no_parent')),

            Select::make('icon')
                ->label(__('fin-codex::fin-codex.editor.form.icon'))
                ->searchable()
                ->allowHtml()
                ->options(self::iconOptions(...)),

            TextInput::make('sort_order')
                ->label(__('fin-codex::fin-codex.editor.form.order'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required(),
        ]);
    }

    /**
     * Format, published flag and visibility. An HTML article's format is
     * locked here: it becomes Markdown only through 05-07's convert action,
     * which rewrites the bodies in the same transaction. The field is not
     * dehydrated in that case, so the writer never sees the key.
     */
    private static function publishing(): Section
    {
        return Section::make(__('fin-codex::fin-codex.editor.form.publishing'))->schema([
            Select::make('format')
                ->label(__('fin-codex::fin-codex.editor.form.format'))
                ->options(fn (): array => collect(ArticleFormat::cases())
                    ->mapWithKeys(fn (ArticleFormat $format): array => [$format->value => $format->label()])
                    ->all())
                ->default(ArticleFormat::Markdown->value)
                ->required()
                ->disabled(fn (?Article $record): bool => $record?->format === ArticleFormat::Html)
                ->dehydrated(fn (?Article $record): bool => $record?->format !== ArticleFormat::Html),

            Toggle::make('is_published')
                ->label(__('fin-codex::fin-codex.editor.form.published'))
                ->default(true),

            Select::make('visibility')
                ->label(__('fin-codex::fin-codex.editor.form.visibility'))
                ->options(fn (): array => collect(Visibility::cases())
                    ->mapWithKeys(fn (Visibility $visibility): array => [$visibility->value => $visibility->label()])
                    ->all())
                ->default(Visibility::Authenticated->value)
                ->required(),
        ]);
    }

    /**
     * Keywords and related articles. Related articles are listed by title
     * with the slug as a hint and read from the composite source, so a
     * file-backed article can be related to before anyone imports it; the
     * article itself is never in its own list.
     */
    private static function discovery(): Section
    {
        return Section::make(__('fin-codex::fin-codex.editor.form.discovery'))->schema([
            TagsInput::make('keywords')
                ->label(__('fin-codex::fin-codex.editor.form.keywords'))
                ->default([]),

            Select::make('related')
                ->label(__('fin-codex::fin-codex.editor.form.related'))
                ->multiple()
                ->searchable()
                ->options(fn (?Article $record): array => self::relatedOptions($record))
                ->default([]),
        ]);
    }

    /**
     * Every outlined Heroicon Filament ships, rendered beside its name and
     * stored as the "heroicon-o-..." string lin-codex's payloads expose.
     *
     * @return array<string, string>
     */
    private static function iconOptions(): array
    {
        return collect(Heroicon::cases())
            ->filter(fn (Heroicon $icon): bool => str_starts_with($icon->value, 'o-'))
            ->mapWithKeys(fn (Heroicon $icon): array => [
                'heroicon-'.$icon->value => svg('heroicon-'.$icon->value, 'h-4 w-4 inline-block')->toHtml().' '.$icon->value,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function relatedOptions(?Article $record): array
    {
        $default = TranslationTabs::languages()['default'];

        return collect(app(ContentSource::class)->all())
            ->reject(fn (ArticleData $article): bool => $article->slug === $record?->slug)
            ->mapWithKeys(fn (ArticleData $article): array => [
                $article->slug => ($article->translation($default)->title
                    ?? SlugPath::humanise(SlugPath::lastSegment($article->slug))).' ('.$article->slug.')',
            ])
            ->all();
    }
}
