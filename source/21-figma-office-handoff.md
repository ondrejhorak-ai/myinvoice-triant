# Triant office — předání implementace podle Figmy

## Výchozí kontext

Tento dokument je vstupní bod pro Cursor i další agenty pracující na Office.
Zdroj návrhu je [TRIANT Office ve Figmě](https://www.figma.com/design/nNhjXHJme5zPppfPtNoI6I/TRIANT-Office?node-id=3-11).
Přesné node ID obrazovek a přepis předávacích poznámek jsou v
[21-figma-office-reference.md](21-figma-office-reference.md).

Figma MCP `get_metadata` bez node ID nevrátil všechny stránky. Kompletní
strukturu poskytlo `figma.root.children` přes `use_figma`; pro konkrétní
šablony použij jejich ID. Nepředpokládej, že soubor obsahuje jen Cover / App Shell.

Architektura [18-myucto-split-architecture.md](18-myucto-split-architecture.md)
a [19-myucto-phase-briefs.md](19-myucto-phase-briefs.md) zůstává závazná.
Kontakty a faktury zapisuje Office pouze přes API MyÚčta. Nové session endpointy
`/api/tri/pricing/*` nepatří do kurátorovaného veřejného API `/api/v1/*`.

V pracovním stromu již před implementací byly změny Cursoru: odstranění Update
a TriSettings, konsolidace nastavení, změny navigace, API/sidebar konfigurace,
názvy variant (migrace 9020), šablona PDF nabídky a související lokalizace.
Byly zachovány; nerozděluj automaticky celý diff na „změny od Figmy“ a nevracej
je přes reset/stash. `VERSION`, `CHANGELOG.md` a upstream se nemění.

## Rozhraní a komponenty

- `web/src/styles/main.css`: Open Sans, lokální font s OFL licencí, hranaté
  komponenty, desktopový sidebar 248 px, obsah s odsazením 40 px. Barvy manuálu
  zrcadlí změněné tokeny. Logo i navigační ikony jsou exporty Figmy.
  Aktuální navigace používá Lucide z uzlů `14:103`, `315:5767`, `315:5988`.
  `OfficeNavIcon` zobrazuje přesné exporty jako CSS masky s `currentColor`;
  soubory jsou v `web/public/assets/tri/navigation-20260922/`.
  Hlavní řádky mají 44 px / ikony 20 px, podřízené 36 px / ikony 16 px.
  Samostatná tlačítka + jsou vždy viditelná, mají cíl 32 px a konkrétní popisek.
  Skupiny se ovládají přes summary klávesnicí, aktivní skupina se otevře,
  posouvá se pouze navigace, vyhledávání a profil zůstávají dostupné.
  Tento nový požadavek nahrazuje dřívější přání obnovit původní ikony.

- Sdílené `OfficeBreadcrumb`, `OfficeRoleTag`, `OfficeMenu`, `JobHeaderInfo`.
  Menu používá teleport mimo scrollované tabulky; Escape vrací fokus a šipky
  přepínají akce. `Modal` má dialog semantics, zachování fokusu a focus trap.
- Zakázky, kontakty, faktury: vyhledávání, filtry a přidávání dalších výsledků.
  Faktury zachovávají seskupení z MyÚčta, měny a nulové `amount_to_pay`.
- Detail zakázky: údaje / kontakty / varianty / termíny / faktury / průvodky /
  reklamace vlevo, komunikace vpravo. Další fakturační akce jsou v menu.
- Varianty: oddělené sekce, přetažení a Alt+šipky, položková/sekční/celková
  sleva, inverzní zadání celku, potvrzení a undo smazání. Řádkový celek je po
  položkové slevě, souhrn respektuje celou původní hierarchii výpočtu.
- Faktura: plochá tabulka, přesun úchytem, menu smazání, poznámka nad/pod
  položkami, sleva v souhrnu, přílohy a uložení. Režim cen s DPH a reverse charge
  zachovává existující výpočty a odesílané hodnoty.
- Hlavní menu: Přehled, Zakázky, Faktury, Kontakty, Ceníky, Nastavení.
  Nabídky byly na přání uživatele odstraněny z menu i jeho vyhledávání;
  nabídky/varianty zůstávají uvnitř zakázky, existující přímá route a API nejsou
  odstraněné. Oprávnění a nastavení skrytých modulů zůstávají zachované.
  Další agendy a Systém používají nové rozbalovací skupiny podle Figmy.

## Katalog, vydání a kompatibilita

Migrace **9021** je aditivní a idempotentní. Zavádí `tri_catalog_products`,
`tri_price_releases`, `tri_price_release_items`, měnu a archivaci ceníku a
zdrojové odkazy v nabídkách. Neprovádí hromadný převod produkčních položek.

`PricingRepository` používá řádek ceníku jako transakční zámek. Koncept je právě
jeden, opakované vytvoření vrátí existující. Save/publish kontrolují
`lock_version`; publikované ceny jsou neměnné. Detail přednostně ukazuje
aktuální publikované vydání, Upravit otevře koncept. Nový koncept kopíruje ceny
a aktuální katalogová metadata; publikované snímky se nepřepisují.

První explicitní úprava původního ceníku převezme jeho položky do konceptu.
Bez potvrzené identity výrobků se položky napříč ceníky **neslučují**. Kód dostane
jednoznačný suffix `~L{listId}-{legacyItemId}`. Původní tabulka cen i historické
nabídky zůstávají zachované. Kód/jednotku již použitého produktu nelze změnit;
nová identita vyžaduje nový produkt. Vracet globální cenu do produktu je chyba.

Publikování vyžaduje všechny ceny, platné datum od dneška, nepřekrývající se
vydání. Starší aktuální interval se může zkrátit na předchozí den. Naplánované
vydání se tiše nenahrazuje. Nula je platná cena, prázdná hodnota není.
Datum změny ceny se při publikování porovnává se zdrojovým vydáním, takže
navrácení původní částky v konceptu nefalšuje historii.

XLS/XLSX import je dvoufázový: server načte nejvýše 10 000 řádků / 5 MB,
validuje `code,unit_price,unit,currency`, duplicitní/neznámé kódy a jednotku/měnu.
Náhled nemutuje DB; uživatel jej aplikuje do konceptu a následně uloží.
Nepočítají se importované vzorce. Hromadná procentní změna také ukazuje rozdíly.

`price_release_id`, `price_effective_date` na variantě a `source_product_id`,
`source_release_id` na řádku uchovávají zdroj cen. Pozdější vložení používá
stejné vydání. Duplikace je zachová. Historická vazba platí i po zkrácení
`valid_until`. Přecenění je explicitní náhled + potvrzení a uloží nový zdroj;
ruční řádky, množství a slevy nemění. Nabídky zůstávají v CZK; faktury vybírají
ceník podle měny svého dokladu. PDF ceníku přijímá `?release=ID` a exportuje
konkrétní snímek, nikoli původní živé řádky.

## Rozhodnutí vůči demonstračnímu návrhu

- Ukázkové stavy zakázek ve Figmě nenahrazují existující workflow
  active/confirmed/rejected/completed.
- Nové provize ani přepínač cen s DPH se do editoru nabídky nepřidávají.
  Historické provize, poznámka nad položkami a interní hodnoty se při uložení
  zachovávají. Nulová sazba DPH se v náhledu nesmí změnit na 21 %.
- Dobropisy a účetní storna řeší MyÚčto; Office je nevytváří jen proto, že jsou
  v demonstračním selectu Figmy. Kontakt se archivuje existujícím write-through
  API; neexistující skutečné smazání není nabízeno pod falešným názvem.
- Obrat kontaktu je existující TRI metrika schválených nabídek potvrzených a
  dokončených zakázek bez DPH, nikoli účetní obrat. Detail zahrnuje zákazníka
  i další přiřazené kontakty, nezapočítává zakázku dvakrát.
- `GenerateTravelersAction` přijímá volitelný `line_id`; menu řádku generuje jen
  tento výrobní řádek schválené varianty. Celá zakázka zachovává hromadnou akci.
- Při kontrole rout byl doplněn chybějící import `CreateTriInvoiceAction`;
  `TriRoutesTest` ověřuje resolvování všech registrovaných tříd.

## Ověření a další práce

- `pnpm --dir web build` (povinné po změně `web/src`).
- `node --test web/tests/tri-price-input.test.mjs`: inverzní ceny, nulové množství,
  neplatná čísla, 100% sleva a nulové DPH.
- `cd api && php vendor/bin/phpunit tests/Unit/Tri tests/Architecture`.
- Integrační test `tests/Integration/Tri/PricingRepositoryTest.php` běží pouze
  při explicitním `TRI_PRICING_TEST_DB` s názvem končícím `_test`, proti lokální
  samostatné MariaDB. `TRI_PRICING_TEST_PORT` default 13379, další volby
  `TRI_PRICING_TEST_USER`, `TRI_PRICING_TEST_PASS`. Vyžaduje migrace přes
  `api/bin/migrate.php` a syntetického dodavatele. DB potom zahoď; testová data
  záměrně ponechává i pro vizuální QA. Nikdy jej nepřipojuj k produkci/MyÚčtu.
- Migrace 9021 byla ověřena na čisté MariaDB 11.8 i opakovaným spuštěním.
- Širší sada Unit+Architecture: 1141 testů, tři známé chyby v nezměněném kódu:
  `CzkRecapTest::testMultipleVatGroupsRoundedPerGroup` (511.66 vs 511.67),
  `ClientValidationTest::testMinimalClientPasses` a `testValidEmailPasses`
  (testy neuvádějí povinnou adresu). Daňový výpočet kvůli redesignu neopravuj
  bez samostatného ověření jeho specifikace. Sada má také přeskočené integrační
  závislosti a jednu deprecation PHPUnit.
- Vizuální QA používá Chromium 1440 px a 390 px. Frontendový build lze podložit
  místo HTML/statiky na doméně Office přes Playwright route; produkční API
  povolit pouze GET. Nové mutace katalogu směrovat do izolované testovací DB.
  Necommituj screenshoty s reálnými doklady ani cookies/tokeny.

Nasazení podle `.cursor/rules/deploy-after-changes.mdc`: `/opt/office/scripts/up.sh`,
potom health check `https://office.triant.cz/api/health`. Nepoužívej `update.sh`,
protože může přepsat necommitovanou práci. Generované `web/dist` a manuál se
necommitují. Výsledky poslední vizuální kontroly a nasazení doplň níže.

### Dokončené ověření a nasazení — 2026-09-21

- Produkční build prošel včetně TypeScriptu. Frontendová sada: 66/66 testů.
  TRI + architektura: 127 testů / 576 assertions. Izolovaný integrační test:
  1 test / 14 assertions. Tři výše popsané chyby širší sady nejsou opravené.
- Import má samostatný `ImportPreviewActionTest`: validní soubor, duplicity,
  neznámý produkt, měna, jednotka, vzorce a zákaz zápisu pro readonly roli.
- V prohlížeči ověřen celý tok nový produkt → koncept ceníku → vložení produktu
  → cena → uložení → reload → publikování → neměnné vydání. Zápisy pouze do
  izolované syntetické DB; žádné testovací doklady nevznikaly v MyÚčtu.
- Po nasazení ověřeny skutečné stránky Přehled, Nabídky, Zakázky, Kontakty,
  detail kontaktu, varianta nabídky, Ceníky, Katalog a nová faktura. Bez JS chyb
  a bez chybových odpovědí čtených API. Vizuální kontrola Chromium desktop
  1440 px; mobilní zakázky, kontakty, ceníky a faktura mají šířku dokumentu
  přesně 390 px při viewportu 390 px. Tabulky scrollují uvnitř panelu.
- Důležitá produkční odlišnost od Vite: statické exporty a font jsou v
  `web/public/assets/tri/` a `web/public/assets/fonts/`. Používej `/assets/...`;
  `/tri/logo.svg` a `/fonts/...` vrací na Apache/IIS SPA HTML. Při změně obsahu
  těchto souborů změň jejich název/URL kvůli immutable cache statických assetů.
  `UiTable` potřebuje `relative`, aby absolutní sr-only záhlaví neroztahovalo
  celou mobilní stránku. Obě opravy byly ověřeny na běžícím webu.
- `/opt/office/scripts/up.sh` dokončen s exit 0. Při prvním restartu může jeho
  okamžitý curl před naběhnutím Apache vrátit 502; následné spuštění s cache
  a health kontrola prošly. `/api/health`: status ok, db true. Migrátor v
  běžícím kontejneru potvrzuje aplikované migrace 9020 a 9021.
- Manuál HTML i PDF regenerován (46 kapitol). `git diff --check` prošel.
  Pracovní strom zůstává necommitovaný včetně původních změn Cursoru.

### Ověření nového menu — 2026-09-22

Build a kontrola diffu prošly. Playwright ověřil odstranění Nabídek z navigace,
rozbalování skupin klávesnicí, otevření aktivní skupiny po navigaci, samostatné
+ pro novou zakázku a mobilní menu při 390 px bez přetečení. Vizuálně porovnány
sbalené i rozbalené skupiny s návrhem. Stejné kontroly prošly na nasazené verzi.
Manuál HTML/PDF regenerován. Nasazení potvrzeno exit 0 a health status ok/db true.

## Aktivní filtry — společný vzor

`OfficeFilterButton`, `OfficeAppliedFilters` a `useAppliedFilters` oddělují
indikaci aplikovaných podmínek od rozepsaných hodnot dialogu. Definice pole
obsahuje aktivitu, plný lokalizovaný popisek a odstranění do neutrálního stavu.
Počet je délka seznamu podmínek, nikdy počet vybraných hodnot nebo URL klíčů.
Štítky jsou tlačítka s dostupným názvem; dlouhé hodnoty se zalamují bez ztráty
textu, pro dotyk mají min. 44 px. Po odstranění fokus pokračuje na sousedním
štítku, po posledním/Zrušit filtry se vrací na stabilní tlačítko Filtry.

Zapojeno: JobList, InvoiceList, ContactList, PriceListList, CatalogList a
PriceListEditor. Ceníky/položky dříve aplikovaly hodnoty při editaci dialogu;
nově mají explicitní draft. Hledání a řazení se při mazání filtrů nemění.
Faktury ukazují výchozí aktuální rok. Rozsah datumů má přednost před rokem
a měsícem; po jeho odstranění se skrytý rok neobnoví. Více stavů/typů z URL
se vypíše v jednom štítku. URL `year=all` zachová skutečně všechny roky.

Archivace je explicitní filtr. Výchozí nearchivované kontakty/zakázky/ceníky
mají štítek. Zrušení přepne na všechny, včetně archivovaných. Pro korektní
serverové stránkování byl do interního `/api/clients` přidán sentinel
`filter[archived]=all` (boolean false/true zachovává původní význam
nearchivované/pouze archivované); do `/api/tri/jobs` parametr
`archive=active|archived|all`. Toto je minimální rozšíření existujícího core
ListClientsAction/ClientRepository vyžadované neutrálním stavem filtru.
Všechny dotazy dál zachovávají supplier scope. Veřejné `/api/v1` ani
MyÚčto API/DB se nemění, migrace není potřeba.

Při změně serverových podmínek se předchozí řádky odklidí (`usePagedRows` a
listy kontaktů/faktur). Poslední odpověď má přednost, při chybě zůstávají
štítky i chybová zpráva; Retry obnovuje první stranu stejného dotazu.
Unit testy: `tri-applied-filters.test.mjs` a `ArchiveListFilterTest.php`.
Kontrola v prohlížeči zahrnuje zrušení bez použití, výchozí podmínky,
jednotlivý křížek, vyčištění se zachováním hledání/řazení, interval, chybu
katalogu a zalamování dlouhého štítku na 390 px. Testovací katalog a
vydání jsou syntetické odpovědi prohlížeče; test nezapisuje produkční data.

Ověření aktivních filtrů: build prošel, frontend 68/68 testů, TRI/architektura
129 testů / 648 assertions bez chyb. PHP lint změněných souborů a diff check
prošly. Celý scénář v prohlížeči prošel před nasazením i na nasazeném buildu
(desktop 1440 px / mobil 390 px); pricing data/chybová odpověď v QA jsou
synteticky podložené, kontakty/zakázky/faktury používají skutečné read-only API.
Nasazeno 2026-09-22 přes up.sh, potvrzující běh exit 0, health status ok/db true.
Manuál HTML i PDF regenerován. Změny zůstávají v pracovním stromu společně
s předchozí prací, žádný automatický commit ani migrace.

## Říjen 2026 — vrstvy, Prodejna, pokladna

Živá Figma (stránky reorganizované 10. 10. 2026) je zdroj. Přepis ze
21. 9. 2026 ho v těchto bodech nepřebíjí.

- Kalendář načítá všechny vrstvy najednou (`calendar` může chybět). Vrstvy
  se vypínají v liště. Týden kreslí vícedenní pruh, měsíc ukáže dvě události
  a zbytek přes **Další**. Nová událost začíná výběrem vrstvy. Popisek
  `shifts` je „Služby na prodejně“; nová služba z UI je celodenní.
  Expedice je jedna událost na destinaci. Okno příjezdu a posádka jsou
  nepovinné texty (migrace 9023). Služba na prodejně má jednoho uživatele
  Office, ne volný text.
- Prodejna je pohled na `/tri/invoices` (`view=counter`, `filter[prodejna]=1`):
  zaplacená faktura bez zakázky. Záloha a dobropis v pohledu nejsou. Vstup
  smaže filtr stavu, typu, nezaplaceno, po splatnosti a zakázky. Není to
  nová databáze prodeje ani položka menu.
- Pokladna je lokální kniha `tri_cash_movements` / `tri_cash_handovers`
  (migrace 9022). Hotovost mění zásuvku, karta ne. Předání: základ 5 000 Kč,
  odvod `max(0, napočítáno − základ)`, rozdíl se uloží a kniha se nepřepíše.
  Číslo je ASCII `PR-RRRR-MM`. Opakovaný `client_token` vrátí existující řádek.
  Příloha je poznámka. Menu: Další agendy. Role readonly smí jen číst.
- Částečná úhrada faktury se nezadává. Kontakt žije v Office (migrace 9024):
  odběratel je firma nebo soukromá osoba, kontaktní osoba je navázaná na firmu
  a do MyÚčta se neposílá. Odběratel se do MyÚčta zapíše až s první zálohou nebo
  fakturou. Potvrzení zakázky projekt nevyžaduje; projekt vznikne s dokladem.
  Role osoby na zakázce jsou zákazník, architekt a kontakt. Jediné shodné IČO
  se spojí samo, stejné jméno jen jako návrh.
- Tlačítko `lg` je 44 px. Události kalendáře používají `.office-event-*`,
  souhrn pokladny `.office-stat`.
- Datum v polích je české `dd. mm. rrrr` (sdílený `UiInput` typu date, locale `cs`).
  Výběr má vlastní šipku, ne nativní šipku prohlížeče. Nová faktura má vpravo
  stav **Koncept**, sloupce **Klient a doklad** a **Data a číslování**, box
  propojení s MyÚčtem a položky na celou šířku se slevou vedle souhrnu.
  Detail zakázky má v hlavičce výběr stavu a **Upravit zakázku**, kontakty
  a varianty jako karty a komunikaci jako bubliny se zprávou. Stavy zakázek
  se nemění.
