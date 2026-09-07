<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Help\Declaration;
use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleContext;

/**
 * Where the article shows up: the contexts section of the form.
 *
 * The rows are a table-layout Repeater over plain form state, never the
 * Repeater's relationship mode. That mode writes the rows itself, after
 * handleRecordUpdate() has already returned and outside ArticleWriter's
 * transaction, so a failed article save could still leave new contexts
 * behind and none of them would carry the panel user's attribution. The
 * rows travel as plain form state instead, the page mutators dehydrate them
 * and the writer replaces the lot in one transaction with sort_order set to
 * the row's position — which is why drag order is the author order the core
 * reads back.
 *
 * The three selects cascade because a key only means something inside a
 * panel and a type: picking a panel or a type clears the key, and the key
 * options are asked of ContextPicker for exactly that pair. `key` and `url`
 * are two separate fields sharing one column, only ever one of them visible;
 * one field with two meanings would need two sets of options, two validation
 * rules and two placeholders on one state path. dehydrate() merges them back
 * into the single `key` the core stores.
 *
 * Contexts a class declares in code (Phase 4's DeclaredContexts, folded into
 * the read model by the ContentSource decorator) are listed above the
 * repeater and nowhere else: they are not rows in codex_article_contexts,
 * they cannot be edited from here, and putting them into the form state
 * would make the next save persist copies of them.
 */
final class ContextsRepeater
{
    /**
     * The whole section: the read-only declared list, when the article has
     * one, above the editable rows.
     */
    public static function section(?Article $record): Section
    {
        return Section::make(__('fin-codex::fin-codex.editor.form.contexts'))
            ->schema([
                View::make('fin-codex::editor.declared-contexts')
                    ->viewData(fn (): array => ['declarations' => self::declarationsFor($record)])
                    ->visible(fn (): bool => self::declarationsFor($record) !== []),

                self::make(),
            ]);
    }

