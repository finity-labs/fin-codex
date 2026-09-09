---
title: Hilfe im Code deklarieren
excerpt: Für Entwicklerinnen und Entwickler — Artikel ohne Editor an Ressourcen, Seiten und Felder hängen.
order: 5
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Resources\ArticleResource
---

Artikel lassen sich nicht nur im Editor, sondern auch im Code an Bildschirme hängen — dann bleibt die Zuordnung bei der Klasse, die sie beschreibt.

## Ressourcen und Seiten

Implementieren Sie `FinityLabs\FinCodex\Help\HasHelp` auf einer Filament-Ressource, einer Ressourcenseite oder einer eigenen Seite und beantworten Sie es mit dem Trait `WithHelp` aus einer Eigenschaft:

```php
class UserResource extends Resource implements HasHelp
{
    use WithHelp;

    protected static array $helpArticles = ['users', 'users/roles'];
}
```

Eine Deklaration auf der Ressource deckt ihre Listen-, Anlage- und Bearbeitungsseite ab. Die Eigenschaft kann auch eine nach Panel-ID geschlüsselte Map sein, mit `'*'` als Standard. Deklarierte Artikel stehen in der Leiste vorn, in der geschriebenen Reihenfolge, zählen zum Abzeichen und zur Abdeckung und erscheinen im Editor als **im Code deklariert**.

## Feldhinweise

Ein Formularfeld kann einen Hilfehinweis tragen, der die Leiste auf einer Überschrift öffnet:

```php
TextInput::make('role')->codexHelp('users/roles', 'assigning-roles');
```

Der Hinweis erscheint als Fragezeichen-Symbol neben dem Feld mit dem Artikeltitel als Tooltip — und nur, wenn die angemeldete Person den Artikel lesen darf.

## Dateien

Artikel können auch als Markdown-Dateien in einem Docs-Ordner liegen, den der Kern liest, mit Front Matter für Titel, Kontexte und Sichtbarkeit. `php artisan codex:make` legt eine an, und der Reiter **Aus Dateien** der Artikelliste importiert eine Datei in die Datenbank, wenn sie im Panel bearbeitet werden soll.
