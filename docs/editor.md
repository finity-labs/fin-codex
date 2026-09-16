# Writing articles

## The article editor

**Help → Help articles** is a normal Filament resource over lin-codex's `Article` model. Titles are shown in the panel's language, falling back to the default language. A **Panels** column shows which panels an article's pages target ("any panel" for a context without one), and the filters cover published state, visibility, format, source, panel and per-language translation state.

Next to the article list sits a **From files** tab. If lin-codex is reading articles off disk as well as out of the database, every file article that has no database row yet is listed there with an **Import and edit** button. Importing creates the database row through the core's importer and opens it. The import is idempotent: if a row already exists for that slug it is handed back rather than overwritten, so pressing the button twice opens what the first press created. Re-importing changed file content over an existing article is not supported yet.

**A database article shadows its file completely.** Once a slug exists in the database, the file version is ignored for every language, and the edit page says so. Nothing is deleted from disk.

**A slug is permanent.** It becomes the article's identity and its file path, so the editor does not let you change it after creation and never guesses one for you. The parent segment must already exist — but a file-only parent counts, and a child imported before its parent keeps a null parent until the parent arrives and the core relinks it.

### Contexts

The Contexts repeater in the form's sidebar is where you say which screens an article shows up on. Contexts are always **picked, never typed** — the panel and the type are selects, and the target opens a modal table of what the chosen panel actually registers for that type: for `class:` every resource and custom page with its navigation label, class, kind, path and panels; for `route:` every named GET route with the page it leads to, its name, path and panel. The picked page shows its label with the class, or the route name and path, underneath. `*` means "any panel", widens the table to every panel, and is stored as a null panel id. The modal is [fin-modal-table-select](https://github.com/finity-labs/fin-modal-table-select), which the related-articles field and the coverage page's attach dialog use too.

Contexts that come from a `HasHelp` class are listed above the repeater as read-only rows and never enter form state. The mapping lives in code, so that's where you change it.

### Languages

