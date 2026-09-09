<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Pages;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Traits\HasPageShieldSupport;
use FinityLabs\LinCodex\Enums\FallbackBehaviour;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use UnitEnum;

/**
 * The Codex settings screen: languages, reading behaviour and revisions.
 *
 * FinCodexPlugin::register() puts this class on every panel that carries the
 * plugin, unless the host named its own through settingsPage(); such an
 * override extends this class, which is why the class is not final — the same
 * reason Resources\ArticleResource is not. The slug is inherited, so each
 * panel gets its own filament.{panel}.pages.help-settings route.
 *
 * Navigation placement is not the page's business: the group comes from the
 * panel's own plugin options and the sort is that panel's article-resource
 * sort plus one, so the page always files directly after Help articles and a
 * host that never set a sort keeps Filament's label ordering.
 *
 * Access goes through HasPageShieldSupport: Shield's own permission when
 * Shield is installed, the opt-in Gate ability page_HelpSettings when it is
 * not, and open to any panel user otherwise.
 */
class HelpSettings extends SettingsPage
{
    use HasPageShieldSupport;

    protected static string $settings = CodexSettings::class;

    protected static ?string $slug = 'help-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FinCodexPlugin::get()->getNavigationGroup();
    }

    /**
     * One slot after the article resource, which reads the same single
     * navigationSort() option. The page adds one to whatever the host set and
     * stays null when the host set nothing, in which case Filament sorts by
     * label.
     */
    public static function getNavigationSort(): ?int
    {
        $sort = FinCodexPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 1;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('fin-codex::fin-codex.settings.navigation');
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('fin-codex::fin-codex.settings.title');
    }

    public function getSavedNotificationTitle(): ?string
    {
        return (string) __('fin-codex::fin-codex.settings.saved');
    }

    /**
     * How many translations are written in one language.
     *
     * Asked once per language row on a render and once per removed language on
     * a confirmation — a handful of counts either way. The query goes through
     * the core's model, so no table name is spelled out here.
     */
    public static function translationCount(string $code): int
    {
        if ($code === '') {
            return 0;
        }

        return ArticleTranslation::query()->where('locale', $code)->count();
    }

    /**
     * Three stacked sections. defaultForm() already applies columns(2) and
     * statePath('data'), so neither is repeated here.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('fin-codex::fin-codex.settings.languages.section'))
                ->description(__('fin-codex::fin-codex.settings.languages.description'))
                ->columnSpanFull()
                ->schema([
                    Repeater::make('languages')
                        ->hiddenLabel()
                        ->addActionLabel(__('fin-codex::fin-codex.settings.languages.add'))
                        ->minItems(1)
                        ->columns(4)
                        ->schema([
                            TextInput::make('code')
                                ->label(__('fin-codex::fin-codex.settings.languages.code'))
                                ->required()
                                ->maxLength(10)
                                // Live so the default-language select below and the
                                // translation count beside it follow the list as it is
                                // edited, without a save.
                                ->live(onBlur: true),
                            TextInput::make('display')
                                ->label(__('fin-codex::fin-codex.settings.languages.display'))
                                ->required()
                                ->maxLength(60),
                            TextInput::make('flag-icon')
                                ->label(__('fin-codex::fin-codex.settings.languages.flag'))
                                ->maxLength(4)
                                ->helperText(__('fin-codex::fin-codex.settings.languages.flag_help')),
                            // What this language would cost to remove, read from the
                            // row's own code rather than from the stored settings, so
                            // it is right while the list is being edited. One count
                            // per row per render on a list that is two to five rows
                            // long; memoising it would make the number lie the moment
                            // a code is retyped.
                            TextEntry::make('translations_count')
                                ->label(__('fin-codex::fin-codex.settings.languages.translations'))
                                ->state(fn (Get $get): string => (string) self::translationCount((string) ($get('code') ?? '')))
                                ->extraAttributes(fn (Get $get): array => [
                                    'data-fin-codex-language-count' => (string) ($get('code') ?? '').':'.self::translationCount((string) ($get('code') ?? '')),
                                ]),
                        ])
                        ->rules([
                            // Filament evaluates a Closure handed to rules() as a rule
                            // FACTORY with its own dependency injection, so a Laravel
                            // closure rule has to be RETURNED from a closure (the same
                            // finding SlugRules carries). Get is injected by type and
                            // resolves against the repeater's own container, which is
                            // the form root — so 'default_locale' is the sibling field.
                            static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $codes = collect(is_array($value) ? $value : [])
                                    ->pluck('code')
                                    ->filter()
                                    ->all();

                                $default = $get('default_locale');

                                if (filled($default) && ! in_array($default, $codes, true)) {
                                    $fail((string) __('fin-codex::fin-codex.settings.default_locale_removed', [
                                        'locale' => $default,
                                    ]));
                                }
                            },
                        ]),
                ]),

            Section::make(__('fin-codex::fin-codex.settings.reading.section'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('default_locale')
                        ->label(__('fin-codex::fin-codex.settings.reading.default_locale'))
                        ->helperText(__('fin-codex::fin-codex.settings.reading.default_locale_help'))
                        ->options(fn (Get $get): array => self::languageOptions($get))
                        ->live()
                        ->required(),
                    Select::make('fallback')
                        ->label(__('fin-codex::fin-codex.settings.reading.fallback'))
                        // The core enum's own labels; fin-codex never redeclares them.
                        ->options(collect(FallbackBehaviour::cases())
                            ->mapWithKeys(fn (FallbackBehaviour $case): array => [$case->value => $case->label()])
                            ->all())
                        ->required(),
                ]),

            Section::make(__('fin-codex::fin-codex.settings.revisions.section'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Toggle::make('revisions_enabled')
                        ->label(__('fin-codex::fin-codex.settings.revisions.enabled'))
                        ->helperText(__('fin-codex::fin-codex.settings.revisions.enabled_help')),
                    TextInput::make('revisions_keep')
                        ->label(__('fin-codex::fin-codex.settings.revisions.keep'))
                        ->helperText(__('fin-codex::fin-codex.settings.revisions.keep_help'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(999)
                        ->required(),
                ]),
        ]);
    }

    /**
     * GUARD 1 — the page has to open on an installation that has never stored a
     * setting. The vendor version calls toArray() unguarded, and CodexSettings
     * declares no PHP property defaults, so an unwritten group throws
     * MissingSettings before a single field renders.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill($this->mutateFormDataBeforeFill($this->settingsData()));

        $this->callHook('afterFill');
    }

    /**
     * The stored values, or CodexSettings::defaults() when the group has never
     * been written or the settings table is not there yet. Visiting the page
     * writes nothing — the same rule TranslationTabs::languages() follows.
     *
     * @return array<string, mixed>
     */
    private function settingsData(): array
    {
        try {
            return app(CodexSettings::class)->toArray();
        } catch (MissingSettings|QueryException) {
            return CodexSettings::defaults();
        }
    }

    /**
     * GUARD 2 — the first save creates all five rows.
     *
     * parent::save() calls Settings::fill(), which assigns through __set() and
     * loads from the database on the first assignment, so an unwritten group
     * throws MissingSettings again. new CodexSettings($values) loads from the
     * array instead, which leaves the instance "loaded" without a query; the
     * repository's write is an upsert, so the missing rows are created. The
     * container instance lives for this request only.
     */
    public function save(): void
    {
        try {
            app(CodexSettings::class)->toArray();
        } catch (MissingSettings|QueryException) {
            $seed = CodexSettings::defaults();
            $seed['fallback'] = FallbackBehaviour::from((int) $seed['fallback']);

            app()->instance(CodexSettings::class, new CodexSettings($seed));
        }

        parent::save();
    }

    /**
     * The save button, replaced so that it can ask first.
     *
     * SettingsPage's own button cannot carry a modal in either of its two
     * modes: with the form wrapper it renders type="submit" and no wire:click
     * at all, and without it a string action() short-circuits
     * getLivewireClickHandler() into a direct method call. requiresConfirmation()
     * on that button does nothing. A plain Action whose action() is a CLOSURE
     * renders a mountAction() handler, and the confirmation works.
     *
     * modal() is the switch that matters, not requiresConfirmation(): Filament
     * opens a modal for any action with a custom heading, whatever the
     * confirmation flag says, so without it every save — an untouched form
     * included — opened an empty "Remove a language?" box. With it, a save
     * that removes nothing runs straight through.
     *
     * The modal carries one checkbox, on by default: keep the translations.
     * Unticked, the save deletes every translation and revision in the
     * removed languages, in the same transaction as the settings write.
     *
     * Everything else is the vendor's button, down to its own label key and
     * mod+s, so a host's muscle memory still works.
     *
     * @return array<Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('filament-spatie-laravel-settings-plugin::pages/settings-page.form.actions.save.label'))
                ->keyBindings(['mod+s'])
                ->visible($this->canEdit())
                // Only when something is going away — a confirmation on every
                // save is a speed bump, not a warning.
                ->modal(fn (): bool => $this->removedLanguages() !== [])
                ->requiresConfirmation()
                ->modalHeading(__('fin-codex::fin-codex.settings.removal.heading'))
                ->modalDescription(fn (): ?Htmlable => $this->removalDescription())
                ->modalSubmitActionLabel(__('fin-codex::fin-codex.settings.removal.submit'))
                ->schema([
                    Checkbox::make('keep_translations')
                        ->label(__('fin-codex::fin-codex.settings.removal.keep_translations'))
                        ->helperText(__('fin-codex::fin-codex.settings.removal.keep_translations_help'))
                        ->default(true),
                ])
                // A closure, never the string 'save': the string is what puts
                // you back on a button that cannot confirm.
                ->action(fn (array $data) => $this->saveRemovingLanguages($data)),
        ];
    }

    /**
     * The save, plus the deletion the modal's checkbox asked for. One
     * transaction: a validation failure inside save() (the default language
     * being removed, say) rolls the deletion back with it, and a deletion
     * failure leaves the settings as they were.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveRemovingLanguages(array $data): void
    {
        $removed = $this->removedLanguages();
        $keep = (bool) ($data['keep_translations'] ?? true);

        DB::transaction(function () use ($removed, $keep): void {
            $this->save();

            if ($removed === [] || $keep) {
                return;
            }

            ArticleRevision::query()->whereIn('locale', $removed)->delete();
            ArticleTranslation::query()->whereIn('locale', $removed)->delete();
        });

        if ($removed !== [] && ! $keep) {
            Notification::make()
                ->warning()
                ->title(__('fin-codex::fin-codex.settings.removal.deleted', ['locales' => implode(', ', $removed)]))
                ->send();
        }
    }

    /**
     * The removed languages of this request, computed once: the save button's
     * confirmation and the modal's description both read it, and they must
     * agree — a confirmation whose description names nothing is a question
     * nobody can answer.
     *
     * @var list<string>|null
     */
    private ?array $removedLanguagesMemo = null;

    /**
     * The stored language codes that are no longer in the form's live state.
     *
     * getRawState() is the unvalidated state — literally what is about to be
     * saved — which is the only honest source for a warning that has to appear
     * before validation runs. It is the same accessor PreviewAction reads.
     * Codes are compared trimmed and lower-cased, so retyping "EN" over "en"
     * is not a removal. Nothing is stored on an installation that has never
     * saved, so the rescued fallback is an empty list and no save is ever a
     * removal there.
     *
     * @return list<string>
     */
    public function removedLanguages(): array
    {
        return $this->removedLanguagesMemo ??= $this->computeRemovedLanguages();
    }

    /**
     * @return list<string>
     */
    private function computeRemovedLanguages(): array
    {
        /** @var list<string> $stored */
        $stored = rescue(
            fn (): array => collect(app(CodexSettings::class)->languages)
                ->pluck('code')
                ->filter()
                ->values()
                ->all(),
            [],
            report: false,
        );

        /** @var array<mixed> $rows */
        $rows = $this->form->getRawState()['languages'] ?? [];

        $current = collect($rows)
            ->pluck('code')
            ->filter()
            ->map(static fn (mixed $code): string => mb_strtolower(trim((string) $code)))
            ->all();

        $removed = array_values(array_filter(
            $stored,
            static fn (string $code): bool => ! in_array(mb_strtolower(trim($code)), $current, true),
        ));

        return $removed;
    }

    /**
     * The languages going away, one line each with what they hold; the
     * checkbox beneath says what happens to the texts.
     *
     * The lines are escaped and joined with <br> rather than newlines, because
     * a modal description renders as one paragraph and three languages on one
     * run-on line is exactly the thing this warning exists to avoid.
     */
    private function removalDescription(): ?Htmlable
    {
        $removed = $this->removedLanguages();

        if ($removed === []) {
            return null;
        }

        $lines = [(string) __('fin-codex::fin-codex.settings.removal.intro')];

        foreach ($removed as $code) {
            $lines[] = (string) __('fin-codex::fin-codex.settings.removal.row', [
                'locale' => $code,
                'count' => self::translationCount($code),
            ]);
        }

        return new HtmlString(implode('<br>', array_map(fn (string $line): string => e($line), $lines)));
    }

    /**
     * The enum arrives as a FallbackBehaviour instance from toArray(). The
     * Select would flatten it to its backing value on its own; doing it here
     * keeps the form state plain scalars and mirrors the save mutator, so the
     * pair reads as one round trip rather than as one half of it.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $fallback = $data['fallback'] ?? null;
        $data['fallback'] = $fallback instanceof FallbackBehaviour ? $fallback->value : $fallback;

        return $data;
    }

    /**
     * Both casts are load-bearing. Settings::fill() assigns straight onto a
     * property typed FallbackBehaviour, and SettingsMapper's enum cast throws
     * "Invalid enum" for anything that is not a BackedEnum; TextInput::numeric()
     * hands back a float, which a typed int property refuses.
     *
     * Languages need no mutation in either direction: the repeater dehydrates
     * to a clean list of {code, display, flag-icon}, which is exactly the shape
     * CodexSettings::$languages declares.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['fallback'] = FallbackBehaviour::from((int) $data['fallback']);
        $data['revisions_keep'] = (int) $data['revisions_keep'];

        return $data;
    }

    /**
     * The default-language options, read live from the languages repeater:
     * code => display, falling back to the code, with blank codes dropped so a
     * half-typed new row does not offer an empty option.
     *
     * @return array<string, string>
     */
    private static function languageOptions(Get $get): array
    {
        $languages = $get('languages');

        return collect(is_array($languages) ? $languages : [])
            ->mapWithKeys(function (mixed $row): array {
                $code = is_array($row) ? (string) ($row['code'] ?? '') : '';
                $display = is_array($row) ? (string) ($row['display'] ?? '') : '';

                return [$code => $display === '' ? $code : $display];
            })
            ->filter(fn (string $label, string $code): bool => $code !== '')
            ->all();
    }
}
