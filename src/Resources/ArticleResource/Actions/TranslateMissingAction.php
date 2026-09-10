<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Ai\NotificationLocale;
use FinityLabs\FinCodex\Ai\NotificationPanel;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Editor\ArticleTitle;
use FinityLabs\LinCodex\Jobs\TranslateArticle;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Translation\MissingTranslations;

/**
 * Translate missing, the row's second action beside Edit.
 *
 * Edit's neighbour, and the one way to fill every gap of an article without
 * opening the editor at all: the modal lists the languages this article still
 * lacks, pre-checked, and the ticked ones are handed to lin-codex's queued
 * translation job. The work runs in the background and the list's flags show
 * the result on the next load; nothing is translated inline here, unlike the
 * tab's Translate with AI.
 *
 * Only missing languages are ever listed. An outdated translation - one whose
 * default-language source was edited afterwards - is a judgement call about
 * text that already exists, so it is refreshed from the editor, where the
 * admin can read both versions first.
 *
 * Three gates, and Filament ANDs them:
 *
 * - availability. The table asks AiAvailabilityCheck once per build and hands
 *   the answer down as a bool, so every unavailable state takes the button
 *   away and the Help settings page is where the admin reads why. It is a
 *   settings read, and a settings read per row would be four on a four-row
 *   page.
 * - the ability. update on the row's article, through ArticleAbility and the
 *   closure form of authorize, never the string form: Filament unshifts the
 *   action's own record as the gate subject, so only a Closure is gated
 *   against the row it sits on (Phase 8's finding).
 * - something to translate into. MissingTranslations::for() on the same one
 *   instance the table resolved for the whole build, whose candidates are
 *   memoised, so the per-row verdict costs neither a settings query nor a
 *   translations query: the list eager-loads the relation and for() reads it.
 *   An article that lacks nothing shows no button.
 *
 * The verdict the modal shows is the verdict the worker re-checks, so the two
 * agree about what "missing" means and a language filled in between is
 * skipped rather than overwritten.
 *
 * The language list is laid out in three columns, as the bulk action's is: a
 * host with a dozen languages otherwise gets one tall column and a modal that
 * scrolls past its own submit button. An integer is a large-breakpoint count
 * in Filament, so a phone still gets the single column it has room for.
 *
 * Inside the modal, three rules:
 *
 * - at least one language. The pick is required, and the submit button is
 *   never disabled for it: an admin who unticks everything and presses is told
 *   in one sentence what to do, which a greyed button never says. The message
 *   is the field's own, so it appears under the list rather than as a toast.
 * - a blank default language explains itself. Where the editor's tab action
 *   greys its button and hangs a tooltip on it, a row has no room for one, so
 *   the button stays and the modal carries the reason instead: the modal is
 *   the only surface the admin will look at, and an absent button teaches
 *   nothing. The description says which language to fill in first and the
 *   submit button is taken away, so the modal is a message and not a form.
 * - the pick is intersected with the current verdict at submit time. A modal
 *   left open while somebody else finished a language would otherwise queue
 *   it; the job re-checks each locale itself and would skip it, but the
 *   intersection keeps the queue and the report honest in the first place.
 *   Everything ticked already filled means no job and no toast at all. The
 *   accepted values are widened to every configured non-default language for
 *   exactly this reason: a checkbox list validates its pick against the
 *   options it offers right now, and a language filled since the modal opened
 *   is no longer one of them, so the default rule would answer a stale modal
 *   with "the selection is invalid" instead of quietly doing the rest of the
 *   work. A locale that is not a configured language at all is still refused.
 *
 * The toast is a queue-time promise and nothing more: the work has not run
 * yet, so it says the languages will appear on the list when it finishes.
 * Whether the admin also gets a notification when it does is the listener's
 * business, and which queue the job runs on is the job's - nothing here names
 * it. The configured timeout is read for one reason only: on the sync driver
 * the job runs inside this request, so the press raises the execution limit
 * for the calls it is about to set off, exactly as the tab action does for
 * its one call.
 *
 * The press does leave the listener two things: the language this panel is
 * being read in, recorded through NotificationLocale so the notification comes
 * back in it rather than in the application's own, and the panel's own id,
 * recorded through NotificationPanel so the notification is written against
 * this panel's guard and links this panel's edit page. A worker has no panel
 * to ask for either.
 */
