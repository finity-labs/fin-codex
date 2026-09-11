# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.1] - 2026-09-11

### Fixed

- Apps whose user model uses a string primary key (`HasUuids`, `HasUlids`) got no author on an article, a revision or a media row. Every door into the editor narrowed the panel user's id to `?int` and threw a UUID or ULID away before it reached the write path. The id is now carried as the host model hands it over, so the create and edit pages, the file-article import, the Media tab upload, an image dropped into a body and both Translate missing actions all record the real author. The columns belong to lin-codex and are fixed there in 0.4.1; an install that ran its earlier migrations on a string-keyed user model has to alter them once, see the README's Upgrading section

### Changed

- The author id is typed `int|string|null` where it was `?int`, on `Editor\ArticleWriter`, `Editor\MediaRecorder::store()` and `Editor\FileArticleAdopter::adopt()`, and on the `userId()` method of the article pages, the file-articles table, the revisions relation manager and the Help coverage page. Host code that passes `?int` keeps working
- Requires `finity-labs/lin-codex` ^0.4, the core release that lets a host switch the public help center off; nothing in fin-codex reads that switch yet
- Requires `finity-labs/fin-support` ^0.1.1 for `Panel\PanelUser`, which resolves the panel user's key for the static closures of a schema or an action

## [0.4.0] - 2026-09-10

### Added

- An **AI translation** section on Help settings: the toggle, the provider, the model as the provider's Default, Cheapest or Smartest id or a custom one, an API key that is stored encrypted and never echoed back, the per-call timeout, and the editable half of the translation prompt with **Reset to default**. **Test connection** makes one round trip with whatever the form says right now, **Remove stored key** deletes the stored key on the spot, and the page's single **Save** writes both settings groups — creating the `lin-codex-ai` rows on the first save, so a panel host never runs that migration by hand.
- `fin-codex:install --ai` answers the AI question with yes, and `--ai-only` runs that step and nothing else. The step gates on PHP 8.3 and Laravel 12, offers to install the optional SDK and then stops (the running process cannot autoload what Composer just wrote), and otherwise asks for the provider, the model and the key, tests the connection once and saves. A blank key keeps the one already stored and only writes nothing where nothing is stored, as a blank save on the settings page does.
- **Translate with AI** beside Copy from default language on every non-default tab of a Markdown article, on the create page as well as the edit page. It fills the tab from the default tab's text exactly as it stands in the form, unsaved text included, asks first when the tab already holds something, and saves nothing.
- **Translate missing** on the article list, as a row action beside Edit and as the table's first bulk action. The row action lists the languages that article lacks, pre-checked; the bulk action offers every configured non-default language and gives each selected article only the languages it lacks among the ticked ones. Both queue lin-codex's `TranslateArticle` job, one per article, and both need AI translation to be available and the `update` ability on the article. The bulk summary reports how many articles were queued, how many needed nothing, and how many were skipped because they may not be updated.
- One Filament database notification per finished translation job, for the admin who queued it, under a fixed title that says what happened — *Help article translated*, *Help article translation failed* or *Nothing to translate* — with a body that names the article first and then the languages that arrived and the ones that failed with their reasons, and an **Open article** button. It renders in the language the panel was being read in when the button was pressed, and it belongs to the panel it was pressed in — the press records both its locale and its panel id in Laravel's context, and the job reads the admin through that panel's guard and links that panel's edit page — rather than in the application's language, through the default panel. The job writes it itself, inline, so it lands on any queue driver; a host with no `notifications` table keeps its translations and gets `fin-codex: could not store the translation notification` in the log instead, and any run with a failed language logs `fin-codex: AI translation failed for some languages`.
- `settings.ai.*`, `editor.translate.*`, `editor.translate_missing.*` and `notification.*` translations in English, German and Hungarian.
- README documentation for AI translation end to end: the optional SDK floor, the install step, the settings section, the tab action, the two list actions, the completion notification and one paragraph on the queue.

### Changed

- Requires `finity-labs/lin-codex` ^0.3.1, which hands the throwable behind an `unknown` reason to the host's error tooling once, at the seam that could not name it. Translate with AI no longer reports a stand-in of its own.
- `laravel/ai` is the suggested AI package, in place of the postponed `finity-labs/fin-ai`. It stays optional — fin-codex requires nothing new and names no SDK class.
- The article list carries a checkbox column while AI translation is available: the bulk action is the table's first, and the column goes away with it when AI is off.

## [0.3.0] - 2026-09-09

### Added

- `fin-codex:install` configures the help languages: `--locales=en,de` answers outright, an interactive run is asked with the application's installed locales pre-selected, and a non-interactive run takes the installed locales. The application locale stays the default when it is among them.
- `fin-codex:install` imports eleven starter articles in the configured languages, as ordinary database articles attached to the pages they describe and to the panel the plugin was installed on: an authenticated Help section about the help system — getting help, writing articles, coverage, settings, and declaring help in code (the last attached to the article editor) — and a public Your account section for Filament's own screens — signing in, creating an account, a forgotten password, email verification and the profile page. `--skip-starter-articles` leaves them out; an existing slug is left alone.

