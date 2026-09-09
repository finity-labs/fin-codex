---
title: Help settings
excerpt: Languages, the reading fallback and revision retention.
order: 4
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Pages\HelpSettings
---

**Help → Help settings** holds the choices that apply to every article.

## Languages

One row per language the editor offers, with its code, display name and flag. Each row shows how many translations already exist in that language. The **default language** is the one every article must have; it is required on every save and is what readers see when their own language is missing.

Adding a language adds a tab to every article. Removing one asks for confirmation and offers a choice: keep the translations in the database, so the language comes back with everything intact if you add it again, or delete them together with their revisions.

## Reading behaviour

**Missing translation** decides what a reader sees when an article has no text in their language: the default language, or nothing.

## Revisions

With **revisions** on, every saved change to a language is kept and can be restored from the article's **Revisions** tab. **Revisions to keep** caps how many are kept per language; older ones are pruned when a new one is written.
