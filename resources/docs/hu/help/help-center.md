---
title: A súgóközpont
excerpt: A súgó olvasása teljes oldalként — a tartalomfa, a keresés és a mögöttük álló beállítások.
order: 2
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Pages\HelpCenter
---

A súgóközpont a teljes súgó saját oldalként: balra a tartalom, középen a cikk, jobbra a címsorai. Ugyanazok a cikkek, mint a súgópanelben, csak van hely elolvasni őket.

## Hogyan jut el ide

:::steps
1. Nyissa meg a jobb felső sarokban a felhasználói menüt, és válassza a **Súgóközpont** elemet.

2. Egyes panelek ehelyett — vagy emellett — navigációs elemként is felveszik; ilyenkor az oldalsávban áll a többi oldal mellett.

3. A súgópanel alján lévő **Súgóközpont megnyitása** bárhonnan idehozza.
:::

## Tartalom és keresés

A bal oldali **Súgó böngészése** mindkét utat tartalmazza egy cikkhez. A **Tartalom** minden cikket felsorol, amelyet elolvashat, szakaszok szerint csoportosítva; egy szakasz a címsorára kattintva összecsukódik, és az oldal a következő alkalomra megjegyzi, mit hagyott nyitva.

Amint a fülek fölötti keresőmezőbe gépel, az oldal a **Keresés** fülre vált. A találatok a bal oldali oszlopban maradnak, így az éppen olvasott cikk ott marad mellettük, és egy rossz tipp semmibe sem kerül; a kiválasztott találat középen nyílik meg, a beírt szavak pedig még a mezőben állnak, amikor visszatér hozzájuk.

## Tájékozódás a cikken belül

A cím fölött a morzsamenü megnevezi azokat a szakaszokat, amelyekben a cikk áll; mindegyik saját oldalként nyílik meg. Mellette az **Ezen az oldalon** felsorolja a cikk címsorait, és azokra ugrik; a címsor nélküli cikknek nincs ilyen oszlopa, és a szöveg kapja meg a helyet.

Minden cikknek saját webcíme van, így az éppen olvasott cikkre mutató hivatkozás könyvjelzőnek elmenthető vagy elküldhető valakinek.

> [!TIP] Más panelek súgójának olvasása
> Ha minden panel súgóját elolvashatja, a bal oldali oszlop tetején, a kereső fölött megjelenik egy **Panel** választó. A legtöbb olvasó sosem látja — e jogosultság nélkül nem is lenne mit mutatnia.

## Rendszergazdáknak

Azt, hogy honnan érhető el a súgóközpont, panelenként lehet megadni: a felhasználói menüből — így áll be egy új telepítés —, a navigációból, mindkettőből vagy egyikből sem. Az utóbbi esetben is megmarad: a webcím továbbra is válaszol, és a súgópanel alján lévő **Súgóközpont megnyitása** is idehoz; csak a két menüelem tűnik el.

A cikkek panelekhez kötődnek. Az olvasó a saját paneljéhez írt cikkeket látja, valamint azokat, amelyek egyetlen panelhez sem tartoznak; egy másik panel cikkei itt, a súgópanelben és a keresésben egyaránt rejtve maradnak. Ezt az a jogosultság oldja fel, amely minden panel súgójának olvasását engedi — és ugyanez kapcsolja be a **Panel** választót.

Az oldal ezen felül lecserélhető egy sajátra, amely kiterjeszti; ez fejlesztői feladat. A **Súgó deklarálása kódban** tartalmazza a többit.
