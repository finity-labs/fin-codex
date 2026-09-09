---
title: Elfelejtett jelszó
excerpt: Visszaállító link kérése és új jelszó választása.
order: 8
visibility: public
contexts:
  - class:Filament\Auth\Pages\PasswordReset\RequestPasswordReset
  - class:Filament\Auth\Pages\PasswordReset\ResetPassword
---

:::steps
1. A bejelentkező oldalon válassza az **Elfelejtette a jelszavát?** hivatkozást, és adja meg a fiókja e-mail-címét.

2. Nyissa meg az érkező üzenetet, és kövesse a benne lévő linket. A link egyszer és csak korlátozott ideig működik.

3. Válasszon új jelszót, ismételje meg, majd nyomja meg a **Jelszó visszaállítása** gombot. Azonnal bejelentkezhet vele.
:::

## Nem érkezett üzenet

Először nézze meg a levélszemét mappát. Az üzenet csak olyan címre megy, amelyhez fiók tartozik, és az oldal mindkét esetben ugyanazt mondja, így a címben lévő elgépelés pontosan úgy néz ki, mint egy hiányzó üzenet. Kérjen új linket, ha az első lejárt.
