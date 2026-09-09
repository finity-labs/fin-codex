---
title: Declaring help in code
excerpt: For developers — attaching articles to resources, pages and fields without the editor.
order: 5
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Resources\ArticleResource
---

Articles can be attached to screens from code as well as from the editor, which keeps the attachment with the class it describes.

## Resources and pages

Implement `FinityLabs\FinCodex\Help\HasHelp` on a Filament resource, resource page or custom page and use the `WithHelp` trait to answer it from a property:

```php
class UserResource extends Resource implements HasHelp
{
    use WithHelp;

    protected static array $helpArticles = ['users', 'users/roles'];
}
```

A resource-level declaration covers its list, create and edit pages. The property can also be a map keyed by panel id, with `'*'` as the default. Declared articles lead the drawer in the order written, count towards the badge and towards coverage, and show up in the editor as **declared in code**.

## Field hints

A form field can carry a help hint that opens the drawer on a heading:

```php
TextInput::make('role')->codexHelp('users/roles', 'assigning-roles');
```

The hint renders as a question-mark icon beside the field, with the article's title as its tooltip, and only when the current user may read the article.

## Files

Articles can also live as Markdown files in a docs folder that the core reads, with front matter for the title, contexts and visibility. `php artisan codex:make` scaffolds one, and the **From files** tab of the article list imports a file into the database when it needs editing in the panel.