One tab per language from the [settings](settings.md#settings). Each tab holds the title, excerpt and body for that language, plus **Copy from default language** for starting a translation from the current default text. A non-default tab is optional as a whole: it is saved when title and body are both filled, refused with a validation message when only one of them is, and deleted when both are emptied — after a snapshot while revisions are on, so the text it held is one restore away.

**Translate with AI** sits beside Copy from default on every non-default tab of a Markdown article, on the create page as well as the edit page, while [AI translation](settings.md#ai-translation) is on and you may update the article — when the button isn't there, the settings page says why. It sends the default tab's title, excerpt and body exactly as they stand in the form, unsaved text included, and fills this tab with the answer. A tab that already holds text asks before it's replaced; an empty one just runs. Nothing is saved until you save the article yourself, and a failure leaves the tab as it was, with a notification naming the reason. Until the default language has both a title and a body the button is there but disabled, with a tooltip saying so.

The call runs inside the request and waits for up to the timeout in the settings — 120 seconds by default — so your web server's own read timeout has to sit above it, or the translation dies before the model answers. nginx's `fastcgi_read_timeout` is 60 seconds out of the box; `Timeout` is Apache's and `request_terminate_timeout` php-fpm's. Raise those or lower the setting. For a provider slow enough to make that awkward, queue the work from the list instead: [Translate missing](#translating-from-the-list) runs the same translation in the background.

A language is either translated or **Missing**, in the tabs and in the list's languages column, with a *Missing language* filter. Missing means no translation for that language, or one whose title or body is empty — the same rule everywhere it is asked, so the filter, the column and both translate actions name the same articles. There is no "outdated" marking: the editor cannot tell a corrected typo in the default text from a rewrite, and a badge that fires on both is soon ignored. `Editor\OutdatedTranslations` still computes which translations were saved before the default language, and the scope behind it, for a host that wants to surface that itself.

### Translating from the list

Two actions on the article list fill an article's gaps without opening the editor. Both hand the work to lin-codex's queued translation job instead of running it in the request, and both need [AI translation](settings.md#ai-translation) to be available — when it isn't, neither is there.

**Translate missing** sits beside Edit on every article that still lacks a language and that you may update. Its modal lists only the languages that article is missing, all ticked, and queues one job for the ones you leave ticked; untick them all and the press is refused with a line under the list rather than a greyed button. The languages appear on the list when the job finishes. If the default language has no title or no body there is nothing to translate from — the button stays, and the modal says which language to fill in first and offers nothing to submit. (The editor greys its own button and hangs a tooltip on it. A table row has no room for a tooltip, and a button that simply isn't there explains nothing.) Only missing languages are ever offered: a translation that went stale because the default text changed afterwards is a judgement call about text that already exists, so refresh that one from the editor, where you can read both versions first.

The same action in the toolbar does a selection at once. Tick the articles, and the modal offers every configured non-default language, all ticked, with the number of selected articles in its description. Each article then gets only the languages it still lacks among the ones you left ticked: one job per article that has something to do, and nothing at all for an article that lacks nothing. The summary names how many articles were queued and how many needed nothing, plus how many were skipped because you may not update them, when that happened. There is no limit on the selection.

When a job finishes, the admin who queued it gets one Filament database notification per article. The title is a fixed label saying what happened — *Help article translated*, *Help article translation failed* or *Nothing to translate* — and never the article's own title, which would read as news about whatever the article is called. The body names the article in its first sentence, then the languages that arrived and the ones that failed with the reason for each. Green when everything landed, amber the moment one language fails, and an **Open article** button that opens the article's edit page on the panel you pressed the button in. An article deleted before the job finished is named by its id in that same sentence, and gets no button. The whole text — the fixed title, the article's own title, the language names and each failure's reason — renders in the language the panel was being read in when the button was pressed, not the application's. The press records both its locale and its panel in Laravel's context, which travels with the job and is restored on the worker, so a panel switched to English is answered in English however `app.locale` is configured and whatever queue driver you run, and a second panel on its own guard is answered through that guard and links its own pages rather than the default panel's. A job queued outside these two actions falls back to the locale the worker is running under and to your default panel.

The bell needs two things from you, neither of which fin-codex checks for: Laravel's notifications table, and database notifications on the panel.

```bash
php artisan make:notifications-table
php artisan migrate
```

```php
$panel->databaseNotifications()
```

The job writes the notification itself, inside the job, so a panel without the bell loses nothing: the translations are written either way and the list's language flags catch up on the next load. A missing table, or a user model that can't be notified, is logged as an error with the article, the admin and the whole report, and the job still finishes. Every failed language is logged as a warning as well, bell or no bell — grep for `fin-codex: AI translation failed for some languages`.

**The queue.** The job goes to your default queue connection and queue unless `lin-codex.ai.queue` names another. On the `sync` driver it runs inside the request, so the translation and the notification are both there by the time the page comes back — a press raises PHP's execution limit for the calls it is queuing, as the editor's own button does for its one call, but your web server's read timeout still applies, as [above](#languages). With a worker there is one thing to check: the job sizes its own timeout as languages × the settings timeout + 30 seconds, and the database and Redis drivers re-deliver a job that is still running once `retry_after` has passed — 90 seconds by default, well under that — so raise `retry_after` on the connection above the job's timeout or the same article gets translated twice at once. [lin-codex's README](https://github.com/finity-labs/lin-codex#missing-translations-and-the-queued-job) does the arithmetic.

An HTML article is translated as it stands, tags included; the editor's advice to convert it to Markdown first applies here too. The tab action hides on an HTML article because its body is read-only in the editor — these two don't judge the format.

### Images

Drop an image into a Markdown body and it uploads to lin-codex's `media.disk` and `media.directory`. Those are core config, not plugin options — a host that wants help images somewhere else sets them in `config/lin-codex.php`. The directory takes the placeholders `{Y}`, `{m}` and `{d}`, expanded at upload time to the year, month and day, and the core's default is `codex/{Y}/{m}`, so a busy site's images spread over dated folders instead of one flat directory. A stored image keeps the path it was written under.

An image in a preview, in the revision preview and in the Media tab opens full size when clicked, the way it does in the drawer. The Media tab's **Download** action streams the file through the application with its original name, so it works on any disk.

**Documents.** A PDF or an office file comes in through the Media tab's **Upload file** action, since the body editor's drop zone takes images only. `FinCodexPlugin::make()->documentTypes([...])` replaces the accepted MIME types (PDF, Word, Excel, PowerPoint, plain text and CSV by default; never an archive, a script or an SVG) and `->documentMaxSize(20480)` the ceiling in kilobytes. Documents land in the same dated folders as images, with the same row. Every upload is stored under its own name, slugified for the URL — `User Guide (final).pdf` becomes `user-guide-final.pdf`, and a repeat in the same month gets `-2` — so the link an article carries and the name a browser saves the file as both read like the upload; the media row keeps the original name.

**Reusing a file.** An upload belongs to the article it was dropped into, but any article may use it: **Insert file** under each Markdown body opens a table of every upload of any article — thumbnail, file name, type, the article it was uploaded to, size and date — and appends the chosen one's Markdown to the body: an image as an image, a document as a link with the file name as its text. The core stamps a link to a document with a `download` attribute, so in the drawer and the previews it saves the file rather than leaving the article. Deleting a file is refused while any article's body still references it, whichever article uploaded it.

**Every upload is public.** Images and documents sit on the public disk and are reachable by anyone holding the URL, which is what help material usually wants. Internal documents would need authenticated delivery — a per-file flag, a private disk and a gated download route — which is planned (EXT-09 in the package's planning notes) and not built.

The disk needs a `url`, or the editor cannot show what was just uploaded. SVG is refused. Removing an image from a body leaves its `codex_media` row behind for the [media manager](#revisions-and-media) to clean up.

### Preview, converting and deleting

**Preview** renders the language tab you are on, through the core renderer, in the panel's theme. One tab at a time; per-tab buttons and a live preview are not in this release.

**An HTML article is converted, not edited.** Its body stays read-only until one confirmed action rewrites every translation as Markdown in a single transaction. The original HTML survives as a revision whether or not revisions are switched on, so the conversion is reversible by restoring it; while they are off the confirmation says so, because the Revisions tab stays hidden until you enable them.

**Renaming a section renames every descendant with it.** The rename is refused, on the slug field, when one of the slugs the descendants would take is already in use.

**Deleting says what else it takes with it.** The modal names the child articles that lose this parent and where each one lands, and the media rows that lose their article. If the article is authenticated and has public children, those children would become guest-visible once the parent is gone — so the modal offers a checkbox, on by default, that sets them to authenticated instead. Untick it deliberately.

## Revisions and media

Both relation managers live on the article edit page. Both are **lazy**: they load when you scroll to them, not with the page. If you want one to load eagerly, extend it and set `protected static bool $isLazy = false;`. Neither carries a count badge — `getBadge()` is the hook if you want one, at one extra query per page render.

### Revisions

Every saved change to a language is recorded while revisions are on, with the author, the time and a reason. Reason labels come from lin-codex's own lang files and follow the panel locale, so retranslating them means overriding the *core's* file, not this package's. A revision whose author has since been deleted reads "Unknown"; revisions are never orphaned by a user delete.

Preview renders a revision through the core renderer, in the revision's own format — an HTML snapshot of an article that has since been converted to Markdown still reads correctly. It is a rendered article, not a diff.

Restoring loses nothing. The text about to be replaced is written to the same history first, tagged as a restore, so any restore can be undone by restoring the row it created. Restoring a pre-conversion HTML revision turns the article back into an HTML article. The edit page reloads after a restore, so the form shows the restored text rather than what it held before.

**Turning revisions off removes the Revisions tab.** With Media left as the only visible relation manager, Filament renders no tab strip at all and the Media table appears bare. That is expected, and the settings toggle's helper text says so.

### Media

The Media tab has no upload button on purpose — a file uploaded there would be one no article body points at. Uploads only ever arrive through the Markdown editor.

A delete is refused while any translation body still shows the file, and the refusal names each article slug and language. **Known limitation:** the scan looks for the URL the file's disk builds *today*. A body written while the disk's `url` config was different won't match, and such a file would delete without a warning. Changing a media disk's URL root after articles exist is not supported.

Deleting a file removes both the row and the file. A missing file, and a disk that has been taken out of `filesystems.disks`, both delete cleanly. A file whose disk is gone, and any non-image upload, show a "No preview" box rather than breaking the tab.

Deleting an *article* leaves its `codex_media` rows with a null `article_id`. Those orphans appear on no Media tab, and cleaning them up is out of scope for 0.1.
