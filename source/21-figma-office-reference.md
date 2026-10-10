# Figma — implementační podklady TRIANT Office

Zdroj: https://www.figma.com/design/nNhjXHJme5zPppfPtNoI6I (načteno 2026-09-21).

Tento dokument zachycuje pravidla návrhu; stav implementace a ověření je v `21-figma-office-handoff.md`. Demo data nejsou součástí implementace.

## Getting Started

- Getting Started: `3:24`
- Implementation Guide / 01 · Platné obrazovky a pořadí čtení: `241:4`
- Implementation Guide / 02 · Sdílená struktura a typografie: `241:7`
- Implementation Guide / 03 · Kontakty, adresa a navigace: `241:10`
- Implementation Guide / 04 · Nabídka, sekce a položky: `241:13`
- Implementation Guide / 05 · Stavy řádku, výpočty a interakce: `241:16`
- Implementation Guide / 06 · Rozsah a kontrola implementace: `241:19`
- 07 / Invoice templates: `262:7`
- 08 / Shared editable item row: `274:4`
- 09 / Contacts templates: `286:4`
- Ceníky a společný katalog · návrh domény: `300:3154`

TRIANT Office · implementační reference. Průvodce vpravo určuje platné obrazovky, sdílené vzory, stavy a rozsah zadání pro AI agenty.

• Používat sémantické tokeny, ne přímé hex hodnoty.
• Zachovat názvy komponent a variant z knihovny.
• Desktop reference: 1440 px, sidebar 248 px, obsah max. 1200 px.
• Stavové barvy vždy kombinovat s textovým štítkem.
• Šablony Jobs jsou první referenční tok; další moduly na ně navážou.

Začněte zde → Foundations → Components → hlavní šablona → doplňkové stavy.

Jobs List · 14:102
/tri/jobs — seznam zakázek, filtry, tabulka.

Job Detail · 136:111
/tri/jobs/:id — přehled zakázky, kontakty, varianty, komunikace.

Job Variant Detail · 170:117
Editor nabídky. Přesnou routu a datové ID ověřit v aplikaci; číslo 260002 je demonstrační číslo zakázky.

Rámy / Canonical jsou hlavní reference. Detaily na jejich listu doplňují stavy stejné komponenty; nejsou další alternativou stránky. Dokument neobsahuje samostatný mobilní návrh.

Desktop 1440 px; sidebar 248 px; obsah 1192 px. V šablonách je obsah odsazen 40 px zleva a zprava, 32 px shora; mezi oblastmi 24 px.

Breadcrumb → 24 px → nadpis → 8 px → podnadpis.
Nadpis 28/36 SemiBold; podnadpis 14/20 SemiBold. Nadpis karty 16/24 SemiBold. Základní pole 14/20; kompaktní text 13/19; popisky 12/18; štítky 11/16.

