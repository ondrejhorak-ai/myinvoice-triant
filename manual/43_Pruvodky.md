# 43. Průvodky

Průvodka je papírový / PDF lístek k jedné vyráběné položce zakázky. Na dílně
se na ni ručně píše datum, hodiny a podpis po stanovištích. V aplikaci pak
hodiny **přepíšeš z papíru** na detailu průvodky.

Najdeš je v menu **TRIANT → Průvodky** a také v sekci **Průvodky** na detailu
zakázky.

## 43.1 Kdy vznikají

Tlačítko **Vytvořit průvodky** je na **potvrzené** nebo **dokončené** zakázce,
která má **schválenou variantu** nabídky.

Generují se jen z řádků schválené varianty se zaškrtnutým **Vyrábíme**.
Jeden řádek nabídky = jedna průvodka. Do průvodky se zkopíruje označení,
název, popis, množství a jednotka — **bez ceny**.

Opakované spuštění **doplní chybějící** průvodky (nové vyráběné řádky).
Průvodky, na kterých už jsou zapsané hodiny, se nepřepisují.

> 💡 Řádek bez „Vyrábíme" (např. obchodní položka z ceníku, kterou jen
> prodáváte) průvodku nedostane.

## 43.2 Stanoviště

Každá průvodka má pevných 9 stanovišť, v tomto pořadí:

1. Konstrukce
2. Nářezové centrum
3. CNC
4. Olepovačka
5. Dýhování a broušení
6. Montáž
7. Lakovna
8. Broušení
9. Balení

Hodiny na stanovišti jsou na začátku prázdné. Tuto sadu **neslučuj** se
stanicemi [kalendáře výroby](44_Kalendar.md) — kalendář má hrubší plán
(konstrukce, výroba, kompletace, lakovna, expedice, montáž na stavbě).

## 43.3 Seznam a detail

Seznam filtruje podle zakázky a stavu (`Otevřená` / `Hotovo`). Kliknutím
otevřeš detail: údaje položky, odkaz na zakázku, tabulku stanovišť.

## 43.4 PDF k tisku

Na detailu je **Stáhnout PDF** (jedna A4 na výšku). Na zakázce je **Tisknout
všechny** — jeden PDF soubor se všemi průvodkami zakázky.

PDF obsahuje číslo zakázky, zákazníka, položku (označení, název, popis,
množství, fotku pokud je) a tabulku 9 stanovišť s prázdnými kolonkami
**datum / hodiny / podpis**. Cena na průvodce není.

## 43.5 Zadání hodin

Na detailu vyplníš hodiny po stanovištích (přepis z papíru) a uložíš.
Až je práce hotová, označíš průvodku **Hotovo**. Lze ji znovu otevřít.

Na detailu zakázky je widget **Odpracované hodiny** — součet za zakázku
a rozpad po stanovištích. Podrobnosti k zakázce viz
[§ 14.10](14_Zakazky.md).
