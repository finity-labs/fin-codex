<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Coverage;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;

/**
 * What the content sources complained about, as one collapsed amber box.
 *
 * Collapsed rather than an open banner: a filesystem source with twenty
 * malformed files would otherwise push the table it sits above off the
 * screen, and a healthy installation pays no vertical space at all because
 * the section renders nothing when the count is zero.
 *
 * It is a Filament Section, not a Blade div: a package must not emit its own
 * Tailwind utilities, and the Section brings the collapse, the heading and
 * the panel's styling for free. Section carries an icon and an icon colour
 * but no colour of its own; Callout has the colour but cannot collapse, and
 * the collapse is the locked requirement, so the amber lives on the icon.
 */
final class WarningsSection
{
    public static function make(): Section
    {
        return Section::make(fn (): string => self::heading())
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('warning')
            ->description(__('fin-codex::fin-codex.warnings.description'))
            ->collapsible()
            ->collapsed()
            ->columnSpanFull()
            ->schema([
                View::make('fin-codex::coverage.warnings')
                    ->viewData(fn (): array => ['groups' => app(SourceWarnings::class)->grouped()]),
            ])
            ->visible(fn (): bool => app(SourceWarnings::class)->count() > 0);
    }

    /**
     * A closure on the Section, not a computed string: the schema is built
     * once per render but the count has to be read at render time, which is
     * what keeps the heading honest when a test seeds articles mid-request.
     *
     * The heading, the visibility and the view data all go through the
     * request-scoped SourceWarnings, so the source is read once per request
     * rather than three times per render.
     */
    private static function heading(): string
    {
        $count = app(SourceWarnings::class)->count();

        return (string) trans_choice('fin-codex::fin-codex.warnings.heading', $count, ['count' => $count]);
    }
}
