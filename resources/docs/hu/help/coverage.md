---
title: Súgólefedettség
excerpt: Mely képernyőknek van súgócikkük, melyeknek nincs, és hogyan zárható be egy hiány.
order: 3
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Pages\HelpCoverage
---

A **Súgó → Súgólefedettség** az alkalmazás minden képernyőjét felsorolja az azt lefedő cikkel, képernyőnként egy sorban. A navigációs elemen lévő szám azt mutatja, az aktuális panel hány képernyőjének nincs még cikke.

## A táblázat olvasása

A táblázat azon a panelen nyílik, amelyen áll. Egy sor akkor lefedett, ha valamelyik cikk megnevezi azt a képernyőt — a szerkesztő **Oldalak** listájából vagy egy kódban tett deklarációból. A **Cikk** oszlop a cikkre hivatkozik; a szürke jelvény kódban tett deklarációt jelöl, amelyet itt nem lehet szerkeszteni.

## Egy hiány bezárása

A cikk nélküli sor két lehetőséget kínál:

- A **Cikk írása** a szerkesztőt nyitja meg a képernyővel már hozzárendelve.
- A **Meglévő hozzárendelése** a képernyőt egy már meglévő cikkhez adja.

Az a sor, amelyet egy még nem importált fájl fed le, ehelyett **Importálás**t kínál, amely a fájlt az adatbázisba hozza, hogy szerkeszthető legyen.

## Figyelmeztetések

A táblázat feletti borostyán doboz — ha megjelenik — azt sorolja, amit a tartalomforrások kifogásoltak: érvénytelen front matterű fájl, két fájl ugyanazzal a sluggal, vagy kódban tett deklaráció, amely nem létező cikket nevez meg. Ugyanez a szám a **Súgócikkek** navigációs elemen is látszik.
