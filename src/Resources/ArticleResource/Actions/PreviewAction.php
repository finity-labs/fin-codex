<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Rendering\ArticleRenderer;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\View\View;

/**
 * "Show me what I just wrote, the way the reader will see it."
 *
 * The body goes through lin-codex's ArticleRenderer — the same pipeline the
 * drawer, the help centre and the API use — so a callout, a steps fence or a
 * figure looks here exactly as it will in the drawer. The MarkdownEditor's
 * own preview button is EasyMDE's: it knows CommonMark and nothing else, so
 * `> [!WARNING]` shows up as a quoted paragraph and `:::steps` as literal
 * colons. That is the one preview an admin must not be given.
 *
 * The body comes from the form's *raw* state, never the validated one:
 * validating would reject a half-finished article, and working on one is the
 * whole point of a preview — no slug yet, no title, nothing saved. Raw state
 * is also what makes an unsaved edit visible; the stored translation would be
 * last week's text.
 *
 * One render per press, on the active language tab only. A live preview
 * beside the editor would re-render on every keystroke (EXT-04 if it is ever
 * wanted); per-tab preview buttons are deferred for the same reason — the
 * tab the admin is looking at is the one they mean.
 */
final class PreviewAction
{
    public static function make(): Action
    {
        return Action::make('preview')
            ->label(__('fin-codex::fin-codex.editor.preview.label'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('fin-codex::fin-codex.editor.preview.close'))
            ->modalHeading(static fn (CreateArticle|EditArticle $livewire): string => (string) __(
                'fin-codex::fin-codex.editor.preview.heading',
                ['locale' => mb_strtoupper(self::locale($livewire))],
            ))
            ->modalContent(static fn (CreateArticle|EditArticle $livewire, ?Article $record): View => view('fin-codex::editor.preview', [
                'html' => self::render($livewire, $record),
                'hasDarkMode' => Filament::getCurrentPanel()?->hasDarkMode() ?? true,
            ]));
    }

    /**
     * The language tab the admin is on. Both pages expose it as a public
     * property because Tabs::livewireProperty() writes it on every tab
     * click; before the first fill it is still null, and the default
     * language is what the form opened on.
     */
    private static function locale(CreateArticle|EditArticle $livewire): string
    {
        $locale = $livewire->activeLocale;

        return ($locale !== null && $locale !== '') ? $locale : TranslationTabs::languages()['default'];
    }

    /**
     * The active tab's current body as safe HTML. The format decides the
     * pipeline: an HTML article is sanitised, a Markdown one is parsed, and
     * a body being typed on the create page has no record at all, so it is
     * Markdown. The slug only affects how relative links are resolved.
     */
    private static function render(CreateArticle|EditArticle $livewire, ?Article $record): string
    {
        $locale = self::locale($livewire);

        $state = $livewire->getSchema('form')?->getRawState() ?? [];
        $body = data_get($state instanceof Arrayable ? $state->toArray() : $state, "translations.{$locale}.body");

        return app(ArticleRenderer::class)->render(
            is_string($body) ? $body : '',
            $record->format ?? ArticleFormat::Markdown,
            $locale,
            $record->slug ?? '',
        )->html;
    }
}
