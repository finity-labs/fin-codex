<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use FinityLabs\LinCodex\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * The modal table behind the editor's "Insert file" picker: every upload
 * of any article, newest first, so an admin can show a screenshot or link
 * a PDF in a second article without uploading it twice. The picker runs
 * in the component's model mode, so this configuration owns the query.
 */
final class MediaPickerTable
{
    public static function configure(Table $table): Table
    {
        $references = app(MediaReferences::class);

        return $table
            ->query(Media::query()->with('article:id,slug'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ViewColumn::make('preview')
                    ->label(__('fin-codex::fin-codex.media.columns.preview'))
                    ->view('fin-codex::editor.media-thumbnail')
                    ->state(fn (Media $record): array => [
                        'url' => $references->urlFor($record),
                        'isImage' => str_starts_with($record->mime_type, 'image/'),
                        'label' => $record->name,
                    ]),
                TextColumn::make('name')
                    ->label(__('fin-codex::fin-codex.media.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mime_type')
                    ->label(__('fin-codex::fin-codex.media.columns.type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('article.slug')
                    ->label(__('fin-codex::fin-codex.media.columns.article'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('size')
                    ->label(__('fin-codex::fin-codex.media.columns.size'))
                    ->state(fn (Media $record): string => Number::fileSize($record->size, precision: 1))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('size', $direction)),
                TextColumn::make('created_at')
                    ->label(__('fin-codex::fin-codex.media.columns.date'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ]);
    }
}
