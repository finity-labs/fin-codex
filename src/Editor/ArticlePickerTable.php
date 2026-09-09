<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The modal table of the article pickers, over ArticlePicker::rows(). The
 * rows are plain arrays supplied by the field, so this is columns only —
 * no query. Title and slug are searchable, the rest tells two articles
 * with the same title apart.
 */
final class ArticlePickerTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('fin-codex::fin-codex.editor.picker.title'))
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('fin-codex::fin-codex.editor.picker.slug'))
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::ExtraSmall)
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('languages')
                    ->label(__('fin-codex::fin-codex.editor.columns.languages'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('source')
                    ->label(__('fin-codex::fin-codex.editor.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("fin-codex::fin-codex.editor.source.{$state}"))
                    ->color(fn (string $state): string => $state === 'database' ? 'info' : 'gray'),
                IconColumn::make('published')
                    ->label(__('fin-codex::fin-codex.editor.columns.published'))
                    ->boolean(),
            ]);
    }
}
