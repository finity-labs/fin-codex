# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-09-09

### Fixed

- Saving Help settings opened an empty "Remove a language?" box on every save, an untouched form included: Filament opens a modal for any action with a custom heading, whatever the confirmation flag says. The save now only opens the modal when a language is actually being removed.

### Added

- A *Keep the translations* checkbox in the language-removal confirmation, ticked by default. Unticked, the save deletes every translation and revision in the removed languages in the same transaction as the settings write, and says so in a notification.

### Changed

- The help button is Filament's own icon button, in the primary colour, sized like the notification bell, with the page's article count as its badge, and it now sits beside the user menu by default: `helpButtonRenderHook()` defaults to `USER_MENU_AFTER`, which Filament renders in the topbar's end group next to the notification bell, or in the sidebar footer on a panel without a topbar. `TOPBAR_END` — the old default — landed after that group closed. The fallbacks only apply to a panel with no user menu.
- The drawer is presented with Filament's schema components. fin-codex renders `Livewire\HelpDrawer`, a subclass of the core component that keeps every property, action and the Alpine glue and adds three schemas — `header()`, `content()` and `footer()` — built from icon-button and link actions, a live search `TextInput`, `Tabs`, `Section`s for tree groups and the table of contents, `Text` and `Html`; a host overrides any of the three on a subclass. Only the panel, the overlay, the scrolling body and the lightbox stay in Blade. The rendered article body is still styled by the core stylesheet, whose tokens are remapped onto the panel's grey and primary scales for light and dark mode. The guest link on simple-layout pages is a Filament link.
- Requires lin-codex ^0.2.1 for the overridable drawer view.

## [0.1.1] - 2026-09-08

### Fixed

- `policyNamespace()` only took effect on the default panel: the policy was registered once at provider boot, from whichever panel was the default. Each panel now registers its own namespace when it boots for a request; provider boot keeps the default panel's (or `App\Policies`) for console commands, queues and routes outside any panel.
- A plain panel page hydrated the whole knowledge base five times — the drawer, the coverage badge (twice, through the core's route report), the warnings badge and the declared-slug check each read the content source. The decorated source now reads the core once per request and drops that reading when an article, a translation or a context is written.
- Converting an HTML article to Markdown kept no revision of the HTML while revisions were switched off, which is a fresh install's default, although the confirmation said it would. The HTML is now snapshotted whatever the switch says, and the confirmation says when revisions are off and the Revisions tab is therefore hidden.
- Emptying a non-default language tab deleted the translation without a snapshot even with revisions on. The writer now snapshots it first while revisions are on, and a tab with only a title or only a body is refused — by the form with a validation message, and by `ArticleWriter` with an exception — instead of being deleted.
- Restoring a revision left the edit form holding the text from before the restore, so the next Save put it straight back. The restore now runs in one transaction and the edit page reloads afterwards.
- `articleResource()` reached only the navigation statics: the built-in pages named the base resource, so a subclass's form, table, query or relation managers never applied. The pages now resolve the resource through the serving panel's plugin, and the coverage report files each panel's pages under that panel's resource.
- Renaming a section whose descendants would land on slugs already in use surfaced a raw database error after a rollback. It is now a validation error on the slug field.

### Added

- Lang keys `editor.convert.description_revisions_off` and `editor.validation.descendant_conflict` in en, de and hu.
- `FinCodexPlugin::articleResourceClass(?string $panelId)`, the resource class in force for a panel.
- `Auth\ArticlePolicyRegistration`, the one place that registers the article policy for a namespace.


## [0.1.0] - 2026-09-07

First release. fin-codex is the Filament panel layer over [lin-codex](https://github.com/finity-labs/lin-codex): the help drawer, the article editor and the surfaces around them. Content, search, visibility and the JSON API stay in the core.

### Added

- `FinCodexPlugin`, registered per panel, with fluent options for the help button and its render hook, the keyboard shortcut, the drawer width, the guest drawer, global search, the navigation group and sort, the policy namespace, and class overrides for the article resource and the two pages.
- The help drawer on every panel page, mounted through the panel's own render hooks so two panels never see each other's output. The topbar help button carries a badge with the article count for the current page and falls back to the sidebar footer on a panel with no topbar.
- Page identity resolved from the route — panel id, guard, page class and resource class — so the button and the drawer always ask about the same screen, and a Livewire update request never reports a framework class as the page.
- Guest support: a "Need help?" link and the same drawer on the login, registration, password-reset and email-verification pages, and on any host `SimplePage`.
- `HasHelp` and the `WithHelp` trait for declaring articles in code, on a resource, a resource page or a custom page, as one list or as a map keyed by panel with `'*'` as the default. Declarations fold in as synthetic contexts, so the drawer, the badge, the coverage page and the core's JSON API all agree without a core change.
- `CodexHelp::make($slug, $heading)` and the `Field::codexHelp()` macro: a question-mark hint button that opens the drawer at a heading, hides itself when the article is missing or the viewer may not read it, and degrades to a plain help-center link on a page with no drawer. The help-center route is added to Filament's SPA exceptions so the intercept wins on an SPA panel.
- The article editor as a Filament resource over the core's `Article`: list and filters, a "From files" tab that imports file articles into the database, an identity/publishing/discovery form, a picked-not-typed contexts repeater, one tab per language with copy-from-default, image uploads to the core's media disk, a rendered preview, HTML-to-Markdown conversion in one transaction, and a delete modal that names the children and media rows it affects.
- Revision history as a relation manager on the article: author, time and reason, a preview rendered in the revision's own format, and a restore that snapshots the current text first so it can be undone the same way.
- A media manager on the article, with a delete that refuses while any translation body still references the file and names each article and language.
- The Help settings page for languages, the default language, the missing-translation fallback and revision retention, writing to the core's `codex` settings group. Nothing is written until the first save, removing a language keeps its translations, and removing the current default is refused.
- The Help coverage page: one row per screen, uncovered first, with panel and coverage filters, a navigation badge, and row actions to write a new article with the context prefilled, attach an existing one, edit, or import a file article.
- A source-warnings panel above the article list and the coverage table, grouping broken front matter, duplicate slugs and `HasHelp` declarations that name articles which do not exist, under the core's own kind labels.
- Optional global search: a gated Help category appended to the panel's own search provider, off by default. The article resource itself stays out of global search on purpose.
- `Policies\ArticlePolicy` with `viewAny`, `view`, `create`, `update`, `delete`, `restore` (revision restore), `import` (adopting a file article) and `convert`, registered for the core's `Article` and replaced by a host policy at `{policyNamespace}\ArticlePolicy` when one exists. A policy that defines only the five standard abilities keeps working: `restore` and `convert` fall back to `update`, `import` to `create`.
- Filament Shield support on the settings and coverage pages through `HasPageShieldSupport`, and the eight resource abilities in the Shield config. Without Shield, both pages take an opt-in `page_{PageClass}` Gate ability and stay open when none is defined.
- `fin-codex:install` and `fin-codex:uninstall`: plugin registration in a panel provider, the optional publish groups, and the Shield config and permission wiring. Neither command touches a core table, setting, media file or revision.
- English, German and Hungarian translations, with tests for key parity, for untranslated copies of the English strings, and against redefining a core enum label.
