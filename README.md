# Codex for Filament

<img class="filament-hidden" alt="finity-labs-fin-codex" src="https://raw.githubusercontent.com/finity-labs/fin-codex/main/docs/screenshots/finity-labs-fin-codex.png">

[![FILAMENT 4.x](https://img.shields.io/badge/FILAMENT-4.x-EBB304?style=flat-square)](https://filamentphp.com/docs/4.x/panels/installation)
[![FILAMENT 5.x](https://img.shields.io/badge/FILAMENT-5.x-EBB304?style=flat-square)](https://filamentphp.com/docs/5.x/panels/installation)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/finity-labs/fin-codex.svg?style=flat-square)](https://packagist.org/packages/finity-labs/fin-codex)
[![Tests](https://github.com/finity-labs/fin-codex/actions/workflows/tests.yml/badge.svg)](https://github.com/finity-labs/fin-codex/actions/workflows/tests.yml)
[![Code Style](https://github.com/finity-labs/fin-codex/actions/workflows/style.yml/badge.svg)](https://github.com/finity-labs/fin-codex/actions/workflows/style.yml)
[![License](https://img.shields.io/packagist/l/finity-labs/fin-codex.svg?style=flat-square)](https://packagist.org/packages/finity-labs/fin-codex)

In-app help for Filament panels. A help drawer on every page that opens on the articles written for that screen, a help center inside the panel, question-mark hints next to form fields, and an editor where your admins write and translate the articles without leaving Filament.

The content layer is [lin-codex](https://github.com/finity-labs/lin-codex): tables, Markdown rendering, search, visibility rules and a JSON API that work in any Laravel app. This package is the panel on top of it.

- **Help drawer** — opens from the topbar button, `ctrl+/`, a `?codex=slug` link or a `codex:open` event, and shows the articles attached to the current screen first. Guests get it on the login and registration pages too.
- **Help center** at `{panel}/help` — the whole library with a contents tree, search and an "On this page" outline, styled by the panel's own theme.
- **Contextual help** — attach articles to resources and pages from the editor, or declare them in code with `HasHelp`. Each panel reads its own articles plus the general ones.
- **Field hints** — `TextInput::make('slug')->codexHelp('articles/slugs')` puts a question mark next to the field that opens the article at a heading.
- **Editor** — Markdown with image and document uploads, one tab per language, revision history with restore, AI translation from the editor or queued from the list, and one-click import of articles that live as files on disk.
- **Coverage report** — every screen in the application and whether it has an article yet, with row actions to write, attach or import one.
- **Authorization** you can replace — a shipped policy, Gate abilities for the pages, and Filament Shield support.
- English, German and Hungarian UI, twelve starter articles in all three.

## Screenshots

<details>
<summary><b>📖 Reading help</b> — the drawer, the help center, field hints, guests</summary>
<br>

**The drawer, opened on a resource page.** The articles for this screen sit under **This page**, everything else under **Browse**, and the badge on the button counts them.

<img src="https://raw.githubusercontent.com/finity-labs/fin-codex/main/docs/screenshots/drawer.png" alt="The help drawer open beside a Filament resource table" width="880">

**The help center.** Contents on the left, the article in the middle, its headings on the right, all in the panel's colours.

<img src="https://raw.githubusercontent.com/finity-labs/fin-codex/main/docs/screenshots/help-center.png" alt="The help center page inside the panel" width="880">

**Field hints.** A question mark next to a form field opens the drawer at the right heading.

<img src="https://raw.githubusercontent.com/finity-labs/fin-codex/main/docs/screenshots/field-hint.png" alt="A form field with a Codex help hint and the drawer open" width="880">

**Help for guests.** The login page gets a "Need help?" link and the same drawer over the public articles.

<img src="https://raw.githubusercontent.com/finity-labs/fin-codex/main/docs/screenshots/guest-drawer.png" alt="The login page with the help drawer open" width="880">

</details>

<details>
<summary><b>✍️ Writing help</b> — the editor and the coverage report</summary>
<br>

**The editor.** One tab per language with a Missing badge where a translation is absent, a Markdown body with image and document uploads, and the slug, parent, order and publishing state beside it.

<img src="https://raw.githubusercontent.com/finity-labs/fin-codex/main/docs/screenshots/editor.png" alt="The article edit page with language tabs and the identity sidebar" width="880">

**Coverage.** Which screens still have no article, and a way to fix each one from its row.

<img src="https://raw.githubusercontent.com/finity-labs/fin-codex/main/docs/screenshots/coverage.png" alt="The coverage page listing panel screens and their articles" width="880">

</details>

## Quick example

```php
use FinityLabs\FinCodex\FinCodexPlugin;

// Register on a panel. Every option is per panel and optional.
$panel->plugins([
    FinCodexPlugin::make()
        ->shortcut('ctrl+/')
        ->globalSearch(),
]);
```

```php
use FinityLabs\FinCodex\Help\HasHelp;
use FinityLabs\FinCodex\Help\WithHelp;

// Attach articles to a resource in code. The drawer opens on them
// on the list, create, edit and view pages of this resource.
class UserResource extends Resource implements HasHelp
{
    use WithHelp;

    protected static array $helpArticles = ['users', 'user-roles'];
}
```

```php
// A question mark next to a field, opening the article at a heading.
Select::make('role')->codexHelp('users', 'assigning-a-role');
```

Articles can also be attached from the editor, without touching code: pick the panel, the resource or page, and save.

## Installation

```bash
composer require finity-labs/fin-codex
php artisan codex:install        # lin-codex: tables and settings
php artisan fin-codex:install    # registers the plugin, picks languages, imports the starter articles
```

The install command lists the panels it found, asks which languages your help is written in, offers twelve starter articles about the help system and Filament's own account pages, and wires up Filament Shield if it's installed. Every step is safe to re-run. Details, the flags and manual registration are in [Installation](https://github.com/finity-labs/fin-codex/blob/main/docs/installation.md).

## Documentation

| Guide | Covers |
|-------|--------|
| [Installation](https://github.com/finity-labs/fin-codex/blob/main/docs/installation.md) | Requirements, the two packages, the install command, styles, translations, uninstalling |
| [Plugin options](https://github.com/finity-labs/fin-codex/blob/main/docs/plugin-options.md) | Every per-panel option, class overrides, a panel that reads but doesn't author |
| [Contextual help](https://github.com/finity-labs/fin-codex/blob/main/docs/contextual-help.md) | `HasHelp` declarations, field hints, how panels scope what a reader sees |
| [The drawer and the help center](https://github.com/finity-labs/fin-codex/blob/main/docs/help-center.md) | Opening the drawer, the help center page and its placement, locale, global search |
| [Writing articles](https://github.com/finity-labs/fin-codex/blob/main/docs/editor.md) | The editor, contexts, languages and AI translation, images and documents, revisions, media |
| [Settings](https://github.com/finity-labs/fin-codex/blob/main/docs/settings.md) | Languages, fallback, revision retention, AI translation setup |
| [Coverage and warnings](https://github.com/finity-labs/fin-codex/blob/main/docs/coverage.md) | The coverage report, closing a gap from a row, source warnings |
| [Authorization](https://github.com/finity-labs/fin-codex/blob/main/docs/authorization.md) | The shipped policy, the nine abilities, gating the pages, Filament Shield |
| [Upgrading and known limitations](https://github.com/finity-labs/fin-codex/blob/main/docs/upgrading.md) | What changed between releases and what this release doesn't do yet |

## Compatibility

| Package | Filament | Laravel | PHP | lin-codex |
|---------|----------|---------|-----|-----------|
| 0.x | 4.x / 5.x | 11, 12, 13 | 8.2+ | ^0.4.2 |

AI translation needs PHP 8.3+, Laravel 12+ and [`laravel/ai`](https://github.com/laravel/ai) ^0.11.

## License

MIT. See [LICENSE](https://github.com/finity-labs/fin-codex/blob/main/LICENSE).
