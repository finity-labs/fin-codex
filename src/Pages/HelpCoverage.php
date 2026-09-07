<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\FinCodex\Coverage\CoverageReport;
use FinityLabs\FinCodex\Coverage\WarningsSection;
use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Editor\FileArticleAdopter;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Panel\Concerns\ResolvesPanelUser;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\FinCodex\Traits\HasPageShieldSupport;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sources\SlugPath;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

/**
 * Which screens of this application have a help article, and which do not.
 *
 * The rows are CoverageReport's, not the core route report's: one row per
 * screen, with a Filament resource's list, create and edit routes folded into
 * one and a resource-class context credited (07-01 explains why the core's own
 * report structurally cannot do either). The number on this page's navigation
 * badge is therefore the number of rows this page shows for this panel by
 * default, and NOT `php artisan codex:coverage`'s route-level exit code. The
 * README says so.
 *
 * The table is array-backed: records() returns a LengthAwarePaginator of plain
 * arrays keyed by the row key, so Filament copies that key onto `__key` and an
 * action's recordKey speaks route names. Filters do nothing by themselves on a
 * records table — the query branch is the only place Filament applies them —
 * so the closure reads and applies them and the filter form is pure state.
 * There are no bulk actions and no row selection on purpose: the "all records"
 * count calls count() on a null query for anything that is not a paginator,
 * which is also why a paginator is returned rather than a plain array.
 *
 * Not final: a coveragePage() override extends it, exactly as HelpSettings is
 * open for settingsPage() and ArticleResource for articleResource().
 *
 * Access goes through HasPageShieldSupport: Shield's own permission when
 * Shield is installed, the opt-in Gate ability page_HelpCoverage when it is
 * not, and open to any panel user otherwise. That is the page-level gate; the
 * row actions carry their own per-article checks (08-03).
 */
class HelpCoverage extends Page implements HasTable
{
    use HasPageShieldSupport;
    use InteractsWithTable;
    use ResolvesPanelUser;