    /**
     * Four columns: panel, type, key-or-pattern, resolved label. Filament's
     * table layout pairs one column with one schema component in order and
     * counts an invisible component as a filled cell, so the key and the
     * pattern live in one Group to keep the count at four.
     */
    public static function make(): Repeater
    {
        return Repeater::make('contexts')
            ->label(__('fin-codex::fin-codex.editor.form.contexts'))
            ->hiddenLabel()
            ->table([
                TableColumn::make(__('fin-codex::fin-codex.editor.contexts.panel')),
                TableColumn::make(__('fin-codex::fin-codex.editor.contexts.type')),
                TableColumn::make(__('fin-codex::fin-codex.editor.contexts.key')),
                TableColumn::make(__('fin-codex::fin-codex.editor.contexts.label')),
            ])
            ->schema([
                Select::make('panel_id')
                    ->label(__('fin-codex::fin-codex.editor.contexts.panel'))
                    ->options(fn (): array => app(ContextPicker::class)->panels())
                    ->default(ContextPicker::ANY_PANEL)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('key', null);
                    }),

                Select::make('type')
                    ->label(__('fin-codex::fin-codex.editor.contexts.type'))
                    ->options(fn (): array => app(ContextPicker::class)->types())
                    ->default(ContextType::PageClass->key())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('key', null);
                        $set('url', null);
                    }),

                Group::make([
                    Select::make('key')
                        ->label(__('fin-codex::fin-codex.editor.contexts.key'))
                        ->searchable()
                        ->options(fn (Get $get): array => self::keyOptions($get))
                        ->visible(fn (Get $get): bool => ! self::isUrl($get))
                        ->required(fn (Get $get): bool => ! self::isUrl($get)),

                    TextInput::make('url')
                        ->label(__('fin-codex::fin-codex.editor.contexts.pattern'))
                        ->placeholder('/admin/users/*')
                        ->maxLength(191)
                        ->visible(fn (Get $get): bool => self::isUrl($get))
                        ->required(fn (Get $get): bool => self::isUrl($get)),
                ])->columnSpan(1),

                TextEntry::make('label')
                    ->label(__('fin-codex::fin-codex.editor.contexts.label'))
                    ->hiddenLabel()
                    ->placeholder('—')
                    ->state(fn (Get $get): ?string => self::rowLabel($get))
                    ->extraAttributes(fn (Get $get): array => [
                        'data-fin-codex-context-label' => self::rowLabel($get) ?? '',
                    ]),
            ])
            ->reorderable()
            ->addActionLabel(__('fin-codex::fin-codex.editor.contexts.add'))
            ->defaultItems(0)
            ->columnSpanFull();
    }

    /**
     * The stored rows as form state, in the author order the core keeps. A
     * null panel_id is the "any panel" sentinel in the select; a url row
     * carries its pattern in `url` and leaves `key` empty, and the other way
     * round, so the two fields never both hold a value.
     *
     * @return list<array{panel_id: string, type: string, key: string, url: string}>
     */
    public static function fill(Article $record): array
    {
        return $record->contexts()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static function (ArticleContext $context): array {
                $isUrl = $context->type === ContextType::Url;

                return [
                    'panel_id' => $context->panel_id ?? ContextPicker::ANY_PANEL,
                    'type' => $context->type->key(),
                    'key' => $isUrl ? '' : $context->key,
                    'url' => $isUrl ? $context->key : '',
                ];
            })
            ->all();
    }

    /**
     * Form state to the writer's context shape, in the order Filament hands
     * the rows back (drag order). A row without a usable type or key is
     * dropped: the field-level required() rules catch that before a save, so
     * this is the safety net for state that never went through validation.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @return list<array{panel_id: ?string, type: string, key: string}>
     */
    public static function dehydrate(array $rows): array
    {
        $contexts = [];

        foreach ($rows as $row) {
            $type = is_string($row['type'] ?? null) ? $row['type'] : '';

            if (ContextType::tryFromKey($type) === null) {
                continue;
            }

            $raw = $type === ContextType::Url->key() ? ($row['url'] ?? null) : ($row['key'] ?? null);
            $key = is_string($raw) ? trim($raw) : '';

            if ($key === '') {
                continue;
            }

            $panel = is_string($row['panel_id'] ?? null) ? $row['panel_id'] : '';

            $contexts[] = [
                'panel_id' => in_array($panel, ['', ContextPicker::ANY_PANEL], true) ? null : $panel,
                'type' => $type,
                'key' => $key,
            ];
        }

        return $contexts;
    }

    /**
     * The declarations that name this article, for the read-only list. A
     * record that does not exist yet (the create page) has no slug to match
     * and strict models would throw on reading one.
     *
     * @return list<Declaration>
     */
    private static function declarationsFor(?Article $record): array
    {
        if ($record === null || ! $record->exists) {
            return [];
        }

        $slug = $record->slug;

        return array_values(array_filter(
            app(DeclaredContexts::class)->declarations(),
            static fn (Declaration $declaration): bool => $declaration->slug === $slug,
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function keyOptions(Get $get): array
    {
        $picker = app(ContextPicker::class);
        $panel = self::panel($get);

        return match (self::type($get)) {
            ContextType::PageClass->key() => $picker->classKeys($panel),
            ContextType::Route->key() => $picker->routeKeys($panel),
            default => [],
        };
    }

    private static function rowLabel(Get $get): ?string
    {
        $type = self::type($get);
        $raw = $get($type === ContextType::Url->key() ? 'url' : 'key');

        return app(ContextPicker::class)->label(self::panel($get), $type, is_string($raw) ? $raw : null);
    }

    private static function isUrl(Get $get): bool
    {
        return self::type($get) === ContextType::Url->key();
    }

    private static function type(Get $get): ?string
    {
        $type = $get('type');

        return is_string($type) ? $type : null;
    }

    /** The row's panel, `*` included: ContextPicker reads it as "any panel". */
    private static function panel(Get $get): ?string
    {
        $panel = $get('panel_id');

        return is_string($panel) ? $panel : null;
    }
}
