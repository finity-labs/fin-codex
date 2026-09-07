<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Forms;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Help\ArticleLookup;
use FinityLabs\LinCodex\Rendering\ArticlePath;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/**
 * The field hint. make() returns a Filament Action for Field::hintAction()
 * (or the Field::codexHelp() macro): an icon-only question-mark button in
 * the size and colour of Filament's own hint icons, with "Open help" as the
 * accessible label and the article's title in the reader's language as the
 * tooltip. The action is hidden, leaving the field as if no hint were set,
 * whenever ArticleLookup answers null, that is whenever the core's gate
 * says the viewer may not read the article or no such article exists.
 *
 * Two activation paths, no configuration. The anchor always carries the
 * core's help-center URL (ArticlePath::href(), the heading as the fragment).
 * Its Alpine click handler dispatches the window event codex:open with
 * { slug, heading } and calls preventDefault only when a drawer
 * ([data-codex-drawer]) is on the page, so the Phase 2 drawer's openFrom()
 * opens the article and scrolls to the heading with no server round trip;
 * without a drawer the browser follows the link in the same tab. Because
 * the handler is non-blank, Filament renders no wire:click for the action.
 *
 * The lookup is resolved lazily inside the tooltip and visible closures, so
 * the panel, the viewer and the locale are those of the rendering request.
 * The action name is codex-help-{slug}[-{heading}] slugified, so two hints
 * on one field never collide unless their slug and heading slugify to the
 * same string.
 *
 * It is a plain Action, so hosts may attach it anywhere Filament takes
 * one: a section's afterHeader, an infolist entry's hint, a table's header
 * actions. Only fields get the macro.
 */
final class CodexHelp
{
    public static function make(string $slug, ?string $heading = null): Action
    {
        $detail = Js::from(array_filter(
            ['slug' => $slug, 'heading' => $heading],
            static fn (?string $value): bool => $value !== null && $value !== '',
        ))->toHtml();

        return Action::make(self::name($slug, $heading))
            ->iconButton()
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('gray')
            ->label(__('fin-codex::fin-codex.hint.open'))
            ->tooltip(static fn (): ?string => app(ArticleLookup::class)->title($slug))
            ->visible(static fn (): bool => app(ArticleLookup::class)->title($slug) !== null)
            ->url(ArticlePath::href($slug, $heading))
            ->alpineClickHandler("if (document.querySelector('[data-codex-drawer]')) { \$event.preventDefault(); window.dispatchEvent(new CustomEvent('codex:open', { detail: {$detail} })) }");
    }

    /**
     * codex-help-{slug}[-{heading}] with the slug's path segments hyphenated
     * (Str::slug() would drop the slash and run them together).
     */
    private static function name(string $slug, ?string $heading): string
    {
        return 'codex-help-'.Str::slug(str_replace('/', '-', $slug).($heading === null || $heading === '' ? '' : '-'.$heading));
    }
}
