# 44. Kalendář

Kalendář v menu **TRIANT → Kalendář** má tři samostatné pohledy. Přepínáš je
nahoře; u každého vidíš **měsíc** nebo **týden** (pondělí až neděle).

Klik do dne otevře okno nové události, klik na existující ji upraví. Událost
můžeš navázat na zakázku — v okně i na kartě je odkaz do detailu.

## 44.1 Tři kalendáře

| Kalendář | K čemu je | Stav |
|---|---|---|
| **Směny** | Kdo je v práci, od–do. Titulek je volný text (jméno), žádná evidence zaměstnanců | Jen **Plán** |
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

## 44.4 Směny

Směna má titulek (kdo) a interval od–do, volitelně celý den. Stav se
nepřepíná — zůstává v plánu. Není to HR modul ani docházka.

## 44.5 Zakázka

Na detailu zakázky je mini-sekce **Nadcházející termíny** (expedice + výroba)
s odkazem do kalendáře. Směny se tam nezobrazují.
