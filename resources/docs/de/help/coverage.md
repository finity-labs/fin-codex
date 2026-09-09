---
title: Hilfeabdeckung
excerpt: Welche Bildschirme einen Hilfeartikel haben, welche nicht, und wie Sie eine Lücke schließen.
order: 3
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Pages\HelpCoverage
---

**Hilfe → Hilfeabdeckung** listet jeden Bildschirm der Anwendung mit dem Artikel, der ihn abdeckt — eine Zeile je Bildschirm. Die Zahl am Navigationseintrag sagt, wie viele Bildschirme des aktuellen Panels noch keinen Artikel haben.

## Die Tabelle lesen

Die Tabelle öffnet sich auf dem Panel, in dem Sie sind. Eine Zeile ist abgedeckt, wenn ein Artikel diesen Bildschirm nennt — aus der Liste **Seiten** im Editor oder aus einer Deklaration im Code. Die Spalte **Artikel** verlinkt den Artikel; ein graues Abzeichen markiert eine Deklaration im Code, die hier nicht bearbeitet wird.

## Eine Lücke schließen

Eine Zeile ohne Artikel bietet zwei Wege:

- **Artikel schreiben** öffnet den Editor mit dem Bildschirm bereits zugeordnet.
- **Bestehenden zuordnen** fügt den Bildschirm einem vorhandenen Artikel hinzu.

Eine Zeile, die eine noch nicht importierte Datei abdeckt, bietet stattdessen **Importieren** an, was die Datei in die Datenbank holt, damit sie bearbeitet werden kann.

## Warnungen

Der gelbe Kasten über der Tabelle listet, wenn er erscheint, was die Inhaltsquellen beanstandet haben: eine Datei mit ungültigem Front Matter, zwei Dateien mit demselben Slug oder eine Deklaration im Code, die einen nicht vorhandenen Artikel nennt. Dieselbe Zahl steht am Navigationseintrag **Hilfeartikel**.
