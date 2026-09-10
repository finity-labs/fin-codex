<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Ai\AiSettings;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Translation\ArticleTranslator;

/**
 * Copy from default's twin, with a translation in the middle.
 *
 * Same place in the tab, same gray button, same three fields: title, excerpt
 * and body are read off the *unsaved* default-language tab and written to this
 * one. The admin has usually just written the English text; reading the stored
 * row would translate whatever was saved last time and ignore the edit in
 * front of them. Nothing is saved here either - no translation row, no
 * revision, no marker on the tab - and the tab the admin was on stays open,
 * because the action never touches activeLocale.
 *
 * Three gates, and Filament ANDs them:
 *
 * - availability. The schema asks AiAvailabilityCheck once per build and hands
 *   the answer down as a bool, so all four unavailable states (no SDK, an
 *   unseeded settings group, the toggle off, no key) take the button away
 *   entirely. The Help settings page is where the admin reads why.
 * - the ability. update for an article being edited, create on the create
 *   page, both through ArticleAbility so a host policy that stops at the five
 *   standard methods still answers. authorize() takes a Closure and must keep
 *   taking one: Filament unshifts the action's own record as the gate subject,
 *   so the string form is gated against whatever record the action happens to
 *   carry - Phase 8's finding, and the reason no gate in this package is ever
 *   handed a plain ability name. The ?Article is nullable because the create
 *   page has no record yet.
 * - a source worth translating. While the default tab's live title or body is
 *   blank the button is there but disabled, with a tooltip naming the language
 *   to fill in first. Filament leaves the HTML disabled attribute off when a
 *   tooltip is present so the hint is reachable on hover, and refuses to mount
 *   or call the action on the server either way.
 *
 * An HTML article gets no button at all: its body is a read-only textarea
 * until the convert action turns it into Markdown, so there would be nothing
 * to write the translation into.
 *
 * The confirmation is conditional, which is what modal() is for: a tab that
 * already holds a title, an excerpt or a body asks first, naming the language
 * pair, and an empty one runs straight through on the button's loading state.
 * requiresConfirmation() alone would open the modal on every press, because a
 * custom heading counts as a modal of its own.
 *
 * A failure is a value, never an exception - lin-codex reduces everything to
 * an AiReason key before this class sees it - so the tab keeps the text it
 * had and the reason is shown as the notification body, with a pointer to Help
 * settings for the two states the admin can fix there. Nothing is reported
 * from here: since lin-codex 0.3.1 the throwable behind an `unknown` verdict is
 * handed to the host's error tooling once, at the seam that could not name it,
 * so this class only shows the label.
 */
final class TranslateWithAiAction
{
    public static function make(string $code, string $default, string $display, string $defaultDisplay, bool $available): Action
    {
        return Action::make('translate_with_ai')
            ->label(__('fin-codex::fin-codex.editor.translate.label'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->extraAttributes(['data-fin-codex-translate' => $code])
            ->visible($available && $code !== $default)
            ->authorize(static fn (?Article $record): bool => $record instanceof Article
                ? ArticleAbility::allows('update', $record)
                : ArticleAbility::allows('create'))
            ->disabled(static fn (Get $get): bool => self::sourceBlank($get, $default))
            ->tooltip(static fn (Get $get): ?string => self::sourceBlank($get, $default)
                ? (string) __('fin-codex::fin-codex.editor.translate.empty_source', ['language' => $defaultDisplay])
                : null)
            ->requiresConfirmation()
            ->modal(static fn (Get $get): bool => filled($get("translations.{$code}.title"))
                || filled($get("translations.{$code}.excerpt"))
                || filled($get("translations.{$code}.body")))
            ->modalHeading(__('fin-codex::fin-codex.editor.translate.heading', ['language' => $display]))
            ->modalDescription(__('fin-codex::fin-codex.editor.translate.description', [
                'language' => $display,
                'default' => $defaultDisplay,
            ]))
            ->modalSubmitActionLabel(__('fin-codex::fin-codex.editor.translate.submit'))
            ->action(static function (Get $get, Set $set) use ($code, $default, $display): void {
                self::extendTimeLimit(self::configuredTimeout());

                $excerpt = $get("translations.{$default}.excerpt");

                $result = app(ArticleTranslator::class)->translateText(
                    (string) $get("translations.{$default}.title"),
                    is_string($excerpt) && $excerpt !== '' ? $excerpt : null,
                    (string) $get("translations.{$default}.body"),
                    $code,
                    $default,
                );

                if (! $result->ok) {
                    $body = $result->reasonLabel();

                    if (in_array($result->reason, [AiReason::AUTHENTICATION_FAILED, AiReason::UNAVAILABLE], true)) {
                        $body .= ' '.__('fin-codex::fin-codex.editor.translate.check_settings');
                    }

                    Notification::make()
                        ->danger()
                        ->title(__('fin-codex::fin-codex.editor.translate.failed'))
                        ->body($body)
                        ->send();

                    return;
                }

                /*
                 * All three, always. A partial fill would leave the tab half in
                 * one language and half in another; a null excerpt is the
                 * translator's never-invent rule reporting a blank source one,
                 * and clearing the field is the honest answer to it.
                 */
                $set("translations.{$code}.title", $result->title);
                $set("translations.{$code}.excerpt", $result->excerpt);
                $set("translations.{$code}.body", $result->body);

                Notification::make()
                    ->success()
                    ->title(__('fin-codex::fin-codex.editor.translate.done', ['language' => $display]))
                    ->send();
            });
    }

    /**
     * What one call is allowed to take, in seconds: the configured timeout,
     * falling back to the package default when the settings group is unseeded
     * or holds something that is not a number.
     *
     * Shared with the two list actions, which are given the same helper below
     * multiplied by the number of calls a press can set off - on the sync
     * driver their queued jobs run inline in the same request this one does.
     */
    public static function configuredTimeout(): int
    {
        $timeout = AiSettings::values()['timeout'] ?? 120;

        return is_numeric($timeout) ? (int) $timeout : 120;
    }

    /**
     * Give the call room to finish, and never take any away.
     *
     * On Linux PHP's max_execution_time counts script CPU time, so a request
     * waiting on an HTTP response does not spend it and this changes nothing;
     * on a Windows host the limit is wall clock, and a 30-second default would
     * kill a 120-second translation halfway. An unlimited 0 is left alone and a
     * limit already longer than the call is never shortened. The real ceiling
     * on every platform is the web server's own read timeout, which the README
     * covers.
     */
    public static function extendTimeLimit(int $timeout): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && $limit < $timeout + 30) {
            set_time_limit($timeout + 30);
        }
    }

    /**
     * Whether the default tab is missing something to translate, read from
     * live form state the way the Missing badge is. Get resolves against the
     * form root: neither Tabs nor Tab nor the actions row carries a state path
     * of its own.
     */
    private static function sourceBlank(Get $get, string $default): bool
    {
        return blank($get("translations.{$default}.title")) || blank($get("translations.{$default}.body"));
    }
}
