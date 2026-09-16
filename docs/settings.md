# Settings

**Help → Help settings** holds the languages, the default language, the missing-translation fallback and revision retention. It writes to lin-codex's `codex` settings group, so the values apply everywhere the core reads them, not only in the panel.

**Nothing is written until you press Save.** A fresh install opens on the packaged defaults — one language derived from `app.locale`, revisions off, ten kept — and the settings rows appear on the first save. The page also works before the settings migration has run: a missing row and a missing table fall back the same way.

**Removing a language asks what to do with its texts.** Dropping a code opens a confirmation that names each language going away with how many translations it holds, counted live from what the form says right now, and one checkbox: *Keep the translations*, ticked by default. Ticked, the language leaves the editor tabs and the reader's fallback chain and nothing is deleted; add the code back and every text returns exactly as it was. Unticked, every translation and revision in those languages is deleted in the same transaction as the settings write. A save that removes no language never asks.

**The current default language is the one removal the page refuses.** It reports twice — once on the language list and once on the default-language select — because those are the two fields that have to agree. Pick a different default first and the same edit goes through in one save.

**Lowering "Revisions kept per language" prunes nothing retroactively.** It moves the ceiling from that point on. Stored revisions stay until new ones push them out, one article and one language at a time, inside the core's snapshot path. Saving settings never triggers a bulk delete.

## AI translation

A fourth section appears once `laravel/ai` is installed. Without it you get one note naming what it takes — PHP 8.3+, Laravel 12+ and `composer require laravel/ai` — and nothing else about AI.

A status line at the top says whether translation can run right now: *AI translation is available* in green, or the reason in amber — the SDK is missing, the AI settings were never migrated, the switch is off, or there's no provider and no key. That line is the counterpart of the button in the editor. [Translate with AI](editor.md#languages) hides itself for those same four reasons, and so do the [Translate missing](editor.md#translating-from-the-list) actions on the article list — which queue lin-codex's translation job rather than translating in the request. This is the page that says which reason it is.

The fields stay editable while the switch is off:

- **Enable AI translation** puts the button on the language tabs.
- **Provider** lists what lin-codex offers; [its README](https://github.com/finity-labs/lin-codex#providers-and-models) has the list and which providers translate reliably.
- **Model** offers the provider's Default, Cheapest and Smartest models under their current ids, plus **Custom model…** and a field for any other id. Changing the provider resets it to the new provider's default. What's stored is always a concrete id.
- **API key** is a revealable password field that never echoes what's stored: a stored key shows as the placeholder *A key is stored. Leave blank to keep it.*, and saving with the field blank keeps it. The key is encrypted at rest, in lin-codex's settings rather than in your `.env`.
- **Timeout** is the seconds one translation call may take, 10 to 600, 120 by default. It applies to the editor button and to the queued job.
- **Translation instructions** is the editable half of the prompt. The package's own rules run first and can't be switched off — return title, excerpt and body, keep code, links, image paths, callout markers and steps fences untranslated ([what the prompt keeps](https://github.com/finity-labs/lin-codex#what-the-prompt-keeps)) — and this text is added after them. **Reset to default** puts the packaged text back, asking first when you've changed it.

Test connection and the translations use the key you type, else the stored key, else the one in `config/ai.php`.

**Test connection**, the signal icon beside the key field, makes one round trip with whatever the form says right now. It names the provider and the model on success and the reason on failure, works whatever the switch says, and it never saves.

**Remove stored key** deletes the stored key on the spot, without saving the rest of the page. The placeholder then reads *No key stored; the env key is used when set.* The button is hidden while there's nothing to remove.

The AI values are written by the same **Save** as everything else on the page, into lin-codex's `lin-codex-ai` settings group. In a panel you never run that group's migration by hand — the first save creates its rows. (The migration step in [lin-codex's README](https://github.com/finity-labs/lin-codex#setup) is for hosts running the core without a panel.)
