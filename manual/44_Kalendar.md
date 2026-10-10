# 44. Kalendář

Kalendář v menu **TRIANT → Kalendář** skládá tři vrstvy najednou: služby na
prodejně, expedice a výrobu. Každou vrstvu můžeš nahoře vypnout. Pohled je
**týden** nebo **měsíc** (pondělí až neděle).

Klik do dne otevře okno nové události, klik na existující ji upraví. Událost
můžeš navázat na zakázku — v okně i na kartě je odkaz do detailu.

## 44.1 Tři kalendáře

| Kalendář | K čemu je | Stav |
|---|---|---|
| **Služby na prodejně** | Kdo je na prodejně. Titulek je volný text (jméno), žádná evidence zaměstnanců. Nová služba je celý den | Jen **Plán** |
| **Expedice** | Odvoz / předání zakázky | **Plán** ↔ **Potvrzeno** |
| **Výroba** | Hrubý plán dílny | **Plán** → **Probíhá** → **Hotovo** |

## 44.2 Expedice — plán a potvrzení

Naplánovaná expedice je vizuálně **čárkovaná**. Tlačítkem **Potvrdit** se
změní na plnou (stav `Potvrzeno`). Lze vrátit zpět na plán.

## 44.3 Výroba — průběh

Stanice je u výroby **povinná**. Je to jiná sada než stanoviště na průvodce:

1. Konstrukce
2. Výroba
3. Kompletace
4. Lakovna
5. Expedice (balení)
6. Montáž (doprava)

Postup stavů: **Plán** → tlačítko **Probíhá** → **Hotovo**.

## 44.4 Služby na prodejně

Služba má titulek (kdo) a je celodenní. Stav se nepřepíná — zůstává v plánu.
Není to HR modul ani docházka. Více lidí na jedné službě se nezadává: každý
člověk je samostatná událost.

## 44.5 Zakázka

Na detailu zakázky je mini-sekce **Nadcházející termíny** (expedice + výroba)
s odkazem do kalendáře. Služby na prodejně se tam nezobrazují.

## 44.6 Týden a měsíc

V týdnu je vícedenní událost v pruhu přes dny, kterých se týká. V měsíci jsou
u dne vidět dvě události; další otevřeš tlačítkem **Další**. Novou událost
zakládáš po výběru vrstvy.
