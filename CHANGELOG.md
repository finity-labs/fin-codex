# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