final class TranslateMissingAction
{
    /**
     * @param  list<array{code: string, display: string, 'flag-icon': string}>  $languages
     */
    public static function make(bool $available, MissingTranslations $missing, array $languages, string $default): Action
    {
        $displayNames = self::displayNames($languages);
        $defaultDisplay = $displayNames[$default] ?? $default;

        return Action::make('translate_missing')
            ->label(__('fin-codex::fin-codex.editor.translate_missing.label'))
            ->icon(Heroicon::OutlinedSparkles)
            ->visible(static fn (Article $record): bool => $available && $missing->for($record) !== [])
            ->authorize(static fn (Article $record): bool => ArticleAbility::allows('update', $record))
            ->modalHeading(static fn (Article $record): string => (string) __(
                'fin-codex::fin-codex.editor.translate_missing.heading',
                ['title' => ArticleTitle::ofModel($record)],
            ))
            ->modalDescription(static fn (Article $record): string => self::sourceBlank($record, $default)
                ? (string) __('fin-codex::fin-codex.editor.translate_missing.blank_source', ['language' => $defaultDisplay])
                : (string) __('fin-codex::fin-codex.editor.translate_missing.description'))
            ->schema(static fn (Article $record): array => self::sourceBlank($record, $default) ? [] : [
                CheckboxList::make('locales')
                    ->label(__('fin-codex::fin-codex.editor.translate_missing.languages'))
                    ->columns(3)
                    ->options(static fn (Article $record): array => self::options($missing->for($record), $displayNames))
                    ->default(static fn (Article $record): array => $missing->for($record))
                    ->in(static fn (): array => $missing->candidates())
                    ->required()
                    ->validationMessages(['required' => __('fin-codex::fin-codex.editor.translate_missing.pick_one')]),
            ])
            ->modalSubmitAction(static fn (Article $record): ?bool => self::sourceBlank($record, $default) ? false : null)
            ->modalSubmitActionLabel(__('fin-codex::fin-codex.editor.translate_missing.submit'))
            ->action(static function (Article $record, array $data) use ($missing, $default): void {
                // The blank-source modal has no submit button, but a forged
                // press can still reach the call: it queues nothing.
                if (self::sourceBlank($record, $default)) {
                    return;
                }

                /** @var array<int|string, mixed> $picked */
                $picked = (array) ($data['locales'] ?? []);

                $locales = array_values(array_intersect(
                    $missing->for($record),
                    array_map(static fn (mixed $locale): string => (string) $locale, $picked),
                ));

                // Nothing left after the intersection: every ticked language
                // was filled while the modal was open. No job, and no toast
                // promising work that is not happening.
                if ($locales === []) {
                    return;
                }

                // The guard hands back int|string|null; the job wants ?int, and
                // a host on a non-numeric key has no author to attribute.
                $id = Filament::auth()->id();
                $userId = is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);

                // The language the admin is reading the panel in, and the
                // panel itself, so the notification the finished job sends
                // comes back in that language, on that panel's guard and with
                // that panel's edit URL. A worker has neither to ask.
                NotificationLocale::remember();
                NotificationPanel::remember();

                // On the sync driver the job below runs inline in this very
                // request, one call per language, so the press needs the room
                // the tab action gives its single call - times the languages
                // it is queuing. On a real queue this changes nothing.
                TranslateWithAiAction::extendTimeLimit(
                    TranslateWithAiAction::configuredTimeout() * count($locales),
                );

                TranslateArticle::dispatch($record->id, $locales, $userId);

                Notification::make()
                    ->success()
                    ->title(__('fin-codex::fin-codex.editor.translate_missing.queued_title'))
                    ->body(__('fin-codex::fin-codex.editor.translate_missing.queued_body'))
                    ->send();
            });
    }

    /**
     * Whether the default language has nothing to translate from: no row at
     * all, or a row whose title or body is blank. Read off the relation the
     * list eager-loads, the same rule the missing verdict applies to every
     * other language.
     */
    private static function sourceBlank(Article $record, string $default): bool
    {
        $row = $record->translations->firstWhere('locale', $default);

        return ! $row instanceof ArticleTranslation || blank($row->title) || blank($row->body);
    }

    /**
     * Every configured language by code, named the way the admin arranged it,
     * falling back to the code when the entry carries no display name.
     *
     * @param  list<array{code: string, display: string, 'flag-icon': string}>  $languages
     *
     * @return array<string, string>
     */
    private static function displayNames(array $languages): array
    {
        $names = [];

        foreach ($languages as $language) {
            $names[$language['code']] = $language['display'] !== '' ? $language['display'] : $language['code'];
        }

        return $names;
    }

    /**
     * The checkbox options: the missing locales only, keeping the settings
     * order for() already hands them back in.
     *
     * @param  list<string>  $missingLocales
     * @param  array<string, string>  $displayNames
     *
     * @return array<string, string>
     */
    private static function options(array $missingLocales, array $displayNames): array
    {
        $options = [];

        foreach ($missingLocales as $locale) {
            $options[$locale] = $displayNames[$locale] ?? $locale;
        }

        return $options;
    }
}
