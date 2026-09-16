# Upgrading and known limitations

## The starter articles have changed

Since 0.5.1 `fin-codex:install` corrects the starter articles it imported for you, so re-run `php artisan fin-codex:install` to pick up this version's text. 0.5.0 rewrote the end of **Getting help** and added the article it links to, and until now only a brand new install ever got either. Every step of the command is safe to repeat.

The refresh moves the title, excerpt and body of one starter article in one language, and nothing else. Where the article sits, the pages it is attached to, the panel it belongs to, whether it is published, its visibility, order, icon, keywords, related articles and metadata all stay exactly as you arranged them. With revisions on, the text it replaced is kept as a revision, so you can read what changed and put it back.

**An article you have edited is left alone.** The decision is made per article and per language, and it is made by looking at the text, not at timestamps: the package keeps a record of every text it has shipped for each starter article since 0.4.0, so a translation that still carries one of them is refreshed, and one that carries anything else — a hand edit, an AI translation, a wording change of one letter — is yours and is skipped. That holds across upgrades: an article refreshed today is refreshed again when the docs change next. An article whose languages all carry your own text, including one you wrote under a starter slug before installing, is treated as yours entirely and gets no language filled in beside it. The command prints the skipped ones by name, so you know which of your articles are now behind the shipped docs.

No flag overrides that skip; `--force` on this command still means already-published files and nothing else. If you want the shipped text for one of them, read it under the package's `resources/docs/{locale}/`, or delete the article and re-run the installer.

A language you configured after installing is filled in on the same run: add it on the Help settings page, re-run the command, and the starter set arrives in it. Before 0.5.1 it never did.

## The public help center is off

Since 0.5.0 `fin-codex:install` sets `lin-codex.routes.help_center` to `null`, and the core registers neither public help-center route when it is. `/help` and `/help/{slug}` answer 404. Help lives at `{panel}/help` instead, inside the panel and behind its login.

Installed before 0.5.0? Re-run `php artisan fin-codex:install`. Every step of that command is safe to repeat and the switch is the one that is new. If you set a prefix of your own, the command prints it and asks before overwriting it — say no and both help centers keep answering.

Run `php artisan route:clear` afterwards if you cache routes.

**If you published the views**, check them. Your copies of `panel/button.blade.php` and `panel/guest-link.blade.php` still resolve the core's help-center route by name, and that route is now gone, so they throw. Re-publish them with `php artisan vendor:publish --tag=fin-codex-views --force`, or port the change by hand.

One thing the switch does not change: outside a panel — the JSON API, a queued render — the core still builds root-relative `/{slug}` links. That is lin-codex's documented contract, and reading those links needs the public page back on.

## UUID or ULID user models

Since 0.4.1 the editor stores the panel user's key as the host model hands it over, so an app whose user model uses `HasUuids` or `HasUlids` gets the real author on an article, a revision and a media row. Until then the id was narrowed to `?int` on the way in and every one of those was recorded as nobody.

The columns belong to lin-codex. An install that ran its earlier migrations on a string-keyed user model has integer columns that cannot hold the key: follow [lin-codex's UUID or ULID user models note](https://github.com/finity-labs/lin-codex#uuid-or-ulid-user-models) to alter the four columns once. Installs on the default integer user model need nothing.

Host code that calls the editor's write path directly (`Editor\ArticleWriter`, `Editor\MediaRecorder`, `Editor\FileArticleAdopter`) now types the author `int|string|null`; widen anything that passes `?int` through.

## Known limitations

Nothing here is speculative — these are the things this release knows it doesn't do, or hasn't checked.

**Three behaviours are proven by contract in the test suite but have never been clicked in a real browser.** This package has no browser runner, and the harness cannot render the surfaces involved:

1. **Global search's "Open here".** The result action dispatches the drawer-open event, but Filament's own result anchors carry an Alpine `close()` that ours does not, so the search dropdown may stay open behind the drawer. The harness renders no search field at all on any fixture panel, which is limitation 1 in the [Global search](help-center.md#global-search) section biting the tests too.
2. **The field hint's drawer open.** The rendered handler string, the absent `wire:navigate` and the SPA exception list are all asserted; the click itself is not.
3. **The `?codex=slug#heading` deep link.** The drawer scrolls to a heading after the article renders, which is Alpine behaviour with no server round trip and nothing to assert against.

Also worth knowing:

- **An article-to-article link inside a rendered body reloads the page on an SPA panel.** The core renderer writes those links root-relative, which is the form the help-center SPA exception matches, so on a panel that called `->spa()` the click is a full page load rather than a Livewire swap. The address bar, the back button, copy-link and open-in-new-tab all hold; it costs feel, not function.
- **A completion notification whose Open article URL cannot be built arrives without its button.** A panel with tenancy, whose routes all sit under a tenant segment the worker cannot name, is the case that happens; a resource override the worker cannot route, or a panel changed since the press, do it too. The notification itself is not lost — it still names the article and the languages it gained — and the panel's log carries a `debug` line with the article id and the panel id.
- **Changing a media disk's URL root after articles exist is not supported.** The in-use check that protects a media file from deletion looks for the URL the disk builds today.
- **Media rows orphaned by an article delete are not cleaned up.** They keep their file and lose their `article_id`, and appear on no Media tab.
- **Re-importing a file article over an existing database row** is not available; the import hands back the existing row instead.
- **Filament 5's multi-configuration resource registrations are not scanned** for `HasHelp` declarations.
