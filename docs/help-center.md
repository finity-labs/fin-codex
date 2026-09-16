# The drawer and the help center

## The help drawer

The drawer is the reading surface on every panel page. It opens from the topbar help button, from the keyboard shortcut (`ctrl+/` by default), from a `?codex=slug` query string on any panel URL, or from a `codex:open` browser event dispatched by your own JavaScript. A `?codex=slug#heading` link scrolls to that heading once the article has rendered.

It opens on the articles attached to the current screen first (see [Contextual help](contextual-help.md)), on a **This page** tab, with a **Browse** tab holding everything the reader may open and a search field over the same set. The badge on the topbar button counts the articles for the current page.

Guests get it too. The login, registration, password-reset and email-verification pages carry a "Need help?" link that opens the same drawer over the public articles, and `->guestDrawer(false)` on the plugin removes it. The button, the shortcut and the drawer width are all [plugin options](plugin-options.md).

## The help center

The help center is the whole library as a page of its own, at `{panel}/help` and `{panel}/help/{slug}` — inside the panel, behind its auth and its guard. It is built out of Filament's own components and follows the panel's colours and its light and dark mode, so there is nothing to publish and nothing to theme. Every panel carrying the plugin has one, `->authoring(false)` included: this is the surface that reads help, not a fourth screen that writes it.

Three columns. On the left, **Browse help**: a search field above a **Contents** / **Search** tab strip. Contents is the tree of everything this reader may open, grouped by section, and it remembers which sections you left folded. Typing moves you to Search, and the hits stay in that column, so the article you were reading is still beside them and a wrong guess costs nothing. The middle column holds the article, with breadcrumbs above the title that open each section it sits in. On the right, **On this page** lists the article's own headings and jumps to them — an article without headings has no such column and the text takes the room instead. On a narrow screen the left rail folds away, also remembered, and the article comes first.

Every help link Codex renders inside a panel arrives here: the drawer's footer, the field hints' `href`, the global-search results. Each points at its own panel's copy.

Who may open it is the page's own question, answered the way the other two pages answer it — Shield's permission where Shield is installed, a `page_HelpCenter` Gate ability where it is not, any panel user otherwise. See [Gating the pages](authorization.md#gating-the-pages). What a reader then finds inside is decided by lin-codex's visibility rules and by [Panel scoping](contextual-help.md#panel-scoping), exactly as in the drawer.

The core's public `/help` is switched off on a fin-codex install, so this is where help lives now. If you are upgrading, read [The public help center is off](upgrading.md#the-public-help-center-is-off).

`->helpCenterPage(MyHelpCenter::class)` swaps in your own subclass of `FinityLabs\FinCodex\Pages\HelpCenter`, the way the editor and the two admin pages are swapped. Every link above resolves through the class the panel actually registered, so a subclass is what gets linked to.

### Placement

Where a panel advertises the page is a per-panel choice:

```php
use FinityLabs\FinCodex\Enums\HelpCenterPlacement;

FinCodexPlugin::make()
    ->helpCenterPlacement(HelpCenterPlacement::Both)
    ->helpCenterNavigationGroup('Support')
    ->helpCenterNavigationSort(20)
    ->helpCenterNavigationLabel('Manual')
    ->helpCenterNavigationIcon('heroicon-o-academic-cap')
```

`UserMenu`, the default, puts a **Help center** entry in the user menu, directly after **Profile**. `Navigation` files an item in the panel's navigation instead, `Both` does both, and `None` neither. The page is registered and reachable under all four: `{panel}/help` answers, and the drawer's footer link, the field hints, the global-search rows and a bookmark all still open it.

Three things follow from that:

- **`None` withholds the two menu entries and nothing else.** It is how you say "reachable, but not advertised" — a panel whose readers arrive from the drawer and the hints — not how you switch the page off. Nothing switches it off.
- **A panel with `->userMenu(false)` renders no user-menu entry whatever the placement says**, because there is no menu to render it in. Name `Navigation` or `Both` on such a panel.
- **The user-menu entry's wording and icon are fixed.** The four `helpCenterNavigation*()` options name the navigation item only. To word the menu differently, register an entry of your own there and set the placement to `Navigation` or `None`.

Both entries are hidden from a viewer who may not open the page.

Those four options are the help center's alone. `navigationGroup()` and `navigationSort()` keep meaning what they always meant — the article resource, Help settings and Help coverage — and the help center stays out of that arithmetic on purpose: no group and sort `1000` by default, so it sits at the foot of the navigation rather than inside the Help group, which holds the screens that write help.

## Locale and theme

The drawer locks `app()->getLocale()` when it mounts and keeps it across later Livewire requests. The chrome around it — the button's tooltip and aria-label, the guest link, the drawer's tab labels — is rendered by `__()` on each request instead, so it follows the locale of whatever request drew it.

If you set the panel locale in middleware, register that middleware as persistent:

```php
$panel->middleware([
    SetLocale::class,
], isPersistent: true);
```

Without `isPersistent: true`, Livewire update requests skip the middleware and the chrome falls back to the app's default locale after the first interaction.

**One accepted rough edge:** on a panel with dark mode, a machine whose OS prefers dark while the stored panel theme is light can show the drawer and button in dark colours for the few dozen milliseconds before Alpine's theme binding adds the `light` class. Filament's own dark-mode loader has the mirror-image race. Panels without dark mode get a static `light` class and never flash.

## Global search

Off by default. Turn it on per panel and the panel's search field gains a **Help** category:

```php
FinCodexPlugin::make()->globalSearch()
```

Results go through the same gated search the drawer uses, so nothing appears that the viewer could not already read.

**`FinCodexPlugin::globalSearch()` is not `Panel::globalSearch()`.** Ours is a `bool|Closure` opt-in for the Help category. Filament's takes a provider class string or a bool and decides which provider the panel uses. They are unrelated and they compose — `->globalSearch(MyProvider::class)` on the panel plus `FinCodexPlugin::make()->globalSearch()` on the plugin gives `MyProvider`'s categories with Help appended.

Four things to know:

1. **The search field stays hidden on a panel with no globally searchable resource.** Filament renders the field only when some resource answers `canGloballySearch()`. Turning our option on does not force it; if you want a search field on a panel that has none, make one of your own resources searchable.
2. **The article resource is deliberately not globally searchable.** Filament's default would query the model directly — unpublished and members-only articles included, the gate never consulted, file articles missing, and every row linking to the edit page. A subclass registered through `->articleResource()` inherits that `false`. Re-enabling it is a visibility leak, not a feature.
3. **The panel search and the help drawer share one rate limit.** lin-codex keys it per user or IP over a 60-second window, defaulting to 120 searches for a signed-in user and 30 for a guest. Global search fires one search per debounced keystroke, so sustained typing in the panel's search field can throttle the same person's help drawer for the rest of the minute. Raise `lin-codex.search.rate_limit.user` if your admins live in the search box. Queries shorter than `lin-codex.search.min_length` cost nothing.
4. **Help results are capped at five** in the dropdown, independent of `lin-codex.search.limit`, which is tuned for the full-height drawer. The category is always appended last, so your own `getGlobalSearchSort()` ordering is untouched, and it is left out entirely when the search is throttled or matches nothing.
