---
title: Súgócikkek írása
excerpt: Cikkek létrehozása, fordítása és oldalakhoz rendelése a szerkesztőből.
order: 2
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Resources\ArticleResource
---

A cikkeket a **Súgó → Súgócikkek** alatt írja. Egy cikknek van egy slugja — ez a címe és a helye a szakaszfában —, nyelvenként egy szövege, és egy listája azokról az oldalakról, amelyekhez tartozik.

## A slug

A slug egy útvonal: a `users` legfelső szintű cikk, a `users/roles` alatta van, és a `users` lesz a szakasza. A szülőnek előbb léteznie kell. Egy szakasz átnevezése mindent átnevez alatta.

## Oldalak

Az **Oldalak** lista dönti el, hol jelenik meg a cikk a súgópanelben. Válasszon egy panelt, majd egy erőforrást vagy oldalt, és a cikk azon a képernyőn lesz felkínálva. Egy sor útvonalat vagy URL-mintát is megnevezhet. Egy cikk több oldalhoz tartozhat, és egy oldalnak több cikke lehet; a lista sorrendje a panel sorrendje.

A **kódban deklarált** jelölésű sorokat fejlesztő rendelte hozzá, itt nem módosíthatók.

## Nyelvek

Nyelvenként egy fül. Az alapértelmezett nyelv kötelező; minden más nyelv opcionális, és **Hiányzik** jelvényt visel, amíg nincs címe és szövege. Az **Átvétel az alapértelmezett nyelvből** az alapértelmezett szövegből indítja a fordítást.

## Képek

Húzzon egy képet a szerkesztőbe: feltöltődik, és a kurzor helyére kerül. A feltöltött fájlok a cikk **Média** fülén láthatók, amely azt is megmondja, mely cikkek használják még az egyes fájlokat.

## Előnézet, HTML és törlés

Az **Előnézet** pontosan úgy jeleníti meg a megnyitott nyelvi fület, ahogy a panel mutatja majd. A HTML-ként importált cikk csak olvasható, amíg az **Átalakítás Markdownra** újra nem írja; a HTML revízióként megmarad. A **Törlés** a törlés előtt felsorol mindent, ami a cikkel együtt megy.

> [!NOTE] Revíziók
> Ha a beállításokban be vannak kapcsolva a revíziók, egy nyelv minden mentett módosítása megmarad a **Revíziók** fülön, és visszaállítható.
