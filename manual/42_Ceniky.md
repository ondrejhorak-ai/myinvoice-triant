# 42. Ceníky a katalog položek

V menu **Ceníky** jsou dvě záložky: **Ceníky** a **Katalog položek**. Katalog
obsahuje výrobky a služby, jejich popisy, jednotky, obrázky, kategorie a štítky.
Cena patří konkrétnímu vydání ceníku. Každý ceník používá jednu měnu a ceny bez DPH.
Data jsou oddělená podle dodavatele.

## 42.1 Katalog položek

**Nová položka** potřebuje jedinečný kód, název a jednotku. Dále lze vyplnit
výchozí množství, sazbu DPH, popis, parametry, kategorii, štítky oddělené čárkami
a obrázek. Obrázky JPG, PNG, WebP a GIF mohou mít nejvýše 10 MiB; aplikace je
optimalizuje. Uložení položky ještě nezakládá žádnou cenu.

Kliknutí na název otevře detail s cenami a historií jednotlivých vydání.
Kód a jednotku položky použité ve vydání nelze přepsat; pro odlišný výrobek
založ samostatnou položku. Úprava popisu neovlivní historické nabídky ani
publikovaná vydání. Nový koncept převezme aktuální katalogové popisy.

## 42.2 Seznam a nový ceník

Seznam ukazuje měnu, počet položek, aktuální vydání, platnost a další vydání.
Vyhledávej podle názvu; **Filtry** nabízejí měnu, stav a archivované ceníky.
**Další** zobrazí další výsledky. Archivace v menu řádku zachová historii.

**Nový ceník** potřebuje název a měnu; poznámka je volitelná. Vznikne prázdný
koncept, do kterého vložíš položky přes **Přidat z katalogu** a doplníš ceny.
Nulová cena je povolená. Prázdná cena znamená nedoplněnou cenu a blokuje publikaci.

Původní ceníky se při otevření nemění. **Upravit ceník** vytvoří první koncept
a převezme původní položky do katalogu jako samostatné záznamy se suffixem
`~L…`. Stejné původní označení se automaticky neslučuje. Původní ceníkové řádky
a ceny již vytvořených nabídek zůstávají zachované.

## 42.3 Koncept a publikování

Detail přednostně ukazuje právě platné vydání. **Upravit ceník** vytvoří koncept
z posledního publikovaného vydání nebo otevře existující koncept. Opakované
kliknutí nezakládá další kopie. **Historie vydání** otevře libovolné vydání;
publikovaná data jsou pouze ke čtení.

**Uložit koncept** neovlivňuje ceny používané v nabídkách. Odchod s neuloženými
změnami nabízí **Uložit**, **Zahodit změny** a **Pokračovat v úpravách**.
Pokud mezitím koncept změnil jiný uživatel, aplikace odmítne přepsání.

**Publikovat vydání** vyžaduje datum začátku platnosti a všechny ceny. Konec
platnosti je volitelný. Začátek nemůže být v minulosti. Nové vydání ukončí
předchozí den před svým začátkem; kolize s publikovaným nebo naplánovaným
vydáním publikaci zablokuje. Vydání od budoucího data je naplánované.

**Cena změněna** vyjadřuje skutečnou změnu částky. Prosté převzetí ceny do dalšího
vydání ani změna popisu datum neposouvá. **PDF** exportuje právě otevřené vydání;
před exportem koncept ulož.

## 42.4 Hromadné změny a import

Vyber položky zaškrtávátky a použij **Hromadná změna cen**. Zadej procentní změnu
(záporná hodnota znamená snížení), zkontroluj původní a nové ceny a potvrď
**Použít v konceptu**. Změny následně ulož. Prázdné ceny zůstávají prázdné.

**Import XLS/XLSX** načítá první list souboru do 5 MB, nejvýše 10 000 řádků.
První řádek má přesně sloupce `code`, `unit_price`, `unit`, `currency` v tomto
pořadí. Ceny musí být číselné hodnoty, nikoli vzorce. Náhled upozorní na
neznámé nebo duplicitní kódy, chybějící cenu a neshodnou měnu či jednotku.
Dokud má některý řádek chybu, nelze import použít. Import nezakládá katalogové
položky automaticky a mění pouze koncept.

## 42.5 Nabídky a faktury

**Vložit z ceníku** vybírá publikované vydání platné k zadanému datu. Nabídky
používají CZK; faktury nabízejí ceníky v měně dokladu. Položka se zkopíruje se
svým názvem, popisem, jednotkou, množstvím, cenou a sazbou DPH.

Nabídka uchovává zdrojové vydání i datum cen. Další vložené položky používají
stejné vydání. Pozdější změny ceníku nabídku samy nepřecení. **Přecenit nabídku**
zobrazí rozdíly pro nové datum a vyžaduje potvrzení; chybějící položka nebo
neshodná jednotka operaci zastaví. Slevy, množství a ruční řádky se zachovají.
Přeceněnou nabídku je ještě potřeba uložit.

Kopie varianty zachová původní vydání i snímky položek. Faktura má samostatný
snímek položek a její finanční evidenci spravuje MyÚčto.


### Použité filtry

Tlačítko **Filtry** ukazuje počet použitých podmínek a při aktivním filtrování
je modře zvýrazněné. Pod hledáním jsou konkrétní podmínky: křížek odstraní
jednu, **Zrušit filtry** všechny. Hledaný text a řazení zůstanou zachované.
Změny v panelu se projeví až po **Použít filtry**; zavření bez použití je zahodí.
Počet nezahrnuje hledání ani řazení, datumový interval se počítá jako jedna
podmínka. Při chybě načtení zůstávají filtry platné; opakujte načtení.

Způsob ovládání je společný seznamu ceníků, katalogu i položkám vydání. Výchozí seznam ceníků omezuje archivace na nearchivované; po zrušení tohoto filtru zahrnuje i archivované ceníky.
