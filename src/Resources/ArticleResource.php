<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use FinityLabs\FinCodex\Coverage\SourceWarnings;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\CreateArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\ListArticles;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\MediaRelationManager;
use FinityLabs\FinCodex\Resources\ArticleResource\RelationManagers\RevisionsRelationManager;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\ArticleForm;
use FinityLabs\FinCodex\Resources\ArticleResource\Tables\ArticlesTable;
use FinityLabs\LinCodex\Models\Article;
use UnitEnum;

/**
 * The help article editor: lin-codex's Article model as a Filament resource,
 * mounted at /{panel-path}/codex-articles.
 *
 * FinCodexPlugin::register() puts this class on every panel that carries the
 * plugin, unless the host named its own through articleResource(); such an
 * override extends this class, which is why the class is not final. Its pages
 * stay this resource's pages (their $resource points here) and the slug is
 * inherited, so the routes and the generated URLs line up; a host that wants
 * different pages overrides getPages() as well.
 *
 * Navigation placement is not the resource's business: the group and the sort
 * come from the panel's own plugin options, read at navigation time through
 * FinCodexPlugin::get(), so two panels can file the editor differently.
 *
 * Every write the pages perform is delegated to Editor\ArticleWriter, the one
 * transactional write path, so nothing here touches the model directly.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $slug = 'codex-articles';

    protected static ?string $recordTitleAttribute = 'slug';

    /**
     * Help never reaches global search through the resource.
     *
     * Filament's default is true, and with $recordTitleAttribute set the default
     * provider would run Article::query() directly: unpublished and members-only
     * articles included, ArticleGate never consulted, file articles missing, and
     * the row linking at the edit page instead of the article. The plugin's own
     * globalSearch() option wraps the panel's provider instead and asks Searcher,
     * which gates before it reads. A host's articleResource() subclass inherits
     * this property, so the rule travels with the override.
     */
    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FinCodexPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return FinCodexPlugin::get()->getNavigationSort() ?? 1;
    }

    public static function getModelLabel(): string
    {
        return (string) __('fin-codex::fin-codex.editor.article');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('fin-codex::fin-codex.editor.articles');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('fin-codex::fin-codex.editor.navigation');
    }

    /**
     * The content-source warnings count. The uncovered count lives on the
     * coverage page's own item: one number per navigation item, each meaning
     * one thing. Filament reads this eagerly when the navigation item is
     * built, once per panel page render, which is why SourceWarnings is
     * request-scoped; a host that does not want the reading at all overrides
     * this with `return null;` on its own subclass.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = app(SourceWarnings::class)->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArticlesTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }

    /**
     * String keys on purpose: Tabs::getDefaultChildComponents() copies the
     * array key onto the tab when livewireProperty() is set, so these become
     * the activeRelationManager values, the wire:click handlers and the
     * ?relation= deep link. A plain list would give ?relation=0.
     *
     * @return array<string, class-string<RelationManager>>
     */
    public static function getRelations(): array
    {
        return [
            'revisions' => RevisionsRelationManager::class,
            'media' => MediaRelationManager::class,
        ];
    }
}