    protected static ?string $slug = 'help-coverage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FinCodexPlugin::get()->getNavigationGroup();
    }

    /**
     * Two slots after the article resource: the settings page takes the slot
     * between them, and both read the panel's single navigationSort() option.
     * Null stays null, in which case Filament sorts by label.
     */
    public static function getNavigationSort(): ?int
    {
        $sort = FinCodexPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 2;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('fin-codex::fin-codex.coverage.navigation');
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('fin-codex::fin-codex.coverage.title');
    }

    /**
     * The screens of this panel that have no article yet.
     *
     * Read eagerly when the navigation item is built (Filament passes a VALUE,
     * not a closure), which is once per panel page render, so the report's
     * request memo is what keeps this at one reading of the content source.
     * Null at zero, the Phase 3 help-button rule.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = app(CoverageReport::class)->uncovered(Filament::getCurrentPanel()?->getId());

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string|Htmlable|null
    {
        return (string) __('fin-codex::fin-codex.coverage.badge_tooltip');
    }

    /**
     * The base Page renders {{ $this->content }}. The warnings section sits
     * above the table and renders nothing when the sources are happy, so an
     * admin with nothing to fix sees the page exactly as before.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            WarningsSection::make(),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * The closure injections are resolved by PARAMETER NAME, so none of these
     * may be renamed: search, sort ([column, direction], both null until a
     * header is clicked), filters (the raw table filter state), page and
     * recordsPerPage (which can be the string 'all').
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, array $sort, ?array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginate($search, $sort, $filters, (int) $page, $recordsPerPage))
            ->columns([
                TextColumn::make('label')
                    ->label(__('fin-codex::fin-codex.coverage.columns.page'))
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium),
                TextColumn::make('panel')
                    ->label(__('fin-codex::fin-codex.coverage.columns.panel'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state ?? (string) __('fin-codex::fin-codex.coverage.outside_panels')),
                TextColumn::make('routes')
                    ->label(__('fin-codex::fin-codex.coverage.columns.routes'))
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('route')
                    ->label(__('fin-codex::fin-codex.coverage.columns.route'))
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('uri')
                    ->label(__('fin-codex::fin-codex.coverage.columns.uri'))
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('matched')
                    ->label(__('fin-codex::fin-codex.coverage.columns.matched'))
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('slug')
                    ->label(__('fin-codex::fin-codex.coverage.columns.article'))
                    ->badge()
                    ->color(fn (array $record): string => match (true) {
                        ! $record['covered'] => 'danger',
                        $record['declared'] => 'gray',
                        default => 'success',
                    })
                    ->description(fn (array $record): ?string => $record['declared'] ? (string) __('fin-codex::fin-codex.coverage.declared') : null)
                    ->url(fn (array $record): ?string => $this->editUrl($record))
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('write')
                    // It only opens the create form, which the resource gates
                    // with canCreate() anyway; this stops the row advertising
                    // a page the user cannot use. Class level, like every gate
                    // on this table: the record is an array and no article
                    // exists yet.
                    ->authorize(static fn (): bool => ArticleAbility::allows('create'))
                    ->label(__('fin-codex::fin-codex.coverage.actions.write'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (array $record): bool => ! $record['covered'])
                    ->url(fn (array $record): string => $this->writeUrl($record)),

                Action::make('attach')
                    // Half the check. The other half is inside attach(): this
                    // one can only ask `create`, because the article the write
                    // changes does not exist until the modal's Select comes
                    // back, and Gate::allows('update', Article::class) against
                    // a two-parameter policy method is an ArgumentCountError,
                    // not an answer.
                    ->authorize(static fn (): bool => ArticleAbility::allows('create'))
                    ->label(__('fin-codex::fin-codex.coverage.actions.attach'))
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(fn (array $record): bool => ! $record['covered'])
                    ->modalHeading(fn (array $record): string => (string) __('fin-codex::fin-codex.coverage.attach.heading', ['page' => $record['label']]))
                    ->modalDescription(__('fin-codex::fin-codex.coverage.attach.description'))
                    ->modalSubmitActionLabel(__('fin-codex::fin-codex.coverage.attach.submit'))
                    ->schema([
                        Select::make('article')
                            ->label(__('fin-codex::fin-codex.coverage.attach.article'))
                            ->helperText(__('fin-codex::fin-codex.coverage.attach.article_help'))
                            ->options(fn (): array => $this->attachOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(fn (array $record, array $data) => $this->attach($record, (string) $data['article'])),

                Action::make('import')
                    // The same class-level gate the files tab uses, and the
                    // same division of labour: this hides the button,
                    // FileArticleAdopter::adopt() is what actually refuses.
                    ->authorize(static fn (): bool => ArticleAbility::allows('import'))
                    ->label(__('fin-codex::fin-codex.coverage.actions.import'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (array $record): bool => $record['covered'] && $record['file_only'] && ! $record['declared'])
                    ->action(fn (array $record) => $this->import((string) $record['slug'])),
            ])
            ->filters([
                TernaryFilter::make('covered')
                    ->label(__('fin-codex::fin-codex.coverage.filters.covered'))
                    ->trueLabel(__('fin-codex::fin-codex.coverage.filters.covered_true'))
                    ->falseLabel(__('fin-codex::fin-codex.coverage.filters.covered_false')),
                // Opening on the panel the admin is standing in is the whole
                // point of a per-panel badge; clearing it shows every screen
                // of the application, including the ones outside any panel.
                SelectFilter::make('panel')
                    ->label(__('fin-codex::fin-codex.coverage.filters.panel'))
                    ->options(fn (): array => app(CoverageReport::class)->panelOptions())
                    ->default(Filament::getCurrentPanel()?->getId()),
            ])
            ->emptyStateHeading(__('fin-codex::fin-codex.coverage.empty'))
            ->emptyStateDescription(__('fin-codex::fin-codex.coverage.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck);
    }

    /**
     * One page of rows, keyed by the row key.
     *
     * A paginator rather than a plain array for two reasons: a host panel has
     * 50 to 400 screens, and the "all records" count Filament reaches for on
     * anything that is not a paginator would go through a query this table
     * does not have.
     *
     * @param  array{0: string|null, 1: string|null}  $sort
     * @param  array<string, mixed>|null  $filters
     */
    private function paginate(?string $search, array $sort, ?array $filters, int $page, int|string $recordsPerPage): LengthAwarePaginator
    {
        $rows = $this->rows($search, $sort, $filters);
        $perPage = $recordsPerPage === 'all' ? max(1, count($rows)) : (int) $recordsPerPage;

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage, preserve_keys: true),
            count($rows),
            $perPage,
            $page,
        );
    }

    /**
     * Every screen the report knows, filtered, searched and ordered.
     *
     * The report is asked once and closed over: it memoises one reading of the
     * content source per request, and a per-row read would be one reading per
     * screen.
     *
     * @param  array{0: string|null, 1: string|null}  $sort
     * @param  array<string, mixed>|null  $filters
     *
     * @return array<string, array<string, mixed>> keyed by CoverageRow::$key
     */
    private function rows(?string $search, array $sort, ?array $filters): array
    {
        $rows = [];

        foreach (app(CoverageReport::class)->rows() as $row) {
            $rows[$row->key] = $row->toArray();
        }

        $rows = $this->filtered($rows, $filters);

        if (filled($search)) {
            $rows = array_filter($rows, fn (array $row): bool => Str::contains((string) $row['label'], $search, ignoreCase: true)
                || Str::contains((string) $row['route'], $search, ignoreCase: true));
        }

        // Only the two sortable columns can arrive here; anything else keeps
        // the opening order, which puts the gap first and orders each block by
        // the screen's name.
        $column = in_array($sort[0] ?? null, ['label', 'route'], true) ? $sort[0] : null;
        $direction = ($sort[1] ?? 'asc') === 'desc' ? -1 : 1;

        uasort($rows, function (array $a, array $b) use ($column, $direction): int {
            if ($column === null) {
                return ($a['covered'] <=> $b['covered']) ?: strnatcasecmp((string) $a['label'], (string) $b['label']);
            }

            return $direction * strnatcasecmp((string) $a[$column], (string) $b[$column]);
        });

        return $rows;
    }

    /**
     * The create page, already knowing which screen the article is about.
     *
     * One row produces exactly ONE context: `class:` for a screen behind a
     * Filament page — the key the picker offers, the key the drawer matches
     * and the key that flips this row to covered — and `route:` for a
     * standalone route. Prefilling `route:` for a resource would take three
     * or four rows and still not be what the drawer resolves.
     *
     * @param  array<string, mixed>  $record
     */
    private function writeUrl(array $record): string
    {
        $isClass = $record['help_class'] !== null;

        return $this->articleResource()::getUrl('create', [
            'context_type' => $isClass ? ContextType::PageClass->key() : ContextType::Route->key(),
            'context_key' => $isClass ? $record['help_class'] : $record['route'],
            'panel' => $record['panel'] ?? ContextPicker::ANY_PANEL,
            'title' => $record['label'],
        ]);
    }

    /**
     * The same (type, key, panel) triple the write URL carries.
     *
     * @param  array<string, mixed>  $record
     *
     * @return array{panel_id: string|null, type: string, key: string}
     */
    private function contextFor(array $record): array
    {
        $isClass = $record['help_class'] !== null;

        return [
            'panel_id' => $record['panel'] === null ? ContextPicker::ANY_PANEL : (string) $record['panel'],
            'type' => $isClass ? ContextType::PageClass->key() : ContextType::Route->key(),
            'key' => (string) ($isClass ? $record['help_class'] : $record['route']),
        ];
    }

    /**
     * Database articles only: a file article has no row to hang a context on,
     * and guessing a slug for it is out of the question — the slug becomes the
     * article's permanent identity and its file path. The helper text says to
     * import the file first.
     *
     * @return array<string, string>
     */
    private function attachOptions(): array
    {
        $default = TranslationTabs::languages()['default'];

        return collect(app(ContentSource::class)->all())
            ->reject(fn (ArticleData $article): bool => $article->id === null)
            ->mapWithKeys(fn (ArticleData $article): array => [
                $article->slug => ($article->translation($default)->title
                    ?? SlugPath::humanise(SlugPath::lastSegment($article->slug))).' ('.$article->slug.')',
            ])
            ->all();
    }

    /**
     * Add this screen's context to an article that already exists.
     *
     * The write goes through ArticleWriter like every other context row, so it
     * runs in one transaction attributed to the panel user. An article that
     * already carries the identical context is left alone and the admin is
     * told; a wider or overlapping context is a legitimate thing to have and
     * is appended without comment.
     *
     * The admin stays on the page. The report memoises one reading of the
     * content source per request, so the row goes green on the next render.
     *
     * The ability is asked twice, about two different things, and both halves
     * are needed. The button asks `create` at class level, because until this
     * method runs there is no article to ask about. The write is an edit of an
     * article that already exists, so it asks `update` on that article — which
     * is the question a host policy answers when it lets somebody start new
     * help but not rewrite what is already published. The refusal reuses the
     * duplicate-context notification rather than a new one: from the admin's
     * side both mean "the attach did not happen", and a gated button that
     * explains itself tells an attacker more than it tells an editor.
     *
     * @param  array<string, mixed>  $record
     */
    private function attach(array $record, string $slug): void
    {
        $article = Article::query()->where('slug', $slug)->first();

        if ($article === null) {
            return;
        }

        if (! ArticleAbility::allows('update', $article)) {
            Notification::make()
                ->warning()
                ->title(__('fin-codex::fin-codex.coverage.attach.duplicate'))
                ->body(__('fin-codex::fin-codex.coverage.attach.duplicate_body', [
                    'title' => $slug,
                    'page' => $record['label'],
                ]))
                ->send();

            return;
        }

        $title = $article->translations()->where('locale', TranslationTabs::languages()['default'])->value('title') ?? $slug;
        $appended = app(ArticleWriter::class)->appendContext($article, $this->contextFor($record), $this->userId());

        Notification::make()
            ->{$appended ? 'success' : 'warning'}()
            ->title(__($appended ? 'fin-codex::fin-codex.coverage.attach.attached' : 'fin-codex::fin-codex.coverage.attach.duplicate'))
            ->body(__($appended ? 'fin-codex::fin-codex.coverage.attach.attached_body' : 'fin-codex::fin-codex.coverage.attach.duplicate_body', [
                'title' => $title,
                'page' => $record['label'],
            ]))
            ->send();
    }

    /**
     * Import the file article covering this screen and open it, mirroring the
     * files tab's own action: the notification is persistent and sent before
     * the redirect, because Notification::send() pushes it into the session
     * where the edit page picks it up.
     */
    private function import(string $slug): void
    {
        try {
            $article = app(FileArticleAdopter::class)->adopt($slug, $this->userId());
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title(__('fin-codex::fin-codex.editor.imported.failed'))
                ->body($e->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->warning()
            ->persistent()
            ->title(__('fin-codex::fin-codex.editor.imported.title'))
            ->body(__('fin-codex::fin-codex.editor.imported.body', ['path' => (string) $article->source_path]))
            ->send();

        $this->redirect($this->articleResource()::getUrl('edit', ['record' => $article]));
    }

    /**
     * Null for an uncovered row, for a row covered by a declaration in code
     * (there is nothing to open) and for a file article that has no database
     * row yet (the import action is what that row offers).
     *
     * @param  array<string, mixed>  $record
     */
    private function editUrl(array $record): ?string
    {
        return $record['covered'] && ! $record['declared'] && $record['article_id'] !== null
            ? $this->articleResource()::getUrl('edit', ['record' => $record['article_id']])
            : null;
    }

    /**
     * The resource the current panel registered, so a host's
     * articleResource() override builds both URLs.
     *
     * @return class-string<ArticleResource>
     */
    private function articleResource(): string
    {
        $resource = FinCodexPlugin::get()->getArticleResource();

        return is_a($resource, ArticleResource::class, true) ? $resource : ArticleResource::class;
    }

    /** The panel user's id, the attribution of the attach and the import. */
    private function userId(): ?int
    {
        return $this->panelUserId();
    }

    /**
     * The panel filter first — it is the default view — then the covered one.
     *
     * The state shape is the filter's own form field: a select filter's field
     * is named `value`. `blank()` is what makes the ternary's `0` and `'0'`
     * read as "no article" rather than as "no filter"; a browser sends those
     * two and a test sends a real bool.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $filters
     *
     * @return array<string, array<string, mixed>>
     */
    private function filtered(array $rows, ?array $filters): array
    {
        $panel = $filters['panel']['value'] ?? null;

        if (filled($panel)) {
            $rows = array_filter($rows, fn (array $row): bool => $panel === CoverageReport::OUTSIDE_PANELS
                ? $row['panel'] === null
                : $row['panel'] === $panel);
        }

        $covered = $filters['covered']['value'] ?? null;

        if (! blank($covered)) {
            $rows = array_filter($rows, fn (array $row): bool => $row['covered'] === (bool) $covered);
        }

        return $rows;
    }
}
