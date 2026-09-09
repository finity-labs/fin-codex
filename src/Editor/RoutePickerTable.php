<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The modal table of the contexts picker for a `route:` row, over
 * ContextPicker::routeRows(): the page the route leads to, the route
 * name, its path and its panel. Columns only — the field supplies the rows.
 */
final class RoutePickerTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label(__('fin-codex::fin-codex.editor.contexts.page'))
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('fin-codex::fin-codex.editor.contexts.key'))
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::ExtraSmall)
                    ->color('gray')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('uri')
                    ->label(__('fin-codex::fin-codex.editor.contexts.uri'))
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::ExtraSmall)
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('panel')
                    ->label(__('fin-codex::fin-codex.editor.contexts.panel'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
            ]);
    }
}
