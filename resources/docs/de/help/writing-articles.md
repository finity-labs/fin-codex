---
title: Hilfeartikel schreiben
excerpt: Artikel im Editor anlegen, übersetzen und Seiten zuordnen.
order: 2
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Resources\ArticleResource
---

Artikel werden unter **Hilfe → Hilfeartikel** geschrieben. Ein Artikel hat einen Slug — seine Adresse und sein Platz im Abschnittsbaum —, einen Text je Sprache und eine Liste der Seiten, zu denen er gehört.

## Der Slug

Der Slug ist ein Pfad: `users` ist ein Artikel auf oberster Ebene, `users/roles` liegt darunter, und `users` wird zu seinem Abschnitt. Der übergeordnete Artikel muss zuerst existieren. Wird ein Abschnitt umbenannt, wandert alles darunter mit.

## Seiten

Die Liste **Seiten** entscheidet, wo der Artikel in der Hilfeleiste erscheint. Wählen Sie ein Panel, dann eine Ressource oder Seite, und der Artikel wird auf diesem Bildschirm angeboten. Eine Zeile kann auch eine Route oder ein URL-Muster nennen. Ein Artikel kann zu mehreren Seiten gehören und eine Seite mehrere Artikel haben; die Reihenfolge der Liste ist die Reihenfolge in der Leiste.

Zeilen mit dem Vermerk **im Code deklariert** wurden von einer Entwicklerin oder einem Entwickler zugeordnet und lassen sich hier nicht ändern.

## Sprachen

Ein Reiter je Sprache. Die Standardsprache ist Pflicht; jede weitere ist optional und trägt das Abzeichen **Fehlt**, bis sie Titel und Text hat. **Aus der Standardsprache übernehmen** beginnt eine Übersetzung mit dem Standardtext.

## Bilder

Ziehen Sie ein Bild in den Editor; es wird hochgeladen und an der Cursorposition eingefügt. Hochgeladene Dateien stehen im Reiter **Medien** des Artikels, der auch sagt, welche Artikel eine Datei noch verwenden.

## Vorschau, HTML und Löschen

**Vorschau** rendert den geöffneten Sprachreiter genau so, wie die Leiste ihn zeigt. Ein als HTML importierter Artikel ist schreibgeschützt, bis **In Markdown umwandeln** ihn neu schreibt; das HTML bleibt als Revision erhalten. **Löschen** listet vor dem Löschen alles auf, was mit dem Artikel geht.

> [!NOTE] Revisionen
> Sind Revisionen in den Einstellungen eingeschaltet, wird jede gespeicherte Änderung einer Sprache im Reiter **Revisionen** aufbewahrt und kann wiederhergestellt werden.
