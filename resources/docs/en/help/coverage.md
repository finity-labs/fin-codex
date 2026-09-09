---
title: Help coverage
excerpt: Which screens have a help article, which do not, and how to close a gap.
order: 3
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Pages\HelpCoverage
---

**Help → Help coverage** lists every screen of the application with the article that covers it, one row per screen. The number on its navigation item is how many screens of the current panel have no article yet.

## Reading the table

The table opens on the panel you are in. A row is covered when some article names that screen, either from the editor's **Pages** list or from a declaration in code. The **Article** column links to the article; a grey badge marks a declaration in code, which is not edited here.

## Closing a gap

A row without an article offers two ways to fill it:

- **Write article** opens the editor with the screen already attached.
- **Attach existing** adds the screen to an article you already have.

A row covered by a file that has not been imported yet offers **Import** instead, which brings the file into the database so it can be edited.

## Warnings

The amber box above the table, when it appears, lists what the content sources complained about: a file with invalid front matter, two files claiming one slug, or a declaration in code that names an article that does not exist. The same count sits on the **Help articles** navigation item.
