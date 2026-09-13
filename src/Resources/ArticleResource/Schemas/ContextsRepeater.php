<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Editor\PageClassPickerTable;
use FinityLabs\FinCodex\Editor\RoutePickerTable;
use FinityLabs\FinCodex\Help\Declaration;
use FinityLabs\FinCodex\Help\DeclaredContexts;
use FinityLabs\FinCodex\Scope\ContextPanels;
use FinityLabs\FinModalTableSelect\Components\ModalTableSelect;
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
     * Three columns: panel, type, key-or-pattern. Filament's table layout
     * pairs one column with one schema component in order and counts an
     * invisible component as a filled cell, so the key and the pattern live
     * in one Group to keep the count at three. The picked page names itself
     * — label on top, class or route name and path underneath — so there is
     * no separate label cell.
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
            ])
            ->schema([
                Select::make('panel_id')
                    ->native(false)->preload()->searchable(false)
                    ->label(__('fin-codex::fin-codex.editor.contexts.panel'))
                    ->options(fn (): array => app(ContextPicker::class)->panels())
                    ->default(ContextPicker::ANY_PANEL)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if (self::keySurvives($get, $state)) {
                            return;
                        }

                        $set('key', null);

                        Notification::make()
                            ->warning()
                            ->title(__('fin-codex::fin-codex.editor.contexts.key_cleared'))
                            ->body(__('fin-codex::fin-codex.editor.contexts.key_cleared_body'))
                            ->send();
                    }),

                Select::make('type')
                    ->native(false)->preload()->searchable(false)
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
                    // A modal table, not a select: a class or route key needs
                    // its label, its path and its panel beside it to be
                    // picked with confidence. The rows are arrays from
                    // ContextPicker, scoped by the row's own panel and type.
                    ModalTableSelect::make('key')
                        ->label(__('fin-codex::fin-codex.editor.contexts.key'))
                        ->tableConfiguration(fn (Get $get): string => self::isRoute($get) ? RoutePickerTable::class : PageClassPickerTable::class)
                        ->standaloneRecords(fn (Get $get): array => self::keyRows($get), titleAttribute: 'label')
                        ->stackedList()
                        ->stackedListPrimary('label')
                        ->stackedListPrimaryWrapped()
                        ->stackedListSecondary(fn (array $record): ?string => self::keySecondary($record))
                        ->stackedListSecondaryWrapped()
                        ->selectAction(fn (Action $action, Get $get): Action => $action
                            ->iconButton()
                            ->modalHeading(__(self::isRoute($get)
                                ? 'fin-codex::fin-codex.editor.contexts.pick_route'
                                : 'fin-codex::fin-codex.editor.contexts.pick_page'))
                            // Where the sign-in and account pages went. The
                            // line belongs on the action rather than in
                            // PageClassPickerTable, which is handed a Table on
                            // the modal's own Livewire component and can never
                            // see the row's panel.
                            ->modalDescription(self::isRoute($get) || ! self::isNamedPanel($get)
                                ? null
                                : __('fin-codex::fin-codex.editor.contexts.auth_any_panel')))
                        ->emptyStateSelectButton()
                        ->helperText(fn (Get $get): ?string => self::keyPanelWarning($get))
                        ->visible(fn (Get $get): bool => ! self::isUrl($get))
                        ->required(fn (Get $get): bool => ! self::isUrl($get)),

                    TextInput::make('url')
                        ->label(__('fin-codex::fin-codex.editor.contexts.pattern'))
                        ->placeholder('/admin/users/*')
                        ->maxLength(191)
                        // Without a round trip of its own the closure below is
                        // only re-evaluated on the next unrelated update, so a
                        // freshly typed pattern would not raise its warning
                        // until the author touched something else. On blur
                        // rather than debounced: no round trip per keystroke.
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get): ?string => self::urlPanelWarning($get))
                        ->visible(fn (Get $get): bool => self::isUrl($get))
                        ->required(fn (Get $get): bool => self::isUrl($get)),
                ])->columnSpan(1),
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
     * The picker rows for the row's panel and type; a url row has none.
     *
     * @return list<array<string, mixed>>
     */
    private static function keyRows(Get $get): array
    {
        $picker = app(ContextPicker::class);
        $panel = self::panel($get);

        return match (self::type($get)) {
            ContextType::PageClass->key() => $picker->classRows($panel),
            ContextType::Route->key() => $picker->routeRows($panel),
            default => [],
        };
    }

    /**
     * The lines under the picked page's label: the class for a `class:` row
     * (ContextPicker::classRows() marks those with a kind), the route name
     * and its path on two lines for a `route:` row — both wrap rather than
     * truncate, because a class or route name is long and the cell is not.
     * A key the application no longer registers comes back as a bare
     * stand-in row whose label is the key itself, and then there is
     * nothing to add.
     *
     * @param  array<string, mixed>  $record
     */
    private static function keySecondary(array $record): ?string
    {
        $key = $record['key'] ?? null;

        if (! is_string($key) || $key === '' || ! isset($record['label'])) {
            return null;
        }

        $uri = $record['uri'] ?? null;

        return isset($record['kind']) || ! is_string($uri) || $uri === ''
            ? $key
            : $key."\n".$uri;
    }

    /**
     * Whether the key the row is already holding is still offered under the
     * panel just chosen.
     *
     * The select used to clear unconditionally, so an author who moved a row
     * between panels re-picked the same resource every time. The picker knows
     * the answer, so it is asked: "any panel" unions every panel and can never
     * invalidate anything, a class two panels register survives the move, and a
     * key that genuinely is not there is cleared rather than kept and flagged,
     * so the row cannot be saved wrong and the field's own required rule blocks
     * the save until the author picks again.
     *
     * A url row's pattern is free text and an author who typed one may mean it,
     * so it is never touched here. An empty key is nothing to clear and nothing
     * to announce.
     */
    private static function keySurvives(Get $get, ?string $panelId): bool
    {
        $key = $get('key');

        if (! is_string($key) || $key === '') {
            return true;
        }

        $picker = app(ContextPicker::class);

        return match (self::type($get)) {
            ContextType::PageClass->key() => array_key_exists(ltrim($key, '\\'), $picker->classKeys($panelId)),
            ContextType::Route->key() => array_key_exists($key, $picker->routeKeys($panelId)),
            default => true,
        };
    }

    /**
     * The row says "any panel" while its key belongs to exactly one: an
     * inline nudge, live and non-blocking, never an error.
     *
     * This is the mistake the UAT actually made — an admin-only route filed
     * under any panel with nothing objecting. It stays a warning because
     * binding one panel's screen to every panel is unusual rather than wrong,
     * and this package's own starter content does it deliberately. Being live
     * rather than modal-only also means a row stored before this change, and
     * a row a help declaration produced, surface it too.
     *
     * A route belonging to no panel is left alone: that is what any panel is
     * for, and nagging about the correct choice is the failure this phase set
     * out to stop repeating. An auth page is left alone as well, because it
     * carries no panel at all.
     */
    private static function keyPanelWarning(Get $get): ?string
    {
        if (self::isUrl($get) || self::isNamedPanel($get)) {
            return null;
        }

        $key = $get('key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $picker = app(ContextPicker::class);

        $panel = self::isRoute($get)
            ? self::soleRoutePanel($picker->routeRows(null), $key)
            : self::soleClassPanel($picker->classRows(null), ltrim($key, '\\'));

        return $panel === null
            ? null
            : (string) __('fin-codex::fin-codex.editor.contexts.key_panel_warning', ['panel' => $panel]);
    }

    /**
     * The pattern sits under one panel's path while the row names another.
     *
     * The pattern is left exactly as typed — free text is free text, and an
     * author who wrote it may mean it — but the same silent miss the key
     * check catches is worth saying out loud. The resolution is the one the
     * coverage report already trusts, so the editor and the report agree
     * about which panel a path belongs to.
     */
    private static function urlPanelWarning(Get $get): ?string
    {
        if (! self::isUrl($get) || ! self::isNamedPanel($get)) {
            return null;
        }

        $pattern = $get('url');

        if (! is_string($pattern) || $pattern === '') {
            return null;
        }

        $owner = app(ContextPanels::class)->forUrl($pattern);

        return $owner === null || $owner === self::panel($get)
            ? null
            : (string) __('fin-codex::fin-codex.editor.contexts.url_panel_warning', ['panel' => $owner]);
    }

    /**
     * The panel a route name belongs to, which the row already carries as a
     * nullable value meaning exactly that. A key no row matches is a key the
     * picker no longer offers — a different problem than this warning.
     *
     * @param  list<array{key: string, label: string, uri: string, panel: ?string}>  $rows
     */
    private static function soleRoutePanel(array $rows, string $key): ?string
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row['panel'];
            }
        }

        return null;
    }

    /**
     * The one panel registering a class, and null for every other count: an
     * empty list is an auth page and several entries is a genuinely shared
     * class, neither of which is the mistake.
     *
     * @param  list<array{key: string, label: string, kind: string, uri: ?string, panel: list<string>}>  $rows
     */
    private static function soleClassPanel(array $rows, string $key): ?string
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return count($row['panel']) === 1 ? $row['panel'][0] : null;
            }
        }

        return null;
    }

    private static function isUrl(Get $get): bool
    {
        return self::type($get) === ContextType::Url->key();
    }

    private static function isRoute(Get $get): bool
    {
        return self::type($get) === ContextType::Route->key();
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

    /** Whether the row names one panel rather than the "any panel" sentinel. */
    private static function isNamedPanel(Get $get): bool
    {
        $panel = self::panel($get);

        return $panel !== null && $panel !== '' && $panel !== ContextPicker::ANY_PANEL;
    }
}
