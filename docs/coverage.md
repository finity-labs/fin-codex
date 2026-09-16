# Coverage and warnings

**Help → Coverage** lists every screen in the application and whether it has a help article. The page opens on the panel you are on, with the screens that have no article at the top; clear the panel filter to see every panel at once. "Outside panels" holds the application's own routes plus Filament's export and import download routes.

> **The coverage page is an editor surface.** It deliberately bypasses the article gate and lists every article regardless of the viewer's own read access, so an editor sees the whole picture. Gate the page itself if that matters to you — see [Authorization](authorization.md#authorization).

**Its number is not `codex:coverage`'s.** lin-codex's console command counts routes and credits only what the core's route report matched. The page counts *screens* — a resource's list, create and edit pages fold into one row — and additionally credits a resource-class context. The two numbers legitimately differ, and the navigation badge is the page's.

The panel and coverage filters sit behind the table's filter button and are deferred, Filament's default: nothing happens until you press **Apply**.

**The badges cost one report per panel page render.** Navigation is built on every page and both badges are read eagerly. The content source is read once per request and shared by the drawer, the coverage report and the warnings (the core rebuilds its set once more for warnings, so two reads in all), and the route report is built once. On a large knowledge base that is still a full hydration of every article on every page; if you don't want to pay it, extend the page, return `null` from `getNavigationBadge()`, and name your class through `->coveragePage(...)` — and the same for the warnings count on `->articleResource(...)`.

## Closing a gap from a row

Each uncovered row offers **Write article** or **Attach to an article**, and a covered row offers **Edit article**:

- **One row prefills exactly one context.** A screen behind a Filament page prefills `class:{page or resource}`; a standalone route prefills `route:{name}`. Never both, and never a `url:` pattern.
- **The slug is never guessed.** The create form opens with the title and context filled and the slug empty, because the slug is permanent and stays your decision.
- **A duplicate attach is refused; a wider one is not.** Attaching a context the article already carries writes nothing and says so. A context scoped to another panel, an "any panel" version of one already there, or a `route:` context overlapping a `class:` one are all legitimate and are added without comment. No overlap heuristics — a warning that fires on legitimate input trains people to ignore warnings.
- **A row covered by a declaration in code can't be edited from here.** It shows the slug in grey with "Declared in code" underneath and no link. Change the `HasHelp` class instead.
- **A row covered by a file article offers "Import and edit"** rather than a link, and imports before opening.
- **Attaching a `class:` context on a custom page covers that page on every panel.** The core's route report walks every panel id when it matches, so an article attached to the admin Dashboard row also covers Dashboard elsewhere. A resource-class context does not spread this way.

## Source warnings

When a content source has something to report — broken front matter, a duplicate slug, a `HasHelp` class naming an article that does not exist — a collapsed amber panel appears above the article list and above the coverage table, grouped by kind.

Nothing in fin-codex names or styles a kind: the headings and the sentences come from lin-codex, translated in English, German and Hungarian. Declaration warnings and file warnings share the surface and are told apart only by their heading.

The section is collapsed by default and does not remember. There is no dismiss control and no per-admin state; it re-opens collapsed on every page load, and it isn't there at all when the sources are happy.

**One number per navigation item.** Help articles shows how many content warnings there are. Coverage shows how many screens have no article. Different questions, different numbers, neither standing in for the other. Both amber, both hidden at zero. The escape hatch is the same as the coverage badge's: return `null` from `getNavigationBadge()` on a subclass named through `->articleResource(...)`.
