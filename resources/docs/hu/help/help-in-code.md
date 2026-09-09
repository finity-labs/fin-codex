---
title: Súgó deklarálása kódban
excerpt: Fejlesztőknek — cikkek hozzárendelése erőforrásokhoz, oldalakhoz és mezőkhöz a szerkesztő nélkül.
order: 5
visibility: authenticated
contexts:
  - class:FinityLabs\FinCodex\Resources\ArticleResource
---

A cikkek nemcsak a szerkesztőből, hanem kódból is hozzárendelhetők képernyőkhöz, így a hozzárendelés annál az osztálynál marad, amelyet leír.

## Erőforrások és oldalak

Valósítsa meg a `FinityLabs\FinCodex\Help\HasHelp` interfészt egy Filament erőforráson, erőforrásoldalon vagy egyéni oldalon, és a `WithHelp` trait segítségével válaszoljon rá egy tulajdonságból:

```php
class UserResource extends Resource implements HasHelp
{
    use WithHelp;

    protected static array $helpArticles = ['users', 'users/roles'];
}
```

Az erőforrás szintű deklaráció lefedi a lista-, létrehozó- és szerkesztőoldalát. A tulajdonság lehet panelazonosítóval kulcsolt térkép is, `'*'` alapértelmezéssel. A deklarált cikkek a panelben elöl állnak a megírt sorrendben, beleszámítanak a jelvénybe és a lefedettségbe, és a szerkesztőben **kódban deklarált** jelöléssel jelennek meg.

## Mezőtippek

Egy űrlapmező súgótippet viselhet, amely a panelt egy címsoron nyitja meg:

```php
TextInput::make('role')->codexHelp('users/roles', 'assigning-roles');
```

A tipp kérdőjel ikonként jelenik meg a mező mellett, a cikk címével mint eszköztippel — és csak akkor, ha a bejelentkezett felhasználó elolvashatja a cikket.

## Fájlok

A cikkek Markdown-fájlként is élhetnek egy docs mappában, amelyet a mag olvas, front matterrel a címhez, kontextusokhoz és láthatósághoz. A `php artisan codex:make` létrehoz egyet, a cikklista **Fájlokból** füle pedig importál egy fájlt az adatbázisba, ha a panelben kell szerkeszteni.