- A **Panels** column on the article list, one badge per panel the article's pages target, and a matching panel filter.
- A **Download** action on the Media tab that streams the file through the application under its original name, on any disk.
- **Upload file** on the Media tab, for the documents the body editor's image drop zone cannot take: PDF, Word, Excel, PowerPoint, plain text and CSV by default, replaced with `FinCodexPlugin::documentTypes()` and capped by `documentMaxSize()` (10 MB by default). Documents land in the same dated folders as images, attributed to the article and the uploader.
- **Insert file** under each Markdown body: a modal table of every upload of any article — thumbnail, file name, type, the article it was uploaded to, size and date — that appends the chosen file's Markdown to the body, an image as an image and a document as a link, so a screenshot or a PDF is uploaded once and used wherever it is needed. A linked document downloads rather than opening in place, in the drawer and both previews, through lin-codex 0.2.2's download marking.
- The three pickers that used to be long selects are modal tables, through [fin-modal-table-select](https://github.com/finity-labs/fin-modal-table-select): the related articles on the form and the coverage page's attach dialog pick from a table of title, slug, languages, source and published state; the key of a context row picks from a table of the pages the row's panel and type actually register — the page as the panel names it, the class or route name, whether it is a resource or a custom page, its path and its panels. Search and sort work on the columns. The picked rows show as stacked lists: the related articles with the slug under each title, the context key with the class, or the route name and its path, under the page's label; both lists wrap their lines rather than truncate, since a class or route name is long and the sidebar is not.

### Changed

- The resource and the two pages are mounted at `codex-articles`, `codex-settings` and `codex-coverage` instead of `help-articles`, `help-settings` and `help-coverage`: "help" is a word a host's own pages may want, "codex" is this package's. Route names follow (`filament.{panel}.resources.codex-articles.*`, `filament.{panel}.pages.codex-settings`, `filament.{panel}.pages.codex-coverage`); a stored `route:` context or a bookmark naming the old slugs needs updating, a `class:` context does not.
- The Help group lists articles, coverage and settings in that order, settings last, with or without a `navigationSort()` on the plugin: without one they take sorts 1, 2 and 3 rather than the order the translated labels happen to sort in.
- Uploads are stored under their own slugified name rather than a hash — `User Guide (final).pdf` becomes `user-guide-final.pdf`, a repeat in the same directory `-2` — so the URL an article links and the name a browser saves a document as both read like the upload. Files already stored keep their names.
- Uploads spread over dated folders: `MediaRecorder::directory()` expands the `{Y}`, `{m}` and `{d}` placeholders lin-codex's `media.directory` may carry, and the core's default is now `codex/{Y}/{m}`. A stored image keeps the path it was written under. The default itself lives in lin-codex 0.2.2.
- The editor names articles in the panel's language — the article list, the related-articles options and the coverage page's attach dialog — falling back to the default language when that translation is missing, and to the slug when there is no title at all.
- The article list's filters sit behind Filament's filter button in the table header, Filament's default, instead of being spread above the table.
- Every remaining select in the editor — format, visibility, icon, the language selects on the settings page, the panel and type of a context row, and every table filter — is Filament's styled select rather than the browser's own; the short ones preload their options and drop the search box.
- Requires fin-modal-table-select ^1.1.1 and lin-codex ^0.2.2.

### Fixed

- Clicking an image in the article preview or the revision preview now opens it full size, as in the drawer: both previews carry the core lightbox, teleported to the body so Filament's slide-over cannot trap it. The Media tab opens an image the same way, from its thumbnail or from a new *View* row action; a file that is not an image offers neither.
- The article preview and the revision preview rendered the body straight inside `.codex-root`, so none of the core stylesheet's article rules — headings, paragraphs, lists, code, quotes, images — applied and the text looked nothing like the drawer. Both now wrap the body in `.codex-article__body` with its language, as the drawer and the core partial do, and the revision title takes the article title style.

### Removed

- The **Outdated** badge on the language tabs, the amber ring in the list's languages column and the *Outdated language* filter. The verdict was a timestamp comparison, so correcting a typo in the default language marked every other language outdated, and restoring a revision did the same. A badge that fires on a typo is soon ignored. `Editor\OutdatedTranslations` still computes the verdict and the scope for a host that wants them; the editor shows translated or missing only.
- The *Shown on* column of the contexts repeater. The picked page now carries its own label, key and path, so the column only repeated them.
- The page-access trait, the installer's panel-provider and Shield edits, the policy registration and the panel-user resolver moved to [fin-support](https://github.com/finity-labs/fin-support) and [lin-support](https://github.com/finity-labs/lin-support); fin-codex requires fin-support ^0.1. `Traits\HasPageShieldSupport`, `Commands\Concerns\*`, `Panel\Concerns\ResolvesPanelUser` and `Auth\ArticlePolicyRegistration` are gone from this package; a host page that used the trait imports `FinityLabs\FinSupport\Pages\Concerns\HasPageShieldSupport` instead.

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