Používat Office text styles a sémantické proměnné. Web/* styly jsou mimo Office kontrakt. Ostré rohy UI: 0 px; stavové, kontaktní a slevové štítky: 2 px.

Field 44 px; tlačítka 36/40/44 px dle kontextu. Primární akce je dominantní v rámci oblasti, ne nutně jediná na celé stránce.

ContactsAddress je společný implementační vzor pro Job Detail (136:138) i variantu (234:666). Stejný obsahový strom, typografie a barvy; mění se šířka a data.

Vnější karta: padding 20 px. Dva pružné sloupce se stejnou šířkou, mezera 24 px. Kontaktní karta: padding 16 px; jméno + role vedle sebe, e-mail a telefon na dalších řádcích. Zákazník modrý, Architekt fialový; role vždy i textem. Barvy: color/role/*.

Adresa: pod textem Mapy.cz a Google Maps s vektorovou ikonou externího odkazu. Vzhled je stejný v obou šablonách. URL generovat z adresy dané zakázky, nikdy nekopírovat demonstrační URL.

Bez adresy: Adresa nezadána; stejné umístění mapových prvků, ale bez navigace. V implementaci poskytnout vysvětlení chybějící adresy a aria-disabled.

Breadcrumb: předchozí úrovně odkazy, aktuální úroveň text.

170:117 je editor: 2 ukázkové sekce a 3 samostatné položky. Každá sekce je vlastní tabulka s opakovaným záhlavím; odstup tabulek 20 px. Samostatné položky jsou samostatná skupina bez ovládání sekce.

Částky položek pouze bez DPH. Žádný přepínač s/bez DPH, žádná provize, žádná poznámka nad položkami.

Úchyt vlevo; menu … vždy vpravo. Menu položky: Sleva položky, Vytvořit průvodku, Vložit obrázek, Smazat položku. Menu sekce: Sleva sekce, Smazat sekci. Posun/přesun není položkou menu. Přesun úchytem zůstává.

Přidání: Přidat položku → Vložit z ceníku → Přidat sekci. Bez znaku +. Ve vlastní sekci tlačítko přidává do dané sekce.

Pod tabulkami sleva na celou zakázku a souhrn vedle sebe; pod nimi poznámka přes celou šířku. Lišta Zpět na zakázku / Uložit bez poučení o ukládání.

Obrázek · 225:480
Tabulka se 3 řádky, prostřední má náhled pod názvem. Číselné sloupce se neposouvají. Náhled je ilustrační; použít skutečnou přílohu. Zvětšení obrázku a jeho změna/odebrání patří k dané položce.

Sleva · 228:573
Prostřední řádek: Sleva 10 % · −450 Kč, původně 4 500 Kč, po slevě 4 050 Kč. Cena/j. je původní, Celkem po položkové slevě. Součet sekce je před celkovou slevou nabídky.

Obchodní pravidla zůstávají v backendu: kombinaci slev položky/sekce/zakázky, zaokrouhlení a sazby DPH ověřit v existující aplikaci. Ze statické ukázky neodvozovat nový algoritmus.

Menu zavírá Escape/kliknutí mimo a vrací fokus na spouštěč. Pole mají popisky, ikony přístupné názvy, chyby i text. Přesun musí být dostupný klávesnicí. Prototyp menu/slevového dialogu je demonstrace, ne kompletní funkčnost.

Ukázková jména, role, kontakty, adresy, ceny a obrázky nejsou produkční data. Job Detail a editor varianty ukazují odlišné demo zakázky.

Zachovat lokální komponenty: Button, Field, Status Badge, Data Table Row, Sidebar, Brand / TRIANT Logo, Breadcrumb. ContactsAddress a nabídka jsou sdílené implementační vzory složené z rámů; nejde zatím o samostatné Figma komponenty.

Ověřit implementaci proti všem deseti hlavním obrazovkám a ukázkám obrázku/slevy. Prověřit dlouhé názvy, prázdnou adresu, více kontaktů, stavy polí, fokus, tabulkové zarovnání a ukládání.

Pod 1024 px drawer a možnost vodorovného posunu tabulky. Detailní mobilní layout, načítání, prázdné/chybové stavy celých obrazovek a kombinovaný stav obrázek + sleva nejsou vizuálně specifikovány. Řešit v návaznosti na aplikaci, nevydávat za hotový návrh.

Kontrola dokumentu: 21. 9. 2026.

Obě šablony mají implementační poznámky vpravo. Ověřeno proti aplikaci 21. 9. 2026. Nové knihovní komponenty: Invoice Status Badge, Invoice Table Row, Invoice Item Row, Textarea a Checkbox / Unchecked.

Pravidla nabídky zakázky se nepřenášejí automaticky do faktur. Faktury zachovávají režim zadávání cen včetně DPH, poznámku nad položkami a posun řádků; jejich výchozí režim je bez DPH.

Faktura i varianta: úchyt vlevo, název, množství, jednotka, jednotková cena, DPH, editovatelná celková cena a menu vpravo. Shodná pole 44 px, mezery 8 px. Název zabírá zbývající šířku.

Pouze varianta: označení, Vyrábíme, obrázek, sleva položky a sekce. Faktura zůstává plochým seznamem. Přesun řádků úchytem v obou editorech; klávesnicová alternativa zachovává dostupnost.

Cena / j. i Celkem jsou vstupy. Po potvrzení úpravy je naposledy změněné pole autoritou a druhé se přepočítá. Při změně množství zachovat jednotkovou cenu a přepočítat celkem. Prázdné/neplatné množství nebo nula nesmí vyvolat dělení nulou; zobrazit chybu, ne odhadnutou cenu.

Varianta: oba cenové sloupce bez DPH. Bez položkové slevy Celkem = množství × Cena / j. a opačně Cena / j. = Celkem ÷ množství. Ukázka 2 ks × 900 = 1 800; po zadání 2 000 celkem je Cena / j. 1 000.

Při položkové slevě zůstává Celkem po této slevě. Zpětný výpočet musí zohlednit zachovanou slevu: při 10 % a 1 ks je 4 050 celkem → 4 500 jednotkově. Nulový koeficient (100 % sleva) nelze invertovat; vyžaduje úpravu slevy nebo jednotkové ceny.

Faktura: Celkem s DPH, jednotková cena podle zvoleného cenového režimu. Převod zohlední množství a DPH/reverse charge. Např. při 21 %: 8 h × 650 bez DPH → 6 292 s DPH; zadání 7 260 s DPH → 750 bez DPH/j. Celková sleva dokladu se aplikuje až v souhrnu.

Přepočet je specifikací pro implementaci, nikoli spuštěným výpočtem statického prototypu. Přesnost, měna a zaokrouhlení podle existujícího backendu; zabránit cyklickému přepočítávání obou polí.

Seznam faktur i zakázek: dole pouze vycentrované Další. Přidává další dávku řádků se zachováním filtrů a řazení. Při načítání je neaktivní, při vyčerpání výsledků se skryje. Bez Předchozí a bez počtu řádků v patičce.

Contact List · 280:5
/tri/contacts. Vyhledávání, skryté filtry, řazení, barevné role a Další. Sloupec e-mail/telefon slučuje související údaje.

Contact Detail · 280:1925
/tri/contacts/43. Kontaktní údaje, fakturační nastavení, související zakázky a faktury. Role vedle názvu kontaktu; vedlejší akce v menu.

Contact Table Row · 281:50. Sdílené styly a role ze stávajících šablon; detail využívá řádky zakázek a faktur. Implementační poznámky jsou vpravo od obou šablon.

Obsah ověřen 21. 9. 2026. Sedm kontaktů je výřez zdroje; detail obsahuje obě ověřené zakázky a všech pět dokladů. Prototyp neprovádí uložení, archivaci ani smazání.

Nové šablony: Price Lists (291:5), Price List Detail (292:204), katalog (296:171). Podpůrné stavy: úprava cen (294:209), historie (295:325), naplánovaná publikace (297:396).

Položka existuje jednou; ceník má jednu měnu a více vydání. Prodejní cena bez DPH je uložena pro položku a vydání. Kategorie a tagy pouze třídí katalog.

Publikované vydání je neměnné. Úprava vytvoří koncept; publikace určí účinnost a ověří překryvy. Cena změněna se nepřepisuje pouhým převzetím nezměněné částky.

Nabídka drží snímek dat i cenový základ. Změna ceníku nepřepisuje existující nabídku. Ruční aktualizace cen vyžaduje náhled rozdílů.

Podrobné validační, importní a prázdné stavy jsou vedle nových šablon. Ukázková data jsou demonstrační; nejde o schválenou migraci původních ceníků.

## Foundations

- Foundations: `3:44`
- Foundations / Compact text styles: `242:125`

Základní tokeny TRIANT Office. Komponenty a šablony používají sémantickou vrstvu; primitivní paleta slouží pouze jako její zdroj.

Obdélníkové ovládací prvky mají ostré rohy (0 px). Stavové, kontaktní a slevové štítky používají jemný radius 2 px. Typografii určují pojmenované styly; 13/19 px je kompaktní text a 11/16 px drobné štítky.

Body/Compact · Open Sans Regular · 13/19 px
Body/Compact Strong · Open Sans SemiBold · 13/19 px
Body/XS · Open Sans Regular · 11/16 px
Caption/XS · Open Sans Regular · 10/14 px

Kompaktní styly pokrývají kontakty, pomocné texty a malé štítky. Nadpisy a hlavní pole používají existující Heading/* a Body/MD.

Barvy rolí jsou explicitně definované proměnnými color/role/customer/* a color/role/architect/*. Tyto proměnné zachovávají schválené modré a fialové štítky.

Styly Web/* zůstávají mimo implementační rozsah TRIANT Office.

## Templates / Job Detail

- Implementation notes: `20:6`
- Template / Job Detail / Desktop / Canonical: `136:111`

PLATNÁ REFERENCE · 136:111
Desktop 1440 px; sidebar 248 px.
Obsah 1192 px, vnitřní odsazení 40 px; sloupce 640 / 448 px, mezera 24 px.

Hlavička
Breadcrumb 24 px nad nadpisem. Zakázka {číslo}, pod ním název zakázky.

Sdílené vzory
ContactsAddress: stejná struktura jako u varianty, dvě pružné poloviny; role vedle jména.
Varianty: Varianta A/B, pod ní název varianty.

Doplňující pravidla: Getting Started.

Klientka potvrdila materiál pracovní desky. Před výrobou ještě ověřit odstín dvířek, polohu zásuvek a návaznost osvětlení v obou místnostech.

## Templates / Job Variant Detail

- Template / Job Variant Detail / Desktop / Canonical: `170:117`
- Akce položky: `175:367`
- Akce sekce: `175:387`
- Detail / Sleva položky: `176:369`
- Implementation / Job Variant Detail: `176:413`
- Detail / Otevřené menu u řádku: `177:397`
- Detail / Přesun řádku mezi sekcemi: `177:448`
- Detail / Tabulka s obrázkem u prostřední položky: `225:480`
- Detail / Tabulka se slevou u prostřední položky: `228:573`

Název, označení, množství, cena, DPH a Vyrábíme zůstávají přímo v řádku. Tab prochází editovatelná pole. Enter potvrdí změnu, Escape ji vrátí. Názvy sekcí lze editovat kliknutím.

Úchyt vlevo slouží k přesunu, menu … je na pravém okraji. Oba cíle mají alespoň 32 × 36 px. Menu položky: sleva, průvodka, obrázek, smazání. Menu sekce: sleva sekce, smazání. Přesun není položkou menu. Smazání vyžaduje potvrzení a možnost Vrátit zpět.

Řádek se přesouvá úchytem v sekci i mezi sekcemi. Sekce se přesouvá se všemi položkami. Při tažení zůstává místo původního řádku a modrá linka ukazuje cíl. Sbalenou sekci nad cílem rozbalit.

Šipka sbalí sekci, název, počet položek a mezisoučet zůstanou viditelné. Součet sekce uvádí cenu před celkovou slevou. Aktivní sleva položky/sekce je vždy vidět u jejího názvu, i když je menu zavřené.

Záhlaví tabulek a lišta Uložit mají v implementaci zůstat dostupné při posunu. Název položky je pružný, číselné sloupce pevné. Pod 1024 px se sidebar mění na drawer a tabulka může mít vodorovný posun. Mobilní obrazovky nejsou v tomto dokumentu specifikovány.

Platná desktopová reference: 170:117. Ukázky obrázku a slevy jsou doplňkové stavy stejného řádku. Data, role kontaktů, obrázky a částky jsou demonstrační. Prototyp ověřuje menu a dialog slevy; nenahrazuje funkční implementaci ani backendový kontrakt.

Menu se otevírá u tlačítka … vpravo a patří konkrétní položce. Úchyt zůstává na levém okraji a slouží pouze k přesouvání.

Sleva platí pouze pro prostřední položku. Cena za jednotku zůstává původní; ve sloupci Celkem je cena po slevě.

## Templates / Invoice List

- Template / Invoice List / Desktop / Canonical: `250:5`
- Handoff / Invoice List: `259:247`
- Overlay / Invoice filters: `275:1873`

Zdroj: /tri/invoices. Skupiny podle DUZP, u proformy podle vystavení. Měsíční nadpis, počet dokladů, součty po měnách a vlastní tabulka.

Hlavní lišta: vyhledávání podle variabilního symbolu nebo klienta a tlačítko Filtry. Panel obsahuje stav, typ, klienta, zakázku, měnu, rok, měsíc, datum od/do, po splatnosti a nezaplacené. CSV export není součástí návrhu.

Stavy: Koncept / Vystaveno / Odesláno / Upomínka / Zaplaceno / Storno. Po splatnosti je oddělená vlastnost data. Stav se nesmí odvozovat jen z barvy.

Šest řádků a součty jsou ilustrační výřez. Dole pouze vycentrované tlačítko Další. Připojí další dávku ke stávajícím řádkům, zachová filtry, řazení i již načtené měsíční skupiny. Při načítání je nedostupné; po poslední dávce se skryje. Počet načtených řádků odvodit z API.

K úhradě zachovává význam částky v aplikaci; nepovažovat automaticky za zbývající dluh. Měny se nesčítají dohromady.

Řádek otevírá detail faktury, zakázka její detail. Bez výběrových checkboxů a hromadných akcí. Poslední sloupce: Splatnost → Stav → K úhradě. Nová faktura odkazuje na druhý Figma frame.

Prázdné výsledky: zachovat filtry a CTA; zpráva „Žádné faktury neodpovídají filtrům.“ Chyba načítání nesmí vypadat jako nulový počet dokladů.

Filtry: otevření tlačítkem, zavření křížkem/Zavřít, Escape nebo mimo panel. Použít potvrdí volby; Vymazat obnoví nefiltrovaný stav. Prototyp ukazuje otevření a zavření, ne skutečné filtrování dat.

## Templates / Invoice New

- Template / Invoice New / Desktop / Canonical: `250:1156`
- Handoff / Invoice New: `259:1632`
- Overlay / Invoice item actions: `259:1645`

Zdroj: /tri/invoices/new. Vyplněný koncept s ukázkovými daty. Vytvořit založí doklad; není to vystavení ani odeslání.

Výchozí stav: daňový doklad, nevybraný klient, bez zakázky, CZK, CZ, převod, daňové volby vypnuté. Vystaveno a DUZP = dnešek, splatnost dle aplikace (při kontrole +7 dní). Jeden prázdný řádek, množství 1, jednotka h, cena 0, DPH 21 %, sleva 0 %.

Povinné: typ, klient, vystaveno, DUZP, splatnost. Číslo se generuje při vystavení, případně ruční zadání. Klient a zakázka jsou vyhledávací výběry. Nový klient zachovává existující tok aplikace.

Typy: daňový doklad / zálohová faktura / dobropis. Měny CZK/EUR, jazyky CZ/EN. Úhrada: převod, karta, hotově, jiný způsob. Jednotky a sazby z číselníků; duplicitní sazby ze zdrojového snapshotu nekopírovat.

Standardní zadávání bez DPH. Faktura zachovává i režim cen včetně DPH a reverse charge. Popisek Cena / j. se mění podle režimu. Celkem s DPH lze přepsat; dopočítání určuje aplikace.

Pořadí položek mění úchyt vlevo, stejně jako u variant. Menu může nabídnout posun nahoru/dolů pro ovládání klávesnicí a smazání. Faktura neobsahuje Vyrábíme, obrázky, položkové slevy ani sekce.

Poznámky nad i pod položkami jsou zachované funkce faktury. Ve formuláři obě pod souhrnem; v PDF pozice podle názvu pole. Přílohy: 10 MiB/soubor, 20 MiB celkem, upload po vytvoření.

Ukázka: 20 000 + 8 × 650 + 1 500 = 26 700 Kč. Sleva 10 % = 2 670 Kč, základ 24 030 Kč, DPH 5 046,30 Kč, celkem 29 076,30 Kč. Backend je autoritou pro validaci a zaokrouhlení. Nulový či záporný výsledek řeší existující validace a dobropis.

Navigační odkazy mezi stránkami vedou na příslušný Figma frame. Odesílání, vystavení a skutečný upload se v návrhu neprovádějí.

## Templates / Contact Detail

- Template / Contact Detail / Desktop / Canonical: `280:1925`
- Overlay / Contact actions: `284:186`
- Overlay / Edit contact roles: `284:193`
- Handoff / Contact Detail: `285:190`

Zdroj /tri/contacts/43, ověřeno 21. 9. 2026. Hlavička: název, role vedle názvu, IČO, Upravit kontakt a menu. Obsahové údaje z aplikace, rozložení podle TRIANT Office.

Nahoře kontaktní údaje s adresou, obratem a poslední aktivitou, vedle fakturační nastavení (jazyk, měna, splatnost, reverse charge). Pomlčka a stav Nezadáno označují chybějící údaj; nepředvyplňovat domněnky.

Zakázky jsou nová sekce požadovaná uživatelem. Břízová je v /tri/jobs navázána na 260009 Kuchyň konzultace — Dubský/Břízová (Aktivní) a 260007 Kuchyň na míru — Břízová (Potvrzená). Při implementaci zahrnout všechny zakázky, kde figuruje kontakt, nikoli pouze ty, kde je hlavním zákazníkem; vazby ověřit v datovém modelu.

Faktury: všech 5 dokladů ze zdroje včetně konceptů. Proto název Faktury, nikoli Vystavené faktury. Stav před K úhradě. Nová faktura předvybere client_id=43; Figma odkaz otevírá společnou šablonu a tento datový parametr sám neaplikuje.

Obrat 44 244 Kč je metrika zdrojové aplikace. Neodvozovat jej součtem zobrazených dokladů: mohou zahrnovat koncepty, zálohy a nulové doklady. Finanční význam a měnu zachovat podle backendu.

Menu: Upravit role, Archivovat kontakt, Smazat kontakt. Role lze kombinovat, zákazník modrý, architekt fialový; ukládání má explicitní potvrzení Uložit role. Archivace a mazání respektují oprávnění, potvrzení a omezení existující aplikace. Prototyp tyto operace neprovádí.

Otevřít v MyÚčtu vede na existující externí kontakt. E-mail a telefon jsou mailto/tel. Breadcrumb vrací na přehled. Žádné odesílání zpráv ani změny dat v rámci návrhu.

Bez zakázek/faktur zachovat nadpis sekce a stručný prázdný stav. U větších seznamů připojovat další dávku tlačítkem Další uprostřed. V ukázce jsou všechny 2 zakázky a 5 faktur, proto je bez tlačítka. Detailní mobilní a chybové obrazovky nejsou nakreslené.

## Templates / Price Lists

- Ceníky / Desktop: `291:5`
- Přehled ceníků · pravidla implementace: `292:196`
- Položky / Desktop: `296:171`
- Filtry ceníků: `296:363`
- Nový ceník: `296:395`
- Korpus 900 bílý: `298:287`
- Filtry katalogu: `298:348`
- Katalog · pravidla implementace: `298:387`

Nový model: katalog položek → ceník → vydání → ceny. Ukázková data představují návrh budoucího stavu, nejde o migraci produkčních dat.

V seznamu je každý ceník právě jednou. Sloupce Vydání / Platí od / Stav se vztahují k aktuálnímu vydání. Pokud ceník ještě nebyl publikován, zobrazí se Rozpracované. Další vydání ukazuje rozepsané nebo naplánované pokračování.

Jedna měna na ceník. Jednotkové prodejní ceny jsou vždy bez DPH. Kategorie a tagy nenahrazují ceníky a nevytvářejí duplicitní položky.

Hledání podle názvu; Filtry: měna, stav a archivované ceníky. Při dalších výsledcích použít pouze vycentrované Další. Zde jsou načteny všechny 3 ceníky.

Nový ceník: název, měna, volitelná poznámka. Vznikne první rozpracované vydání; položky se vybírají z katalogu. Historické vydání nelze přepsat ani odstranit, pokud je zdrojem nabídky.

Kód je stabilní a jedinečný. Název, popis, jednotka, volitelný obrázek, parametry, hlavní kategorie a tagy patří položce; cena patří řádku vydání.

Detail položky ukazuje ceny v jednotlivých cenících a jejich historii. Volitelný obrázek se spravuje u položky a převezme se při vložení do varianty.

Změna metadata katalogu aktualizuje katalog, nikoli již vytvořené snímky položek v nabídkách. Přejmenování kódu či změna jednotky vyžaduje kontrolu vazeb.

Nová položka nemá globální cenu. Nejprve vznikne katalogový záznam, potom se zařadí do rozpracovaných vydání s vlastní cenou. Vyřazení z ceníku neodstraňuje katalogový záznam.

Obrazovky jsou vzájemně propojené v hlavních scénářích. Filtry, hledání, ukládání a výpočty jsou návrh chování; Figma neprovádí operace v produkční aplikaci.

## Templates / Price List Detail

- TRIANT – základní / Desktop: `292:204`
- Detail ceníku · pravidla implementace: `293:235`
- Upravit ceny / Desktop: `294:209`
- TRIANT – základní / Desktop: `295:325`
- Publikovat vydání 2026/02: `295:473`
- Price List / Scheduled release: `297:396`
- Filtry položek: `297:614`
- Hromadně upravit ceny: `297:643`
- Import XLS · kontrola před použitím: `297:692`
- Přidat položky z katalogu: `299:604`
- Editace, publikování a okrajové stavy: `299:632`
- Akce ceny: `299:3177`

Platné vydání je pouze ke čtení. Upravit ceny vytvoří kopii do rozpracovaného vydání, nebo otevře již existující koncept. Bez duplicitních konceptů po opakovaném kliknutí.

Cena změněna je datum skutečné změny částky v kombinaci položka + ceník. Převzetí ceny do dalšího vydání jej nemění. Autora a historii ukazuje detail ceny.

Platnost je společná pro celé vydání. Žádné samostatné intervaly na položkách. Při publikování se ověří překryvy; začátek nového vydání ukončí předchozí předchozím dnem.

Nabídka si ukládá snímek názvu, popisu, jednotky a ceny i zdroj položky a vydání. Existující nabídky se samy nepřecení. Aktualizovat ceny podle ceníku vyžaduje náhled rozdílů a potvrzení.

Ukázky mají navržená data a počty. K10, D10 a H10 vycházejí ze současného ceníku; další řádky slouží k demonstraci katalogu. Sazba DPH je výchozí pro položku, v dokladu závisí na daňovém režimu.

X99 · položka neexistuje v katalogu. Přiřaďte existující položku nebo ji nejprve vytvořte v katalogu. Import nezakládá duplicity automaticky.

Editor načte všechny ceny ze zdrojového vydání. Přidání / vyřazení položky mění pouze koncept. Cena je konečná nezáporná částka v měně ceníku; nula je dovolená, prázdná cena blokuje publikaci.

Uložit rozpracované zachová koncept bez vlivu na ceny. Odchod s neuloženými změnami nabízí Uložit / Zahodit / Zůstat. Zabraňte přepsání novější úpravy jiným uživatelem.

Hromadná úprava i XLS import vždy nabídnou rozdíly před aplikací. Import mapuje kód, cenu a měnu; odmítne duplicitní kódy a nesoulad jednotky nebo měny. Neznámé kódy řeší uživatel.

Publikace validuje intervaly a kompletnost cen atomicky. Od dneška vzniká Platné, od budoucího data Naplánované. Při konfliktu s již naplánovaným vydáním zobrazit kolizi a nepřepsat jej potichu.

Publikované vydání nelze editovat. Zobrazit vydání otevře jeho snímek pouze ke čtení. Tato sada návrhů demonstruje aktuální vydání, koncept, historii a úspěšnou naplánovanou publikaci.

Žádné výsledky: prázdná tabulka s textem a Zrušit filtry. Prázdný ceník: Přidat z katalogu. Chyba načítání: Zkusit znovu. Tlačítko Další během načítání deaktivovat, při konci výsledků skrýt.

Cenový základ nabídky = ceník + rozhodné datum + vydání. Při vložení pozdější položky použít stejné vydání. Není-li pro datum platné vydání, nevkládat jinou cenu bez rozhodnutí uživatele.



## Aktualizace navigace — 2026-09-22

Aktuální sidebar `14:103`, Navigation Item `313:386`, rozbalené Další agendy
`315:5767`, rozbalený Systém `315:5988` nahrazují starší popis menu výše.
Hlavní menu nemá Nabídky: Přehled, Zakázky, Faktury, Kontakty, Ceníky, Nastavení.
Lucide ikony exportované přímo z návrhu; 20 px hlavní, 16 px podřízené.
Řádky 44/36 px, mezera 4 px, vnoření 32 px, samostatné + má cíl 32 px.
Skupiny mají vlastní ikonu a šipku, aktivní podřízená položka zvýrazní skupinu.
Fokus musí být viditelný i na aktivní položce. Nabídky zůstávají v zakázkách.

## Aktivní filtry — schválený společný vzor

Zdroj `325:4`, Components / Filters `324:4`, Filter Button `324:13`,
Applied Filter Chip `324:14`. Platí pro zakázky, faktury, kontakty, ceníky,
katalog a položky vydání ceníku. Tlačítko 44 px: světle modrá plocha, modrý
rámeček a samostatný modrý badge 24 px. Neutrální stav bez badge.
Pod hledáním odstranitelné podmínky a Zrušit filtry. Jeden filtr = jedno
aplikované pole, více hodnot a datumový interval se počítají jednou.
Výchozí omezení (rok, nearchivované) se počítají; hledání ani řazení ne.
Rozpracované hodnoty panelu se projeví až po Použít filtry. Zrušení zachová
hledání i řazení; po posledním filtru zmizí štítky. Chyba nesmí ukázat
staré nefiltrované řádky jako výsledky nové podmínky. Štítky se zalamují,
celý text zůstává dostupný, odstranění vrací fokus na další štítek/tlačítko.
