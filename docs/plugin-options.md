# Plugin options

Every option that can differ between two panels is a fluent method. All of them accept a closure as well as a literal, evaluated when the option is read.

```php
FinCodexPlugin::make()
    ->shortcut('ctrl+/')                       // keyboard shortcut, null or '' disables it
    ->drawerWidth(480)                         // drawer width in pixels
    ->helpButton()                             // show the topbar button (default: true)
    ->guestDrawer()                            // drawer and link on simple-layout pages (default: true)
    ->authoring()                              // this panel manages help content (default: true)
    ->globalSearch()                           // Help category in the panel search (default: false)
    ->helpButtonRenderHook(PanelsRenderHook::USER_MENU_AFTER)
    ->navigationGroup('Help')
    ->navigationSort(90)
    ->helpCenterPlacement(HelpCenterPlacement::Both)
    ->policyNamespace('App\\Policies')
    ->articleResource(MyArticleResource::class)
    ->settingsPage(MyHelpSettings::class)
    ->coveragePage(MyHelpCoverage::class)
    ->helpCenterPage(MyHelpCenter::class)
    ->documentTypes(['application/pdf'])          // MIME types the Media tab's upload accepts
    ->documentMaxSize(20480)                       // its ceiling, in kilobytes
```

| Method | Default | What it does |
|---|---|---|
| `shortcut(string\|Closure\|null)` | `'ctrl+/'` | The keyboard shortcut that opens the drawer. `null` or `''` turns it off for that panel. |
| `drawerWidth(int\|Closure)` | `480` | Drawer width in pixels. |
| `helpButton(bool\|Closure)` | `true` | Renders the topbar help button. `false` removes the button only — the drawer, its shortcut and field hints stay. |
| `guestDrawer(bool\|Closure)` | `true` | The "Need help?" link and the drawer on simple-layout pages: login, register, password reset, email verification and any host `SimplePage`. `false` removes all three there; signed-in pages are unaffected. |
| `authoring(bool\|Closure)` | `true` | Whether this panel manages help content. `false` registers the article resource, Help settings and Help coverage nowhere in it — no navigation items, no routes — and leaves the reading half untouched. See [One panel authors, the others read](#one-panel-authors-the-others-read). |
| `globalSearch(bool\|Closure)` | `false` | Appends a Help category to the panel's global search results. See [Global search](help-center.md#global-search). |
| `helpButtonRenderHook(string\|Closure)` | `USER_MENU_AFTER` | Where the button renders. Set it explicitly and Codex honours it as given. Leave it alone and the button sits beside the user menu: in the topbar's end group next to the notification bell, or in the sidebar footer on a panel with `->topbar(false)`. A panel with `->userMenu(false)` gets it at `TOPBAR_END`, or `SIDEBAR_FOOTER` without a topbar. Under SPA mode Filament persists the topbar's end group across navigations, so the badge there keeps the count of the first page; name `TOPBAR_END` if you want it live. |
| `navigationGroup(string\|UnitEnum\|Closure\|null)` | `NavigationGroup::Help` | The navigation group for the resource and both pages. The default enum's label follows the panel locale. |
| `navigationSort(int\|Closure\|null)` | `null` | Sort for the article resource. Help settings files at `+1` and Help coverage at `+2`, so `->navigationSort(90)` gives 90, 91 and 92. Leave it null and Filament sorts the group by label. |
| `helpCenterPlacement(HelpCenterPlacement\|Closure)` | `HelpCenterPlacement::UserMenu` | Where the help center is advertised: the user menu, the navigation, both or neither. The page stays reachable under all four. See [Placement](help-center.md#placement). |
| `helpCenterNavigationGroup(string\|UnitEnum\|Closure\|null)` | `null` | The group the help center's navigation item is filed under. Null leaves it at the top level, outside the Help group the authoring screens use. See [Placement](help-center.md#placement). |
| `helpCenterNavigationSort(int\|Closure\|null)` | `1000` | Where that item sorts. The default puts it below a panel's own arrangement; `null` means no sort at all. |
| `helpCenterNavigationLabel(string\|Closure\|null)` | the translated `'Help center'` | That item's label. The user-menu entry's wording is fixed and does not read this. |
| `helpCenterNavigationIcon(string\|BackedEnum\|Htmlable\|Closure\|null)` | an outlined book | That item's icon. A book, not the question mark the topbar button carries. |
| `policyNamespace(string)` | `'App\Policies'` | Where Codex looks for your own `ArticlePolicy`. See [Authorization](authorization.md#authorization). |
| `articleResource(class-string)` | built-in | Swap in a subclass of `FinityLabs\FinCodex\Resources\ArticleResource`. |
| `settingsPage(class-string)` | built-in | Swap in a subclass of `FinityLabs\FinCodex\Pages\HelpSettings`. |
| `coveragePage(class-string)` | built-in | Swap in a subclass of `FinityLabs\FinCodex\Pages\HelpCoverage`. |
| `helpCenterPage(class-string)` | built-in | Swap in a subclass of `FinityLabs\FinCodex\Pages\HelpCenter`. Registered on every panel, `->authoring(false)` included. |
| `documentTypes(list<string>\|Closure)` | PDF, Word, Excel, PowerPoint, plain text, CSV | The MIME types the Media tab's **Upload file** accepts. The body editor's image drop zone is unaffected. See [Media](editor.md#media). |
| `documentMaxSize(int\|Closure)` | `10240` | The largest document the Media tab accepts, in kilobytes. |

> **The four class overrides must name a real subclass of ours.** Filament calls `registerRoutes()` and `registerNavigationItems()` statically on whatever string you pass at panel registration time, so a typo or a class that doesn't extend the built-in one is a fatal error on the next request, not a quietly ignored option. Keep the built-in slug (or override `getPages()` too) so the internal links keep resolving.

Extending is the intended way to adjust things. All four built-ins are non-final, and a subclass inherits the list, the filters, the "From files" tab, the form, the relation managers and every header action for free. The built-in pages resolve their resource through the plugin of the panel serving the request, so whatever you override on the subclass — the form, the table, `getEloquentQuery()`, the relation managers, the navigation statics — takes effect on those pages, and two panels can name two different subclasses:

```php
use FinityLabs\FinCodex\Resources\ArticleResource;

class MyArticleResource extends ArticleResource
{
    public static function getNavigationBadge(): ?string
    {
        return null;   // skip the warnings count on this panel
    }
}
```

## One panel authors, the others read

Register the plugin in a second panel and that panel gets everything: the button, the drawer, field hints — and a Help menu with the editor, Help settings and Help coverage in it. Useful on a staff panel whose editors write articles; noise on a panel that should only read them.

`->authoring(false)` splits the two halves:

```php
// app/Providers/Filament/AdminPanelProvider.php — writes help
->plugin(FinCodexPlugin::make())

// app/Providers/Filament/ManagementPanelProvider.php — reads it
->plugin(FinCodexPlugin::make()->authoring(false))
```

The management panel keeps the button, the drawer, its shortcut, field hints, global search if it asked for it, the panel scope and its own help center. What it no longer has is the three admin screens: `/management/codex-articles` is not a route there, and nothing files under a Help group in its navigation. Articles, media, revisions and Shield abilities are untouched — one knowledge base, edited from one place.

Two things stay true with authoring off. Articles still scope per panel, so an article written for `management` shows up in that panel's drawer even though the editor lives in `admin` (see [Contexts](editor.md#contexts)). And Help coverage still scans every panel, so a management screen without an article is still a gap on the report — a cleaner one, since the panel's own Help screens no longer count themselves.

The option is read once, when the panel registers the plugin, so a closure may read config but not panel state or the signed-in user. Who may edit is a separate question with a separate answer: see [Authorization](authorization.md#authorization).
