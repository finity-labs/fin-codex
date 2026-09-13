<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The modal table of the contexts picker for a `class:` row, over
 * ContextPicker::classRows(): the page as the panel names it, the class,
 * whether it is a resource or a custom page, its path and its panels.
 * Columns only — the field supplies the rows.
 */
final class PageClassPickerTable
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
                    ->searchable(),
                TextColumn::make('kind')
                    ->label(__('fin-codex::fin-codex.editor.contexts.kind'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('uri')
                    ->label(__('fin-codex::fin-codex.editor.contexts.uri'))
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::ExtraSmall)
                    ->color('gray')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('panel')
                    ->label(__('fin-codex::fin-codex.editor.contexts.panel'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
            ]);
    }
}
