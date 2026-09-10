<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Ai\NotificationLocale;
use FinityLabs\FinCodex\Ai\NotificationPanel;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\LinCodex\Jobs\TranslateArticle;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Translation\MissingTranslations;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Translate missing, the table's first and only bulk action.
 *
 * It is the list's answer to "fill every gap in the selection": tick the
 * articles, press once, and every language any of them still lacks is queued.
 * No editor is opened and nothing is translated inline - that is the tab's
 * Translate with AI - so the modal is a language picker and the work happens
 * in the background.
 *
 * "Every language pre-checked" is not "every language written". The picker
 * offers every configured non-default language, all ticked, and each article
 * then receives only what IT lacks among the ticked ones; an article that
 * lacks none of them is left alone. That is why the same press can be right
 * for a selection whose articles are missing completely different languages.
 *
 * Two reads, no query per row:
 *
 * - the candidates come from the languages the table already loaded once per
 *   build, so the modal costs no settings query of its own. (The row action's
 *   modal lists one article's gaps instead; these are deliberately different
 *   lists.)
 * - the gaps come from MissingTranslations::for() on the eager-loaded
 *   translations relation. The selection is fetched through the table's own
 *   query, so with('translations') has already run for the whole selection.
 *
 * The language list is laid out in three columns, as the row action's is. This
 * one offers EVERY configured non-default language rather than one article's
 * gaps, so it is the longer of the two and a single column left the modal
 * scrolling past its own submit button. An integer is a large-breakpoint count
 * in Filament, so a phone still gets the single column it has room for.
 *
 * Availability is a plain bool handed down from the table: a bulk action has
 * no per-record gate to hang it on, and hiding it also takes the checkbox
 * column away, so with AI unavailable the list renders exactly as it did
 * before this slot existed.
 *
 * The per-article ability check lives inside the loop, through ArticleAbility
 * and the closure form of authorize, rather than in Filament's
 * individual-record authorization: that helper filters the records away before
 * the loop ever sees them and reports through a notification of its own, which
 * would take the not-permitted count out of the summary and replace the
 * wording with Filament's. The summary is the whole point, so the loop keeps
 * the count.
 *
 * No delete action sits beside it, on the row or in bulk, for the reason the
 * table's own docblock gives: deleting an article has consequences the admin
 * must see first, so it lives on the edit page behind its confirmation modal.
 *
 * There is no cap on the selection size. One job per article is the unit of
 * work, and the queue is what a queue is for.
 *
 * The press counts three things and reports two of them. Queued and "needed
 * nothing" are the normal summary, always both, because a selection made from
 * a list of flags routinely contains articles that are already complete and an
 * admin who ticked twenty rows wants to know that nine of them were fine. The
 * not-permitted count is a third sentence and appears only when it is not
 * zero: a summary that ends "and 0 were skipped" on every press trains the
 * admin to stop reading it.
 *
 * The ticked languages are intersected with each article's CURRENT gaps rather
 * than queued as they were picked, so a modal left open while somebody else
 * filled a language in never queues that language again. The job re-checks
 * per locale anyway - it runs on a freshly fetched article - so this is the
 * cheaper half of the same promise, not the only one.
 *
 * The language this panel is being read in, and the panel itself, are recorded
 * once for the press, before the loop, rather than once per article: both are
 * facts about the request, not about any one job, and every job pushed after
 * them carries them. The listener renders the finished run's notification in
 * that language, writes it against that panel's guard and links that panel's
 * edit page, so a panel read in one language is not answered in another and a
 * press made on one panel is not reported through the default one.
 *
 * The row action's blank-source rule has no counterpart here. That one has a
 * single article in front of it and can say which language to fill in first;
 * a selection may hold twenty, so this action does not judge any article's
 * source. An article whose default language is empty is queued like the rest
 * and the job fails those locales with a reason of its own.
 */
