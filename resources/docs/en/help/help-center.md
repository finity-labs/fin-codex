---
title: The help center
excerpt: Reading help as a full page — the contents tree, search, and the settings behind it.
order: 2
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Pages\HelpCenter
---

The help center is the whole help library as a page of its own: the contents on the left, the article in the middle, its headings on the right. The articles are the ones the drawer holds, with room to read them.

## Reaching it

:::steps
1. Open the user menu at the top right and choose **Help center**.

2. Some panels carry it as a navigation item instead, or as well — then it sits in the sidebar with the other pages.

3. **Open the help center** at the bottom of the help drawer arrives here from wherever you were.
:::

## Contents and search

**Browse help**, on the left, holds both ways of getting to an article. **Contents** lists every article you are allowed to read, grouped by section; a section folds away when you click its heading, and the page remembers what you left open for the next time you come.

Typing into the search field above the tabs moves you to **Search**. The hits stay in the left column, so the article you were reading is still beside them and a wrong guess costs nothing; choosing one opens it in the middle, and the words you typed are still in the field when you go back to them.

## Finding your way in an article

Above the title, the breadcrumbs name the sections the article sits in, and each one opens as a page of its own. Beside it, **On this page** lists the article's own headings and jumps to them; an article with no headings has no such column and the text takes the room instead.

Every article has an address of its own, so a link to the one you are reading can be bookmarked or sent to someone else.

> [!TIP] Reading another panel's help
> If you are allowed to read the help of every panel, a **Panel** selector appears at the top of the left column, above the search. Most readers never see it — without that permission there is nothing it could show.

## For administrators

Where the help center is reachable from is chosen for each panel: the user menu, which is what a new installation does, the navigation, both, or neither. Under neither it is still there — the address still answers and **Open the help center** at the bottom of the drawer still arrives — only the two menu entries are gone.

Articles are scoped to panels. A reader sees the ones written for the panel they are in, plus the ones written for no panel in particular; another panel's articles are hidden here, in the drawer and in the search alike. The permission to read every panel's help lifts that, and it is the same permission that turns the **Panel** selector on.

The page can also be replaced with one of your own that extends it, which is a developer's job. **Declaring help in code** has the rest.
