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

Detail obsahuje základní údaje, kontakty a adresu realizace, varianty nabídky,
nadcházející termíny, faktury, průvodky a reklamace. Vpravo je komunikace a
historie událostí; na užší obrazovce se přesune pod ostatní obsah.

Kontakty mohou mít více rolí. Zákazník má modrý štítek, architekt fialový.
E-mail a telefon otevírají příslušnou aplikaci. **Mapy.cz** a **Google Maps**
vyhledají adresu realizace; bez adresy nejsou odkazy aktivní.

Stavy zakázky jsou **Aktivní**, **Potvrzená**, **Zamítnutá** a **Dokončená**.
Úpravu základních údajů otevře **Upravit**. Archivace a smazání respektují
oprávnění a vazby stávající aplikace.

## 14.2 Varianty nabídky

Zakázka může mít více variant označených písmeny. Každá má vlastní název,
stav, datum, položky a ceny. Otevři písmeno varianty; **Nová varianta** vytvoří
prázdnou variantu a **Duplikovat** převezme obsah existující varianty.

Položky lze přidávat ručně nebo [z ceníku](42_Ceniky.md). Sekce seskupují
související řádky a mají vlastní slevu. Úchyt vlevo umožňuje přetažení;
klávesy **Alt + šipka nahoru/dolů** na úchytu přesouvají řádek klávesnicí.
Sekce lze sbalit.

Řádek obsahuje označení, název, množství, jednotku, jednotkovou cenu, DPH,
**Vyrábíme** a cenu po položkové slevě. Zadání částky **Celkem** dopočítá
jednotkovou cenu se zachováním množství a slevy. Množství musí být kladné,
cena nezáporná. Při stoprocentní slevě nelze jednotkovou cenu z celku určit.

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
v menu **…**. Nabídka musí být schválená, aby bylo možné vytvořit navazující
zálohovou nebo konečnou fakturu. Účetní evidenci spravuje MyÚčto přes API.


### Použité filtry

Tlačítko **Filtry** ukazuje počet použitých podmínek a při aktivním filtrování
je modře zvýrazněné. Pod hledáním jsou konkrétní podmínky: křížek odstraní
jednu, **Zrušit filtry** všechny. Hledaný text a řazení zůstanou zachované.
Změny v panelu se projeví až po **Použít filtry**; zavření bez použití je zahodí.
Počet nezahrnuje hledání ani řazení, datumový interval se počítá jako jedna
podmínka. Při chybě načtení zůstávají filtry platné; opakujte načtení.

Výchozí podmínka **Archivace: Nearchivované** je také aktivní filtr. Po jejím zrušení se zobrazují i archivované zakázky.
