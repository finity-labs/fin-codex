# Authorization

fin-codex ships a policy for lin-codex's `Article` and registers it for you. Out of the box it answers yes to any authenticated panel user, which is what a panel with no policy already does — the difference is that a panel with `strictAuthorization()` renders instead of throwing.

## Replacing it

Write your own class at `{policyNamespace}\ArticlePolicy` — `App\Policies\ArticlePolicy` unless you say otherwise — and Codex registers yours instead of the shipped one. Extending the shipped policy is the shortest way there; it is not final and none of its methods are static.

```php
namespace App\Policies;

use FinityLabs\LinCodex\Models\Article;
use Illuminate\Contracts\Auth\Authenticatable;

class ArticlePolicy extends \FinityLabs\FinCodex\Policies\ArticlePolicy
{
    public function update(Authenticatable $user, Article $article): bool
    {
        return $user->hasRole('editor');
    }
}
```

Don't edit the shipped file in `vendor/` — an update overwrites it.

The namespace is a per-panel option and is registered when that panel boots for a request, so two panels can name two policies. Outside any panel — console commands, queue workers, routes of your own — the default panel's namespace applies, or `App\Policies` when the plugin is not on the default panel.

> **If your application already has an `App\Models\Article`, read this one.** The lookup matches on class basename, so your existing `App\Policies\ArticlePolicy` — written for *your* Article — would be registered against lin-codex's model too, and would start answering questions it was never written for. Point Codex somewhere else:
>
> ```php
> FinCodexPlugin::make()->policyNamespace('App\\Policies\\Codex')
> ```
>
> Codex then looks for `App\Policies\Codex\ArticlePolicy` and falls back to the shipped policy when it isn't there. Your own article's policy is left alone.

## The abilities

| Ability | Guards |
|---|---|
| `viewAny` | The article list and the navigation item |
| `view` | Reading one article in the editor |
| `create` | The create page |
| `update` | The edit page, the media tab and media deletion |
| `delete` | Deleting an article |
| `restore` | Restoring a **revision** — `Article` has no soft deletes |
| `import` | Adopting a file article into the database |
| `convert` | Rewriting an HTML article's body as Markdown |
| `viewAllPanels` | Reading every panel's articles from inside one panel |

The first five are Filament's. The next three are ours, and **a policy that only defines the first five keeps working**: `restore` and `convert` fall through to the article's `update`, and `import` falls through to `create`. You should not have to learn our vocabulary to keep the editor running.

`viewAllPanels` is the ninth and the odd one out: it guards no screen, it widens what a viewer may *read* across panels (see [Panel scoping](contextual-help.md#panel-scoping)), and it has no fallback — a policy that does not define it answers no, where the three above it fall through. The shipped policy answers it from the permission Filament Shield generated for the ability, and a host `Gate::before` callback still runs before any of that.

The fallback fills a missing method; it never overturns a no. Define `restore()` and return `false` and the restore button stays gone.

`import` gates **every** file-to-database adoption, not just the buttons. Opening a file-only article for editing needs it too, because the adopter enforces it at the choke point rather than only in the UI. A user who cannot import cannot cause an import by any route.

There is **no `MediaPolicy` and no revision policy**, by design. Revisions, translations, contexts and media are only ever edited through the article, so they answer to the owning article's abilities — the media relation manager and its delete both ask for `update` on the article. One policy to override, not four.

## Reading help is not editing help

The drawer, the help button, the field hints and the global-search Help category go through lin-codex's `ArticleGate` and never touch `ArticlePolicy`. A user with a deny-everything article policy still reads exactly the help the core's visibility rules allow. Editor permissions have nothing to do with reading help, and there is a test in the suite that keeps it that way.

## Gating the pages

Without Shield, all three pages are open to any authenticated panel user until you define an ability named after the page class:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('page_HelpSettings', fn ($user) => $user->isAdmin());
Gate::define('page_HelpCoverage', fn ($user) => $user->isAdmin());
Gate::define('page_HelpCenter', fn ($user) => $user->isAdmin());
```

Define nothing and nothing changes. The ability is named after the class **actually registered on the panel**, so if you supply your own settings page through `->settingsPage(MyHelpSettings::class)`, the ability is `page_MyHelpSettings`.

`page_HelpCenter` is the one to think twice about: the other two gate editor screens, while this one gates a reading surface. Deny it and that panel's user-menu entry and navigation item go with it, and the drawer's footer link hides itself. The drawer, the button and the field hints keep working — reading help is a separate question from opening the page.

## Filament Shield integration

[Filament Shield](https://github.com/bezhanSalleh/filament-shield) is optional. Install it and the pages and the resource pick up Shield permissions on their own; without it, authorization works exactly as described above.

`fin-codex:install` writes the article resource into `config/filament-shield.php` with all nine abilities and runs `shield:generate`. The pages need nothing written for them — Shield 4 discovers pages from the panel and only reads `pages.exclude` from config — so the command prints the nudge instead:

```bash
php artisan shield:generate --page=HelpSettings,HelpCoverage,HelpCenter
```

Because `policies.merge` is on by default, the resource's own methods are folded into Shield's list, which is how `restore`, `import` and `convert` end up on the generated policy. That policy lands at `App\Policies\ArticlePolicy` — the same place Codex already looks — so a Shield install takes over the article authorization with no extra wiring and no Shield branch in our code.

**On `page_HelpSettings` and `page_HelpCoverage`:** those are **fin-codex's own** Gate hook for hosts without Shield. They are not Shield's naming. Shield 3 used `page_{Class}`, but Shield 4 renamed every permission — separator `:`, pascal case, a `view` prefix for pages — so on a Shield install the settings page's permission is `View:HelpSettings` by default, and something else entirely on a reconfigured one. Codex never builds that name: it asks Shield for it, which is why a customised `filament-shield.php` keeps working.

`fin-codex:uninstall` removes the resource entry from the Shield config and deletes the permission rows for the resource and all three pages, asking Shield for their names rather than rebuilding them. If Shield cannot answer, nothing is deleted and the command says so.
