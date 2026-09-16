# Contextual help

How an article ends up on a given screen: contexts stored on the article, `HasHelp` declarations in code, the field-hint button next to a form field, and how panels scope what a reader sees.

## Where an article shows up

An article shows up in the drawer on a given screen because it has a *context* pointing at that screen. Contexts come from two places: articles carry them in the database, added from the editor, and classes declare them in code.

### Declaring help in code

Implement `HasHelp` on a resource, a resource page or a custom page, and use the `WithHelp` trait to answer it from a property:

```php
use FinityLabs\FinCodex\Help\HasHelp;
use FinityLabs\FinCodex\Help\WithHelp;

class UserResource extends Resource implements HasHelp
{
    use WithHelp;

    protected static array $helpArticles = ['users', 'user-roles'];
}
```

Best article first. The property can also be a map, when one class needs different articles per panel:

```php
protected static array $helpArticles = [
    '*'     => ['users'],
    'staff' => ['staff-users', 'users'],
];
```

`'*'` is the entry for panels without a key of their own. A panel with neither gets nothing.

The class must still `implements HasHelp` — a trait cannot implement an interface, and the scanner looks for the interface. `WithHelp` only fills in `getHelpArticles(string $panelId): array` from the property; skip the trait and write the method yourself if you'd rather compute the list.

### What a declaration becomes

A declaration on a **resource** covers the whole resource: it folds in as a panel-scoped `class:` context on the resource, plus one `route:` context per registered page (list, create, edit, view). A declaration on a **resource page** like `EditUser` covers that page's route only. A declaration on a **custom page** covers that page class.

Declared slugs lead the drawer in the order you wrote them, count towards the topbar badge, count as covered on the coverage page, appear in lin-codex's JSON API, and go through the core's visibility gate like any stored article.

Three rules are worth knowing before you spread declarations around:

1. **A page-level declaration refines, it doesn't lead.** The core sorts `class:` contexts before `route:` ones regardless of order, so an `EditUser` declaration always follows the resource's list on the edit page. Declare on the resource whatever should come first.
2. **A declaration suppresses panel-less stored contexts on that page.** The core's panel-scoped pass wins when it is non-empty, so a stored context with no panel id stops appearing on a page whose class or route carries a declaration in that panel. Store panel-scoped contexts (or declare them) when both should show.
3. **A slug that doesn't exist is skipped, not shown.** It is reported once per class, panel and slug through lin-codex's source warnings, so the typo turns up on the [coverage page](coverage.md#coverage-and-warnings) rather than in the drawer.

**Multi-configuration resources are not scanned.** The scanner walks `Panel::getResources()` and `Panel::getPages()`. Filament 5's `getResourceConfigurations()` — the same resource registered several times with different configurations — is not walked, so declarations on those registrations do nothing until a later release adds it.

## Field hints

`CodexHelp` puts a small question-mark button next to a form field. Clicking it opens the drawer on the article, scrolled to the heading if you named one.

The shortest form is the `codexHelp()` macro, available on every Filament field:

```php
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

TextInput::make('slug')->codexHelp('articles/slugs');

Select::make('role')->codexHelp('users', 'assigning-a-role');
```

The macro is sugar over `hintAction(CodexHelp::make(...))`. Since `CodexHelp::make()` returns a plain Filament `Action`, it drops anywhere an action is accepted:

```php
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use FinityLabs\FinCodex\Forms\CodexHelp;

// Next to a section heading
Section::make('Permissions')
    ->afterHeader([CodexHelp::make('users/permissions')]);

// On an infolist entry
TextEntry::make('status')
    ->hintAction(CodexHelp::make('orders', 'order-statuses'));

// In a table header
$table->headerActions([CodexHelp::make('orders')]);
```

Only fields get the macro; everything else takes `CodexHelp::make()`.

The hint is invisible when there is nothing to open. If the slug doesn't exist, or the core's gate says this viewer may not read that article, the action hides and the field renders as if no hint were set. The tooltip is the article's title in the reader's language.

The button is a real link. Its `href` is the help-center URL for the article, and the Alpine handler only cancels the navigation when a drawer is present on the page. On a page without one — or with JavaScript off — the click goes to the help center in the same tab.

**On an SPA panel**, Codex appends the help-center route pattern to Filament's SPA exceptions when the plugin boots. Without that, Livewire's navigate listener starts on `mousedown` and wins the race against the Alpine intercept, so the click would leave the panel even with a drawer open. The pattern follows the panel's own help-center path, so a second panel excepts its own, and chaining `->spaUrlExceptions([...])` after `->plugin()` keeps working — the plugin appends rather than replaces.

## Panel scoping

Since 0.5.0 a reader inside a panel sees the general articles plus that panel's own, and nothing else. An article belonging only to some other panel is hidden everywhere the core reads: the help center, the drawer's Contents tab and its search, the field hints and global search. A section left holding nothing goes with them.

This is the change most likely to surprise you on an upgrade. An article written for `admin` and read from `staff` used to be there and is not any more.

What puts an article in a panel is its contexts, and there are four cases:

- **No contexts at all is general.** The article is read in every panel.
- **A context that names a panel binds the article to it**, and an article with contexts in two panels is read in both. The editor's panel select writes that name, a `HasHelp` declaration writes it for the panel it declares in, and in front matter it is the prefix: `admin:class:App\Filament\Resources\UserResource`.
- **A context that names no panel restricts nobody.** `*` in the editor's select, no prefix in front matter — one of those is enough for the article to be read from every panel, whatever its key points at. An article whose only context is a plain Laravel route is therefore read in every panel rather than in none.
- **An article is hidden only when every one of its contexts names a different explicit panel.**

A section with no body of its own follows its children: it is shown while one descendant survives the scoping and disappears when none does. A section that carries a body is scoped like any other article, and the core's ancestor rule then takes the whole subtree with it — so keep an article that should be read everywhere out of a panel-bound section.

Published state and visibility are settled before any of this and stay lin-codex's. Panel scoping can only hide.

**Outside a panel nothing is scoped.** The JSON API, a queued render and your own routes are not panel requests, so they answer as they always did, under the core's visibility rules and nothing more.

**`viewAllPanels` lifts the rule for one reader.** With it they read every panel's help from wherever they are standing, and the help center grows a **Panel** select at the top of its left rail that answers "what would a reader in the staff panel see" — the one control most readers never meet. It is off by default, and it is the ninth row in [the abilities table](authorization.md#the-abilities): it has no fallback, so a policy that does not define it says no. The shipped policy answers it from the permission Filament Shield generated for the ability — `fin-codex:install` writes it into the Shield config with the other eight, and an admin ticks it on a role. Without Shield, define `viewAllPanels()` on your own policy. A `Gate::before` callback, Shield's super admin among them, still runs before either.
