# 14. Zakázky a nabídky TRIANT

Zakázky otevřete v hlavním menu. Nabídky a jejich varianty najdete v detailu
konkrétní zakázky; samostatná položka Nabídky v menu není. Tlačítko **+** vedle
Zakázek založí novou zakázku. Průvodky, Kalendář a Reklamace jsou ve skupině
**Další agendy**, administrace ve skupině **Systém** podle oprávnění.


**Zakázky** ukazují číslo, název, zákazníka, autora a stav. Vyhledávání hledá
číslo, název i zákazníka; stav lze filtrovat. **Další** přidá další výsledky.
**Přehled** shrnuje počty zakázek a naposledy upravené záznamy. **Nabídky**
ukazují varianty napříč zakázkami a odkazují přímo do jejich editoru.

## 14.1 Detail zakázky

V hlavičce je **Zakázka** a její číslo, pod tím název zakázky. Drobeček nese číslo i název. Výběr stavu a **Upravit zakázku** jsou stejně vysoké a sedí na jedné lince; popisek **Stav** je jen nad výběrem. Stavy zůstávají
**Aktivní**, **Potvrzená**, **Zamítnutá** a **Dokončená**. Detail obsahuje základní údaje,
kontakty jako karty se štítky kontaktu, adresu realizace, varianty nabídky jako karty s cenou a stavem,
nadcházející termíny, faktury, průvodky a reklamace. Vpravo je komunikace: bubliny zpráv
a pole pro novou zprávu. Na užší obrazovce se přesune pod ostatní obsah.

Na zakázce se vybírá existující kontakt. Nová osoba se zakládá jako kontakt,
ne v políčku na zakázce. Štítky (zákazník, architekt a další) se upravují na
kontaktu. Vlastníci se přidávají výběrem; vybrané osoby jsou štítky pod ním,
takže se výběr neposouvá. Odběratel faktury se na zakázce nevyplňuje. Potvrzení zakázky
projekt v MyÚčtu nezakládá. Projekt vznikne s prvním dokladem.
E-mail a telefon otevírají příslušnou aplikaci. **Mapy.cz** a **Google Maps**
vyhledají adresu realizace; bez adresy nejsou odkazy aktivní.

Úpravu základních údajů otevře **Upravit zakázku**. Archivace a smazání respektují
oprávnění a vazby stávající aplikace.

## 14.2 Varianty nabídky

Karta varianty, faktury i průvodky má odstup od okraje sekce, takže rámeček karty nesedí na dělicí čáře. V editoru varianty drobeček i podtitul nesou číslo a název zakázky (`Zakázka 260006 · Kuchyň na míru`).

Zakázka může mít více variant označených písmeny. Každá má vlastní název,
stav, datum, položky a ceny. Otevři písmeno varianty; **Nová varianta** otevře
editor s jedním prázdným řádkem a **Duplikovat** převezme obsah existující varianty.

Položky lze přidávat ručně nebo [z ceníku](42_Ceniky.md). Sekce seskupují
související řádky a mají vlastní slevu. Úchyt vlevo umožňuje přetažení;
klávesy **Alt + šipka nahoru/dolů** na úchytu přesouvají řádek klávesnicí.
Sekce lze sbalit.

Řádek obsahuje označení, název, množství, jednotku, jednotkovou cenu, DPH,
**Vyrábíme** a cenu po položkové slevě. Zadání částky **Celkem** dopočítá
jednotkovou cenu se zachováním množství a slevy. Množství je celé kladné číslo
(krok 1). Tečka a čárka se do pole nezapíšou, zůstanou jen číslice. Cena je nezáporná a bez šipek. Dlouhý název se zalamuje a řádek povyroste.
Sleva položky jde po celých procentech a nerozbije sloupec DPH. Při stoprocentní slevě nelze jednotkovou cenu z celku určit.

Menu **…** na pravém okraji nabízí slevu položky, vytvoření průvodky, obrázek
a smazání. Menu sekce nabízí slevu a smazání sekce. Smazání vyžaduje potvrzení;
**Vrátit zpět** obnoví poslední smazaný obsah před uložením.

Obrázek může být JPG, PNG, WebP nebo GIF do 10 MiB. Aplikace jej optimalizuje;
přiřazení obrázku se uloží spolu s nabídkou. Na PDF se zachová poměr stran.

Pod položkami je sleva celé nabídky, souhrn a poznámka pod položkami. **Uložit**
zapíše změny a server znovu spočítá ceny. Starší interní hodnoty nabídky se
při běžné úpravě zachovávají, i když pro ně editor nenabízí ovládací prvek.

## 14.3 Výroba, termíny a faktury

**Vyrábíme** určuje položky pro [průvodky](43_Pruvodky.md). Na potvrzené nebo
dokončené zakázce se schválenou variantou lze vytvořit všechny průvodky,
nebo v menu uložené výrobní položky vytvořit jen její průvodku. Cena se na
průvodku nekopíruje. Hodiny se zapisují na detailu průvodky.

Nadcházející expedice a výroba odkazují do [kalendáře](44_Kalendar.md).
[Reklamace](45_Reklamace.md) mají samostatnou agendu i vazbu na zakázku.

Sekce faktur ukazuje částky a doklady navázané přes MyÚčto. Další akce jsou
v menu **…**. Nabídka musí být schválená, aby šlo vytvořit zálohovou nebo
konečnou fakturu. Když tlačítko **Vystavit zálohovou fakturu** nejde stisknout,
vedle něj je důvod (varianta není schválená). Zakázka, která už odběratele má,
založí doklad rovnou. Bez odběratele se otevře editor faktury a v seznamu
klientů jsou nejdřív kontakty z této zakázky. Klient zvolený na faktuře
stačí k navázání dokladu na zakázku. Do MyÚčta se odešle při vystavení,
pokud tam ještě není. **Cena varianty** bere schválenou variantu; dokud žádná
schválená není, ukáže nejdražší rozpracovanou. Účetní evidenci spravuje MyÚčto
přes API.

Novou osobu jde přidat z karty firmy. Na zakázce se jen vybírá existující kontakt.


### Použité filtry

Tlačítko **Filtry** ukazuje počet použitých podmínek a při aktivním filtrování
je modře zvýrazněné. Pod hledáním jsou konkrétní podmínky: křížek odstraní
jednu, **Zrušit filtry** všechny. Hledaný text a řazení zůstanou zachované.
Změny v panelu se projeví až po **Použít filtry**; zavření bez použití je zahodí.
Počet nezahrnuje hledání ani řazení, datumový interval se počítá jako jedna
podmínka. Při chybě načtení zůstávají filtry platné; opakujte načtení.

Výchozí podmínka **Archivace: Nearchivované** je také aktivní filtr. Po jejím zrušení se zobrazují i archivované zakázky.
