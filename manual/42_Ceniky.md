# 42. Ceníky

Ceník je katalog položek dodavatele (desky, kování, služby), ze kterého
vkládáš řádky do **cenové nabídky** i do **faktury**. Najdeš ho v menu
**TRIANT → Ceníky**.

Položka se do dokladu **zkopíruje** (snapshot). Pozdější úprava ceny v ceníku
už vystavenou nabídku ani fakturu nemění — na dokladu můžeš částku i popis
libovolně přepsat.

> 💡 Ceníky jsou per dodavatel. Položky jednoho ceníku neuvidíš pod jinou
> firmou.

## 42.1 Seznam ceníků

Tabulka ukazuje název, dodavatele a počet položek. Kliknutím otevřeš editor.
Prázdný seznam má tlačítko **Nový ceník**.

## 42.2 Editor ceníku

| Pole | Význam |
|---|---|
| Název | Povinný — např. „Kování 2026", „Desky Egger" |
| Poznámka | Interní text, na PDF se neobjeví |
| Položky | Tabulka řádků (pořadí, fotka, označení, název, popis, množství, jednotka, cena, DPH) |

Každá položka má:

| Pole | Význam |
|---|---|
| Fotka | Stejná pravidla jako u nabídky: JPG / PNG / WebP / GIF, max 10 MiB, systém zmenší na JPEG |
| Označení | Krátký kód (např. `D-18-W980`) |
| Název | Povinný název položky |
| Popis | Delší text, kopíruje se do nabídky |
| Množství | Výchozí množství při vložení do dokladu |
| Jednotka | Výchozí `ks` |
| Cena bez DPH | Základní jednotková cena |
| DPH | Výchozí 21 % |
| Cena aktualizována | Datum se nastaví **jen při změně ceny**, ne při každém uložení |

Položky ukládáš spolu s ceníkem. Pořadí měníš přetažením.

## 42.3 PDF ceníku

V editoru je tlačítko **PDF**. Vygeneruje přehled s hlavičkou dodavatele
(jako na nabídce), fotkami, cenami, jednotkami a souhrnem DPH.

## 42.4 Vložení do nabídky

V editoru varianty zakázky je akce **Vložit z ceníku**. Otevře se vyhledávání
napříč ceníky, vybereš jednu nebo více položek a vloží se jako nové řádky
nabídky včetně fotky. Na řádku zůstane volitelný odkaz na položku ceníku,
samotné údaje na dokladu už žijí samostatně.

Nový řádek z ceníku má ve výchozím stavu zaškrtnuté **Vyrábíme** — z takových
řádků se později generují [průvodky](43_Pruvodky.md).

## 42.5 Vložení do faktury

V editoru **Faktury TRI** je stejná akce **Vložit z ceníku**. Snapshot se
zapíše do běžných položek faktury:

- popis = označení + název + popis z ceníku,
- množství a jednotka z výchozích hodnot položky,
- cena bez DPH a sazba DPH (mapuje se na sazbu v číselníku).

Řádek je po vložení běžně editovatelný. Jádro faktur (DPH, KH, banka) se
nemění — ceník jen předvyplní položky.
