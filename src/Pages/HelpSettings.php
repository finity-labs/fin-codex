<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Pages;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinCodex\Ai\AiSettings;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinSupport\Pages\Concerns\HasPageShieldSupport;
use FinityLabs\LinCodex\Ai\AiAvailability;
use FinityLabs\LinCodex\Ai\AiAvailabilityCheck;
use FinityLabs\LinCodex\Ai\AiCallFailed;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Ai\Contracts\AiClient;
use FinityLabs\LinCodex\Ai\ProviderCatalog;
use FinityLabs\LinCodex\Enums\FallbackBehaviour;
use FinityLabs\LinCodex\Models\ArticleRevision;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Translation\DefaultInstructions;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use UnitEnum;

/**
 * The Codex settings screen: languages, reading behaviour, revisions and AI
 * translation.
 *
 * FinCodexPlugin::register() puts this class on every panel that carries the
 * plugin, unless the host named its own through settingsPage(); such an
 * override extends this class, which is why the class is not final — the same
 * reason Resources\ArticleResource is not. The slug is inherited, so each
 * panel gets its own filament.{panel}.pages.codex-settings route.
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

    protected static ?string $slug = 'codex-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /**
     * The AI half of the form state, carried from the save mutator to
     * afterSave().
     *
     * Private on purpose: Livewire hydrates public properties only, and the
     * value has to live for exactly one request — from the moment the AI keys
     * are stripped out of the CodexSettings payload to the moment they are
     * written to their own group.
     *
     * @var array<string, mixed>|null
     */
    private ?array $aiData = null;

    /**
     * The provider's tier models, once per provider per request.
     *
     * The model select, the model text input and the Test connection notice
     * all ask for them on the same render; an empty array is the "the seam
     * cannot list them" answer and is memoised too.
     *
     * @var array<string, array<string, string>>
     */
    private array $tierMemo = [];

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FinCodexPlugin::get()->getNavigationGroup();
    }

    /**
     * The last of the three: two slots after the article resource, one after
     * the coverage page, all reading the panel's single navigationSort()
     * option. With none set the three still sit in that order — 1, 2, 3 —
     * rather than in whatever order the translated labels happen to sort.
     */
    public static function getNavigationSort(): ?int
    {
        return (FinCodexPlugin::get()->getNavigationSort() ?? 1) + 2;
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
     * Four stacked sections. defaultForm() already applies columns(2) and
     * statePath('data'), so neither is repeated here.
     *
     * The fourth reads a second settings group, lin-codex-ai, which the three
     * hooks at the bottom of this class fill and save; the AI keys live under
     * one "ai" key in the form state so the CodexSettings payload keeps its
     * own five.
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
                        ->native(false)->preload()->searchable(false)
                        ->helperText(__('fin-codex::fin-codex.settings.reading.default_locale_help'))
                        ->options(fn (Get $get): array => self::languageOptions($get))
                        ->live()
                        ->required(),
                    Select::make('fallback')
                        ->label(__('fin-codex::fin-codex.settings.reading.fallback'))
                        ->native(false)->preload()->searchable(false)
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

            Section::make(__('fin-codex::fin-codex.settings.ai.section'))
                ->description(__('fin-codex::fin-codex.settings.ai.description'))
                ->columnSpanFull()
                ->columns(2)
                ->extraAttributes(['data-fin-codex-ai' => 'section'])
                // One section either way: without the optional SDK the page
                // says what to install and nothing else about AI. The seam is
                // a container singleton, so this is one call per render.
                ->schema(fn (): array => app(AiClient::class)->installed()
                    ? $this->aiFields()
                    : [Text::make(__('fin-codex::fin-codex.settings.ai.install_note'))->columnSpanFull()]),
        ]);
    }

    /**
     * The AI section's own components, in reading order: what the state of AI
     * is, the switch, who translates, with which model, on which key, how long
     * a call may take and what to tell the model.
     *
     * @return list<Component>
     */
    private function aiFields(): array
    {
        return [
            // No memo: the closure has to re-read after save() re-renders the
            // page, and a value cached during the pre-save render would report
            // the state the admin has just changed away from.
            Text::make(fn (): string => $this->aiStatus()->available
                ? (string) __('fin-codex::fin-codex.settings.ai.status_available')
                : $this->aiStatus()->label())
                ->icon(fn (): Heroicon => $this->aiStatus()->available
                    ? Heroicon::OutlinedCheckCircle
                    : Heroicon::OutlinedExclamationTriangle)
                ->color(fn (): string => $this->aiStatus()->available ? 'success' : 'warning')
                ->extraAttributes(['data-fin-codex-ai' => 'status'])
                ->columnSpanFull(),

            Toggle::make('ai.enabled')
                ->label(__('fin-codex::fin-codex.settings.ai.enabled'))
                ->helperText(__('fin-codex::fin-codex.settings.ai.enabled_help'))
                // Live so the required marks on the provider and the key follow
                // the switch without a save.
                ->live()
                ->columnSpanFull(),

            // A stored provider the installed SDK no longer offers renders as a
            // blank select; required-while-enabled is what stops that save.
            Select::make('ai.provider')
                ->label(__('fin-codex::fin-codex.settings.ai.provider'))
                ->native(false)->preload()->searchable(false)
                ->options(fn (): array => app(AiClient::class)->providers())
                ->live()
                ->required(fn (Get $get): bool => (bool) $get('ai.enabled'))
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    [$choice, $model] = $this->defaultTierFor((string) $state);

                    $set('ai.model_choice', $choice);
                    $set('ai.model', $model);
                }),

            // The view over ai.model: never dehydrated, so the concrete id in
            // the text input below is the one and only stored value.
            Select::make('ai.model_choice')
                ->label(__('fin-codex::fin-codex.settings.ai.model'))
                ->native(false)->preload()->searchable(false)
                ->live()
                ->dehydrated(false)
                ->options(fn (Get $get): array => $this->tierOptions((string) $get('ai.provider')))
                ->visible(fn (Get $get): bool => $this->tierOptions((string) $get('ai.provider')) !== [])
                ->afterStateUpdated(fn (?string $state, Set $set) => $set('ai.model', $state === 'custom' ? null : $state)),

            // The dehydrate-when-hidden call below is load-bearing: a hidden
            // field is not dehydrated by default, so a chosen tier would be
            // dropped on save the moment the select hides this input.
            TextInput::make('ai.model')
                ->label(__('fin-codex::fin-codex.settings.ai.model_id'))
                ->maxLength(120)
                ->dehydratedWhenHidden()
                ->visible(fn (Get $get): bool => $get('ai.model_choice') === 'custom'
                    || $this->tierOptions((string) $get('ai.provider')) === []),

            TextInput::make('ai.api_key')
                ->label(__('fin-codex::fin-codex.settings.ai.api_key'))
                ->password()
                ->revealable()
                ->maxLength(255)
                // Read from storage, never from the form: the field itself is
                // blanked on fill and a blank save keeps what is stored.
                ->placeholder(fn (): string => AiSettings::storedApiKey() !== null
                    ? (string) __('fin-codex::fin-codex.settings.ai.key_stored')
                    : (string) __('fin-codex::fin-codex.settings.ai.key_missing'))
                ->helperText(fn (Get $get): ?string => ProviderCatalog::isKeyless((string) $get('ai.provider'))
                    ? (string) __('fin-codex::fin-codex.settings.ai.ollama_help')
                    : null)
                // Asked for only when there is nothing else to reach the
                // provider with: no key of its own in storage, and none in the
                // SDK's own config for it.
                ->required(function (Get $get): bool {
                    $provider = (string) ($get('ai.provider') ?? '');

                    return (bool) $get('ai.enabled')
                        && $provider !== ''
                        && ! ProviderCatalog::isKeyless($provider)
                        && AiSettings::storedApiKey() === null
                        && ! ProviderCatalog::envConfigured($provider);
                })
                ->suffixActions([$this->testConnectionAction(), $this->removeKeyAction()])
                ->columnSpanFull(),

            TextInput::make('ai.timeout')
                ->label(__('fin-codex::fin-codex.settings.ai.timeout'))
                ->helperText(__('fin-codex::fin-codex.settings.ai.timeout_help'))
                ->numeric()
                ->minValue(10)
                ->maxValue(600)
                ->required()
                ->suffix(__('fin-codex::fin-codex.settings.ai.seconds')),

            Textarea::make('ai.translation_instructions')
                ->label(__('fin-codex::fin-codex.settings.ai.instructions'))
                ->helperText(__('fin-codex::fin-codex.settings.ai.instructions_help'))
                ->rows(8)
                ->autosize()
                ->columnSpanFull()
                ->hintAction($this->resetInstructionsAction()),
        ];
    }

    /**
     * Whether AI translation can run, asked fresh every time.
     *
     * The core's one rule, never a second copy of it: the tab action hides on
     * the same answer, and this page is the one place that says why.
     */
    private function aiStatus(): AiAvailability
    {
        return app(AiAvailabilityCheck::class)->check();
    }

    /**
     * Put the package's own instructions back into the form.
     *
     * modal() is the switch, the same one the save button uses: with a custom
     * heading Filament would otherwise open the box on every press, including
     * the press that changes nothing because the text is already the default.
     * Nothing is stored until the page is saved.
     */
    private function resetInstructionsAction(): Action
    {
        return Action::make('reset_instructions')
            ->link()
            ->label(__('fin-codex::fin-codex.settings.ai.reset_instructions'))
            ->modal(fn (Get $get): bool => trim((string) $get('ai.translation_instructions')) !== trim(DefaultInstructions::TEXT))
            ->requiresConfirmation()
            ->modalHeading(__('fin-codex::fin-codex.settings.ai.reset_instructions_heading'))
            ->modalDescription(__('fin-codex::fin-codex.settings.ai.reset_instructions_description'))
            ->action(fn (Set $set) => $set('ai.translation_instructions', DefaultInstructions::TEXT));
    }

    /**
     * One round trip to the provider with what the form holds right now.
     *
     * Independent of the toggle: the point of the button is to find out
     * whether the settings work before switching AI on. It changes nothing on
     * the form and nothing in storage — the key it sends is the typed one,
     * else the stored one, else null, which is the seam's way of saying "use
     * the SDK's own credential".
     *
     * The seam answers null or a reason key and never echoes a model, so the
     * model named in the notice is the one that was sent: the form's id, else
     * the provider's Default tier, else the plain "provider default" phrase
     * for a seam that cannot list tiers.
     */
    private function testConnectionAction(): Action
    {
        return Action::make('test_connection')
            ->label(__('fin-codex::fin-codex.settings.ai.test_connection'))
            ->icon(Heroicon::OutlinedSignal)
            ->action(function (Get $get): void {
                $provider = (string) ($get('ai.provider') ?? '');

                if ($provider === '') {
                    Notification::make()
                        ->warning()
                        ->title(__('fin-codex::fin-codex.settings.ai.test_connection_no_provider'))
                        ->send();

                    return;
                }

                // Deferred wire:model values reach the server with the action
                // call, so the key typed a moment ago is already here.
                $typed = (string) ($get('ai.api_key') ?? '');
                $model = trim((string) ($get('ai.model') ?? ''));

                $reason = app(AiClient::class)->testConnection(
                    $provider,
                    $model !== '' ? $model : null,
                    $typed !== '' ? $typed : AiSettings::storedApiKey(),
                );

                if ($reason !== null) {
                    Notification::make()
                        ->danger()
                        ->title(__('fin-codex::fin-codex.settings.ai.test_connection_failed'))
                        ->body(AiReason::label($reason))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('fin-codex::fin-codex.settings.ai.test_connection_ok', [
                        'provider' => app(AiClient::class)->providers()[$provider] ?? $provider,
                        'model' => $model !== ''
                            ? $model
                            : ($this->tierModels($provider)['default'] ?? (string) __('fin-codex::fin-codex.settings.ai.provider_default')),
                    ]))
                    ->send();
            });
    }

    /**
     * Forget the stored key now, without saving the rest of the page.
     *
     * The placeholder beside it and this button's own visible() both read
     * storage on every render, so the field flips to "no key stored" on the
     * re-render that follows with no extra state to keep. write() seeds an
     * unseeded group, so the action cannot fail on a host that never ran the
     * AI settings migration — where it is hidden anyway, nothing being stored.
     */
    private function removeKeyAction(): Action
    {
        return Action::make('remove_key')
            ->label(__('fin-codex::fin-codex.settings.ai.remove_key'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => AiSettings::storedApiKey() !== null)
            ->requiresConfirmation()
            ->modalHeading(__('fin-codex::fin-codex.settings.ai.remove_key_heading'))
            ->modalDescription(__('fin-codex::fin-codex.settings.ai.remove_key_description'))
            ->action(function (): void {
                AiSettings::write(['api_key' => null]);

                Notification::make()
                    ->success()
                    ->title(__('fin-codex::fin-codex.settings.ai.remove_key_done'))
                    ->send();
            });
    }

    /**
     * The provider's three tier models, or an empty list when the seam cannot
     * name them — an unknown provider, or a fake seam in a test.
     *
     * @return array<string, string>
     */
    private function tierModels(string $provider): array
    {
        if ($provider === '') {
            return [];
        }

        return $this->tierMemo[$provider] ??= $this->readTierModels($provider);
    }

    /**
     * @return array<string, string>
     */
    private function readTierModels(string $provider): array
    {
        try {
            return app(AiClient::class)->tierModels($provider);
        } catch (AiCallFailed) {
            return [];
        }
    }

    /**
     * The model select's options: the concrete ids with their tier as a hint,
     * plus the Custom choice that reveals the text input.
     *
     * Two tiers that share an id are listed once, under the first tier that
     * names them — the shape fin-sentinel's own model select has.
     *
     * @return array<string, string>
     */
    private function tierOptions(string $provider): array
    {
        $tiers = $this->tierModels($provider);

        if ($tiers === []) {
            return [];
        }

        $options = [];

        foreach (['default', 'cheapest', 'smartest'] as $tier) {
            $model = $tiers[$tier] ?? null;

            if (! is_string($model) || $model === '' || isset($options[$model])) {
                continue;
            }

            $options[$model] = $model.' ('.__('fin-codex::fin-codex.settings.ai.tier_'.$tier).')';
        }

        if ($options === []) {
            return [];
        }

        $options['custom'] = (string) __('fin-codex::fin-codex.settings.ai.custom_model');

        return $options;
    }

    /**
     * What the model pair becomes when the provider changes: its Default tier,
     * or nothing at all when the seam lists no tiers.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function defaultTierFor(string $provider): array
    {
        $default = $this->tierModels($provider)['default'] ?? null;

        return is_string($default) && $default !== '' ? [$default, $default] : [null, null];
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
     * The AI group joins the state under one "ai" key, and ONLY when the seam
     * reports the SDK installed: Schema::fill() keeps every key it is handed,
     * so merging it unconditionally would put AI state on a page that shows no
     * AI section — and would break the form-state contract HelpSettingsTest
     * pins at five keys.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $fallback = $data['fallback'] ?? null;
        $data['fallback'] = $fallback instanceof FallbackBehaviour ? $fallback->value : $fallback;

        if (app(AiClient::class)->installed()) {
            $data['ai'] = $this->aiFormData(AiSettings::values());
        }

        return $data;
    }

    /**
     * The AI group as the form wants it.
     *
     * The key is blanked, always: it is never echoed into the page, and a
     * blank field on save means "keep the stored one". model_choice is the
     * select's view over the stored model — the id itself when it is one of
     * the provider's tiers, Custom when it is any other id, and the Default
     * tier (written into ai.model too) when nothing is stored yet, so the
     * preselected default saves as a concrete id. With no tier list there is
     * no select, so no choice either.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    private function aiFormData(array $values): array
    {
        $provider = $values['provider'] ?? null;
        $tiers = $this->tierModels(is_string($provider) ? $provider : '');

        $stored = $values['model'] ?? null;
        $model = is_string($stored) && $stored !== '' ? $stored : null;
        $choice = null;

        if ($tiers !== []) {
            if ($model === null) {
                $model = $choice = $tiers['default'] ?? null;
            } else {
                $choice = in_array($model, $tiers, true) ? $model : 'custom';
            }
        }

        return [
            'enabled' => (bool) ($values['enabled'] ?? false),
            'provider' => $provider,
            'model_choice' => $choice,
            'model' => $model,
            'api_key' => '',
            'timeout' => $values['timeout'] ?? 120,
            'translation_instructions' => $values['translation_instructions'] ?? DefaultInstructions::TEXT,
        ];
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
     * Stripping the "ai" key is mandatory rather than tidy: Settings::fill()
     * assigns every key it is handed, so an AI payload left in place would
     * land on CodexSettings as a dynamic property and a sixth row in the wrong
     * group. It is stashed instead, and afterSave() writes it to its own.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['fallback'] = FallbackBehaviour::from((int) $data['fallback']);
        $data['revisions_keep'] = (int) $data['revisions_keep'];

        $this->aiData = is_array($data['ai'] ?? null) ? $data['ai'] : null;

        unset($data['ai']);

        return $data;
    }

    /**
     * The second settings group, written by the one Save button.
     *
     * The vendor calls this hook after CodexSettings has been saved and before
     * the transaction commits, so the two groups land together or not at all —
     * including the wider transaction saveRemovingLanguages() opens around the
     * whole save. Nothing happens on a page that never showed the section,
     * because the fill mutator put no AI state there to stash.
     */
    protected function afterSave(): void
    {
        if ($this->aiData === null) {
            return;
        }

        AiSettings::write($this->aiSettingsFromForm($this->aiData));
    }

    /**
     * Form state as the AI settings class needs it.
     *
     * numeric() hands back a float and a toggle a bool-ish scalar, while the
     * settings properties are typed int and bool; a blank key means "keep the
     * stored one", which is read from storage rather than from the form; and
     * model_choice is only ever a view over the model, so it is dropped even
     * though it is not dehydrated.
     *
     * @param  array<string, mixed>  $ai
     *
     * @return array<string, mixed>
     */
    private function aiSettingsFromForm(array $ai): array
    {
        unset($ai['model_choice']);

        $model = trim((string) ($ai['model'] ?? ''));
        $key = $ai['api_key'] ?? null;

        return [
            'enabled' => (bool) ($ai['enabled'] ?? false),
            'provider' => filled($ai['provider'] ?? null) ? (string) $ai['provider'] : null,
            'model' => $model === '' ? null : $model,
            'api_key' => blank($key) ? AiSettings::storedApiKey() : (string) $key,
            'timeout' => (int) ($ai['timeout'] ?? 120),
            'translation_instructions' => (string) ($ai['translation_instructions'] ?? DefaultInstructions::TEXT),
        ];
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