final class TranslateMissingBulkAction
{
    /**
     * @param  list<array{code: string, display: string, 'flag-icon': string}>  $languages
     */
    public static function make(bool $available, MissingTranslations $missing, array $languages, string $default): BulkAction
    {
        $candidates = self::candidates($languages, $default);

        return BulkAction::make('translate_missing')
            ->label(__('fin-codex::fin-codex.editor.translate_missing.bulk_label'))
            ->icon(Heroicon::OutlinedSparkles)
            ->visible($available)
            ->modalHeading(__('fin-codex::fin-codex.editor.translate_missing.bulk_heading'))
            ->modalDescription(static fn (EloquentCollection $records): string => trans_choice(
                'fin-codex::fin-codex.editor.translate_missing.bulk_description',
                $records->count(),
                ['count' => $records->count()],
            ))
            ->schema([
                CheckboxList::make('locales')
                    ->label(__('fin-codex::fin-codex.editor.translate_missing.languages'))
                    ->columns(3)
                    ->options($candidates)
                    ->default(array_keys($candidates))
                    ->required()
                    ->validationMessages(['required' => __('fin-codex::fin-codex.editor.translate_missing.pick_one')]),
            ])
            ->modalSubmitActionLabel(__('fin-codex::fin-codex.editor.translate_missing.submit'))
            ->action(
                /**
                 * @param  array<string, mixed>  $data
                 */
                static function (EloquentCollection $records, array $data) use ($missing): void {
                    // The selection is fetched through the table's own query,
                    // so these are Articles with their translations loaded.
                    /** @var EloquentCollection<int, Article> $records */
                    $picked = self::picked($data);
                    $id = Filament::auth()->id();
                    $userId = is_numeric($id) ? (int) $id : null;

                    NotificationLocale::remember();
                    NotificationPanel::remember();

                    /*
                     * On the sync driver every job below runs inline in this
                     * request, one call per language per article, so the press
                     * needs the room the tab action gives its single call -
                     * times the ceiling of what it can set off here, every
                     * selected article against every ticked language. The
                     * exact gaps are only known inside the loop, and the
                     * helper only ever raises a limit, never lowers one.
                     */
                    TranslateWithAiAction::extendTimeLimit(
                        TranslateWithAiAction::configuredTimeout() * $records->count() * count($picked),
                    );

                    $queued = 0;
                    $nothing = 0;
                    $notPermitted = 0;

                    foreach ($records as $article) {
                        if (! ArticleAbility::allows('update', $article)) {
                            $notPermitted++;

                            continue;
                        }

                        $locales = array_values(array_intersect($missing->for($article), $picked));

                        if ($locales === []) {
                            $nothing++;

                            continue;
                        }

                        TranslateArticle::dispatch($article->id, $locales, $userId);
                        $queued++;
                    }

                    $body = (string) __('fin-codex::fin-codex.editor.translate_missing.bulk_summary', [
                        'queued' => $queued,
                        'nothing' => $nothing,
                    ]);

                    if ($notPermitted > 0) {
                        $body .= ' '.trans_choice(
                            'fin-codex::fin-codex.editor.translate_missing.bulk_not_permitted',
                            $notPermitted,
                            ['count' => $notPermitted],
                        );
                    }

                    Notification::make()
                        ->success()
                        ->title(__('fin-codex::fin-codex.editor.translate_missing.queued_title'))
                        ->body($body)
                        ->send();
                },
            )
            ->deselectRecordsAfterCompletion();
    }

    /**
     * The locale codes the admin left ticked. Anything that is not a string is
     * dropped rather than cast: the only source of these values is the
     * CheckboxList's own option keys, so a non-string is a tampered request,
     * not a language.
     *
     * @param  array<string, mixed>  $data
     *
     * @return list<string>
     */
    private static function picked(array $data): array
    {
        $picked = [];

        foreach ((array) ($data['locales'] ?? []) as $locale) {
            if (is_string($locale)) {
                $picked[] = $locale;
            }
        }

        return $picked;
    }

    /**
     * Every configured language except the default one, in settings order, as
     * code => display. A language whose display name was never filled in falls
     * back to its code, so the picker never renders an empty label.
     *
     * @param  list<array{code: string, display: string, 'flag-icon': string}>  $languages
     *
     * @return array<string, string>
     */
    private static function candidates(array $languages, string $default): array
    {
        $candidates = [];

        foreach ($languages as $language) {
            if ($language['code'] === $default) {
                continue;
            }

            $candidates[$language['code']] = $language['display'] !== '' ? $language['display'] : $language['code'];
        }

        return $candidates;
    }
}
