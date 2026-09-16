# Installation and setup

Everything about getting Codex into a panel, and out again: requirements, the two packages, the install command, styling, publishing translations and views, and uninstalling.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- Filament 4 or 5
- [`finity-labs/lin-codex`](https://github.com/finity-labs/lin-codex) ^0.4.2
- Optional, for AI translation: PHP 8.3+, Laravel 12+ and [`laravel/ai`](https://github.com/laravel/ai) ^0.11 — lin-codex's suggested SDK, documented in [its README](https://github.com/finity-labs/lin-codex#ai-translation)

Codex is split across two packages, and it matters for where you configure things. **lin-codex** owns the content: the `codex_*` tables, the Markdown renderer, the filesystem source, visibility rules, search, translations and the JSON API. It ships its own config file, its own install command and its own Blade drawer, and it works in any Laravel app with no Filament at all.

**fin-codex** — this package — is the panel layer on top: the drawer mount, the help button, the help center, the editor, the settings and coverage pages, and the authorization. It has no config file of its own. Anything about *content* (the media disk, search tuning, the help-center route, the article gate) is configured in `config/lin-codex.php`; anything that can differ between two panels is a fluent option on the plugin.

## Installation

```bash
composer require finity-labs/fin-codex
```

lin-codex comes along as a dependency. Install its schema and settings first:

```bash
php artisan codex:install
```

Then wire the panel:

```bash
php artisan fin-codex:install
```

The install command:

- Checks that lin-codex's articles table exists, and offers to run `codex:install` if it doesn't.
- Registers `FinCodexPlugin::make()` in one of your panel providers (it lists the panels it found; pass `--panel=admin` to skip the prompt).
- Asks which languages the help articles are written in, with the locales your application already translates pre-selected, and writes them to the Codex settings. Pass `--locales=en,de` to answer without the prompt. The application locale stays the default language when it is among them.
- Offers to import twelve starter articles in the configured languages (they exist in en, de and hu): an authenticated **Help** section about the help system itself — getting help, the help center, writing articles, coverage, settings, and declaring help in code — and a public **Your account** section for Filament's own screens — signing in, creating an account, a forgotten password, email verification and the profile page (the profile article is authenticated). The account section is public on purpose: lin-codex hides an article whose ancestor the reader may not open, so a visitor on the sign-in page only sees articles whose whole path is public. They land as ordinary database articles, attached to the pages they describe, and are yours to edit or delete. The Help section is also attached to the panel the plugin was installed on, so a second panel does not offer its users the editor's manual; the account section stays on any panel, because Filament's sign-in, registration, password and profile pages exist on every panel and naming one would hide those articles on every other panel's login page. `--skip-starter-articles` leaves them out; a slug that already exists is never re-imported, though a re-run does refresh the text of one you have not edited — see [The starter articles have changed](upgrading.md#the-starter-articles-have-changed).
- Offers to publish the translations and the views. Both default to **no** — a published copy stops receiving upstream changes.
- Registers the article resource in `config/filament-shield.php` if [Filament Shield](authorization.md#filament-shield-integration) is installed, and runs `shield:generate`.
- Offers to set up [AI translation](settings.md#ai-translation), on PHP 8.3+ and Laravel 12+ only. If the SDK isn't there it offers to run `composer require laravel/ai:^0.11` for you and then stops, because the process that's already running can't autoload what Composer just wrote — start it again with `--ai-only`. Otherwise it asks for the provider, the model (the provider's default, cheapest and smartest models by name, or a custom id) and the API key (never for Ollama, optional when a key is already stored or `config/ai.php` carries one — leave it blank and the stored key is kept, exactly as a blank save on the settings page keeps it), tests the connection once, and saves the settings with AI switched on. A failed test saves nothing and says why.

It never publishes or migrates anything belonging to lin-codex. That is `codex:install`'s job, and running it twice is safe.

Pass `--force` to overwrite already-published files, and `--no-interaction` to take every default (the public help center switched off, the first panel it finds, the installed locales, the starter articles, no publishing, Shield wiring on if the config is there, no AI step). `--ai` answers the AI question with yes, and `--ai-only` runs that one step and nothing else — which is what you want on an install that's already done.

### Register the plugin by hand

If you would rather not let a command rewrite a provider:

```php
use FinityLabs\FinCodex\FinCodexPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            FinCodexPlugin::make(),
        ]);
}
```

Register it on as many panels as you like. Each panel gets its own options, its own drawer and its own copy of the pages.

### Styles

There is nothing to do. The help button, the guest link and the drawer's chrome — its header buttons, search field, tabs and footer link — are Filament's own components, so they follow your panel's colours, radii and font as any other control does. Article content inside the drawer is rendered by lin-codex's partials and styled by its stylesheet, which arrives through the core's hashed route, injected into `<head>` on every panel page; fin-codex remaps that stylesheet's tokens onto the panel's grey and primary scales for light and dark mode. You do not need a custom Filament theme for any of that. The editor's modal pickers (related articles, the context key, the coverage page's attach dialog) are [fin-modal-table-select](https://github.com/finity-labs/fin-modal-table-select) components with views of their own, so if you *do* run a custom theme, add them to its `@source` list:

```css
/* resources/css/filament/admin/theme.css */
@source '../../../../vendor/finity-labs/fin-modal-table-select/resources/**/*.blade.php';
```

The core drawer view stays what a page outside Filament gets. Inside a panel, fin-codex renders its own `Livewire\HelpDrawer`, a subclass of the core component that only names a different view, so every property, action and the Alpine glue are the core's.

## Translations

English, German and Hungarian ship with the package. To adjust the wording:

```bash
php artisan vendor:publish --tag=fin-codex-translations
```

The files land in `lang/vendor/fin-codex/{locale}/fin-codex.php`. There is one file per locale and the key sets are identical across all three, enforced by a test.

Enum labels — article formats, visibility, context types, revision reasons, fallback behaviours, warning kinds — come from lin-codex, not from here. Retranslating one of those overrides the *core's* lang file:

```bash
php artisan vendor:publish --tag=lin-codex-translations
```

A second test asserts that fin-codex never redefines a core enum label, so the two files cannot drift into disagreeing about the same word.

Views can be published too, if you need to change the markup:

```bash
php artisan vendor:publish --tag=fin-codex-views
```

Both are opt-in during `fin-codex:install`, and both default to no, because a published copy stops receiving upstream changes.

## Uninstalling

Run the uninstall command **before** removing the package:

```bash
php artisan fin-codex:uninstall
composer remove finity-labs/fin-codex
```

It removes `FinCodexPlugin::make()` from every panel provider that carries it, drops the article resource from the Shield config, deletes the Shield permission rows, offers to delete the published views and translations, and offers to switch lin-codex's public help center back on at `/help`, since the Help Center page inside the panel goes with the plugin.

**It does not touch your content.** Articles, translations, contexts, revisions, media files, the Codex settings and the AI translation settings all belong to lin-codex and survive removing the Filament layer. If you want those gone too:

```bash
php artisan codex:uninstall
```

**Stored notifications stay too.** The completion notifications the translation job wrote are rows in your application's own `notifications` table, not in anything Codex owns, so neither command touches them. Delete them yourself if you want them gone.

**It also leaves `app/Policies/ArticlePolicy.php` alone**, even though `shield:generate` may have written it. That is exactly the path where an application with its own `App\Models\Article` keeps its own policy, the command cannot tell the two apart, and deleting it is unrecoverable. Remove it yourself if it was ours.

## Testing

```bash
composer test       # Pest
composer analyse    # PHPStan (larastan), level 5
composer format     # Pint
```

CI runs the suite across Filament 4 and 5 (Livewire 3 and 4 respectively) on Laravel 12 and 13, PHP 8.2 to 8.4. The Laravel 11 row is there but allowed to fail: Composer's security audit blocks every tagged 11.x release, so it resolves the `11.x` branch.
