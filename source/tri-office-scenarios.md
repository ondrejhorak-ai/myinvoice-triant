# Standardizované obchodní scénáře S1–S10 (Triant office × MyÚčto)

Zdroj pravdy pro scénářový loop. Každý scénář se prochází **klikáním v prohlížeči**
na `https://office.triant.cz` (přihlášený uživatel `ondrej.horak@triant.cz`);
zrcadlo se ověřuje **čtením UI** na `https://ucto.triant.cz` (druhý tab, přihlášeno).
Kód ani DB MyÚčta se nikdy nemění.

## Pravidla pro všechny scénáře

- **Fiktivní data:** výroba nábytku na míru. Každý běh vymýšlí nová česká jména
  (ateliéry, stolárny, architekti, soukromé osoby) — žádná reálná osobní data.
  IČO syntetické (např. `999xxxxx`), telefony `+420 777 xxx xxx`, e-maily
  `@example.cz`. Bankovní účet placeholder `1000000005 / 0100`.
- **Marker `TRI-SCEN`** v poznámce kontaktu i zakázky, aby šly záznamy z běhů dohledat
  (a případně později uklidit).
- **Fail → fix → replay:** když scénář spadne, zapiš bug jako nový řez do backlogu
  hned nad zbývající scénáře, oprav, deployni a **celý scénář zopakuj od začátku**
  (s novými fiktivními daty). Scénář je hotový až když projde celý bez zásahu do kódu.
- **E-maily se neodesílají** (SMTP v MyÚčtu není nakonfigurováno). Krok „odeslat"
  se ověřuje jen tak, že UI korektně zareaguje (dialog/hláška), ne živým odesláním.
- **Storno / dobropis se z office nedělá** (jen v MyÚčtu, mimo scénáře).
- Ověření v MyÚčtu: klient v adresáři, projekt v seznamu projektů, doklad
  v seznamu faktur (typ, stav, částka, vazba na projekt).

## S1 — Nový zákazník a zakázka

1. Kontakty → Nový kontakt: firma (název, IČO, adresa, e-mail, telefon),
   označit jako **Zákazník**. Uložit.
2. Zakázky → Nová zakázka: „Kuchyň na míru — <příjmení>", přiřadit kontakt, uložit.
3. Očekávání: kontakt má `myucto_id` (viditelný v adresáři MyÚčta); zakázka má
   vyplněného zákazníka **bez ručních workaroundů** (tag/checkbox stačí z formuláře);
   po potvrzení/fakturaci vznikne projekt v MyÚčtu.

## S2 — Dvě varianty, sleva, schválení B

1. Na zakázce z S1 založit variantu A: 2–3 položky (korpusy, dvířka, pracovní deska),
   nechat jako koncept.
2. Varianta B: sekce (např. „Spodní skříňky" / „Horní skříňky"), 4+ položek
   s množstvím a jednotkovými cenami, **sleva** na zakázku (procentní nebo absolutní).
3. Schválit variantu B, potvrdit zakázku.
4. Očekávání: souhrn varianty B počítá slevu správně; v MyÚčtu existuje **jeden**
   projekt pro zakázku (žádná duplicita po opakovaném uložení).

## S3 — Zálohová faktura

1. Ze zakázky vystavit **zálohovou fakturu** (~50 % ceny schválené varianty).
2. Otevřít editor konceptu — nesmí viset na „Načítám…". Zkontrolovat položky, uložit.
3. Vystavit (issue), poté označit jako zaplacenou.
4. Očekávání: doklad je vidět v office seznamu faktur (a **zůstane** tam — cron sync
   ho nesmí tombstonovat); v MyÚčtu je proforma vystavená a zaplacená, navázaná
   na projekt zakázky.

## S4 — Daňový doklad k přijaté záloze

1. Z vystavené a zaplacené zálohy z S3 vytvořit **daňový doklad k platbě**
   (issue-final z proformy).
2. Očekávání: doklad vznikne přes MyÚčto (žádné 410 z core API), objeví se
   v office i v MyÚčtu, navázaný na zálohu i projekt.

## S5 — Doplatková (konečná) faktura

1. Ze zakázky vystavit **konečnou fakturu** — položky ze schválené varianty,
   odečtená zaplacená záloha.
2. Zkontrolovat částku: součet varianty − zaplacené zálohy. Vystavit.
3. Očekávání: v MyÚčtu jsou oba doklady (záloha + konečná) na stejném projektu,
   konečná má odečtenou zálohu.

## S6 — Ceník a výroba

1. Ceníky → nový ceník (např. „Dvířka a korpusy 2026") se 3+ položkami
   (označení, název, cena, DPH, jednotka).
2. Na nové zakázce (nový kontakt dle S1 zkráceně) vložit do varianty položky
   z ceníku (picker), část označit **Vyrábíme**, schválit variantu, potvrdit zakázku.
3. Vygenerovat průvodky, na jedné vyplnit hodiny u 1–2 stanovišť, stáhnout PDF.
4. Očekávání: průvodky jen z vyráběných řádků; PDF **bez cen**; součet hodin
   na detailu zakázky sedí.

## S7 — Kalendář

1. Na zakázce z S6 naplánovat událost **výroba** (stanice, termín) a **expedice**.
2. Přepnout výrobu `planned → in_progress`, expedici `planned → confirmed`.
3. Očekávání: události vidět v kalendáři i v mini-sekci na detailu zakázky,
   stavy se vizuálně liší.

## S8 — Reklamace

1. Na dokončené/potvrzené zakázce otevřít reklamaci („Odchlípená hrana dvířek"),
   přidat komentář, uzavřít.
2. Očekávání: reklamace v seznamu, chat funguje, událost otevření/uzavření
   je v chatu zakázky (`tri_job_activity`).

## S9 — Změna kontaktu + fyzická osoba

1. U firemního kontaktu z S1 změnit ulici/číslo popisné. Uložit.
2. Ověřit v MyÚčtu, že se adresa propsala (write-through).
3. Založit kontakt **fyzická osoba** (bez IČO). Založit zakázku se **dvěma** kontakty
   (architekt + zákazník), fakturačním je jen ten s označením Zákazník.
4. Očekávání: úprava adresy v MyÚčtu; zakázka fakturuje na správný kontakt.

## S10 — Regrese celku

1. Druhá zakázka („Vestavěný šatník") zkráceně přes S1→S5: kontakt, zakázka,
   varianta, schválení, záloha, daňový doklad, doplatek.
2. Kontroly: seznam kontaktů i faktur **není prázdný**; editor i detail dokladu
   se otevřou; žádný MyInvoice branding (title, footer, sidebar); core URL
   `/invoices`, `/clients` přesměrují na TRI; storno akce v office neexistuje;
   odeslání e-mailu se korektně přeskočí/ohlásí.
3. Očekávání: vše projde bez oprav kódu.

## Po S10

Jeden **kompletní opakovaný průchod S1–S10** s novými daty. Teprve když projde
celý bez zásahu, scénáře jsou zelené a loop přechází na Polish / mazání mrtvého kódu.
