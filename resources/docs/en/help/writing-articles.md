---
title: Writing help articles
excerpt: Creating, translating and attaching articles to pages from the editor.
order: 2
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Resources\ArticleResource
---

Articles are written under **Help → Help articles**. An article has a slug, which is its address and its place in the section tree, one text per language, and a list of the pages it belongs to.

## The slug

The slug is a path: `users` is a top-level article, `users/roles` sits under it and `users` becomes its section. The parent has to exist first. Renaming a section renames everything beneath it.

## Pages

The **Pages** list is what makes an article appear in the drawer. Pick a panel, then a resource or page, and the article is offered on that screen. A row can also name a route or a URL pattern. An article can belong to several pages, and a page can have several articles; the order in the list is the order the drawer shows them in.

Rows that say **declared in code** were attached by a developer and cannot be changed here.

## Languages

One tab per language. The default language is required; every other language is optional and shows a **Missing** badge until it has a title and a body. **Copy from default language** starts a translation from the default text.

## Images

Drop an image into the editor and it is uploaded and inserted where the cursor is. Uploaded files are listed on the **Media** tab of the article, which also says which articles still use each file.

## Preview, HTML and deleting

**Preview** renders the language tab you are on exactly as the drawer will show it. An article imported as HTML is read-only until **Convert to Markdown** rewrites it; the HTML is kept as a revision. **Delete** lists everything that goes with the article before it goes.

> [!NOTE] Revisions
> With revisions switched on in the settings, every saved change to a language is kept on the **Revisions** tab and can be restored.
