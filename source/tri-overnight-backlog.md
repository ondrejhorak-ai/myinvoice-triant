# Noční backlog — UI + ceníky, průvodky, kalendář, reklamace

Živý backlog pro noční loop. Pravidla a rozhodnutí: [`tri-overnight-loop-prompt.md`](tri-overnight-loop-prompt.md).

Stavy: `[ ]` čeká · `[~]` rozpracováno · `[x]` hotovo. Loop bere první nehotový řez **shora**.
Po dokončení řezu připiš záznam do sekce **Log** dole.

## Řezy

### A. UI foundation (Untitled UI vzhled, brand indigo zůstává)

- [x] **A1 — Design tokeny.** V `web/src/styles/main.css` přiblížit tokeny Untitled UI: 4px grid spacing, radius 8/12/16 (`--radius-md/lg/xl`), jemné stíny (shadow-xs/sm jako utility či tokeny), neutral škála čistší/chladnější ve stylu Untitled gray (light i `.dark` varianta), focus ring styl (4px světlý ring v primary). Primary indigo škálu zachovat. Ověřit, že se nerozpadly existující stránky (bg-neutral-50/200/900, status badge tokeny).
- [x] **A2 — Primitiva.** Do `web/src/components/ui/` přidat `UiButton.vue` (primary/secondary/outline/ghost/danger, sm 36px / md 40px, loading, ikona), `UiBadge.vue` (pill, barevné varianty mapované na status tokeny), `UiPageHeader.vue` (title + subtitle + actions slot, spodní divider), `UiCard.vue`, `UiInput.vue` (label, hint, error, prefix ikona), `UiTable.vue` (hlavička uppercase 12px, řádkový hover, sticky first column přes existující `table-sticky-first`). Vizuálně podle Untitled UI. Nikde je zatím plošně nenasazovat — jen vytvořit + použít na jedné stránce jako pilot (TRI kontakty list).
- [x] **A3 — Shell.** `AppLayout.vue`: sidebar podle Untitled UI (sekce s jemnými popisky, položky 40px, aktivní stav plný pill v primary-50/primary-700, hover neutral-100), topbar vyčistit, page canvas `max-w` + konzistentní padding. `AppShell.vue` (login) sladit. **Zachovat TRI úpravy:** drawer chování na všech šířkách, `TriLogo`, `TriTopbarNav`, skryté přepínače (`SHOW_LOCALE_SWITCHER/SHOW_THEME_TOGGLE`).
- [x] **A4 — Restyle TRI Kontakty** (`pages/tri/contacts/**`): list, detail, form modal — nasadit primitiva z A2, tabulka + page header + empty state podle Untitled.
- [x] **A5 — Restyle TRI Zakázky** (`JobList.vue`, `JobForm.vue`, `JobDetail.vue`): karty, status badge, dvousloupcový detail, chat feed vizuálně sladit.
- [ ] **A6 — Restyle editor varianty** (`VariantEditor.vue`, `VariantItemsNotionTable.vue`, `QuoteAdjustmentPanel.vue`): tabulka položek, toolbar, souhrn cen — Untitled table + inputs. Neměnit chování kalkulací.
- [ ] **A7 — Restyle TRI Faktury** (`pages/tri/invoices/**`): list, editor, detail, ActionBar.

### B. Ceníky

- [ ] **B1 — Model + CRUD.** Migrace `9012_tri_price_lists.sql`: `tri_price_lists` (id, supplier_id, name, note, created_at, updated_at) a `tri_price_list_items` (id, price_list_id FK CASCADE, sort_order, image_id FK na `tri_quote_images`, designation, title, description, default_quantity, unit default 'ks', base_unit_price, vat_rate default 21, `price_updated_at DATETIME NULL`, created_at, updated_at). PHP: `PriceListRepository` + akce (list/get/create/update/delete, items batch save) v `api/src/Tri/`, routy `/api/tri/price-lists...`. `price_updated_at` nastavovat v repository jen při změně `base_unit_price`. FE: `/tri/price-lists` list + editor položek (v duchu `VariantItemsNotionTable`, včetně fotky přes `QuoteLineImageControl` vzor a zobrazení „cena aktualizována"), API typy do `api/tri.ts`, routy, sidebar (AppLayout + `TRI_SIDEBAR_MODULES` + `ALLOWED_MODULE_IDS`), i18n cs+en. Fotky: rozšířit `QuoteImageService` upload endpoint pro ceníky (`POST /api/tri/price-lists/{id}/images`), stejná pravidla (jpeg/png/webp/gif, max 10 MB, resize).
- [ ] **B2 — PDF ceníku.** `PriceListPdfRenderer` podle `QuotePdfRenderer` + `api/templates/tri/price_list.twig`, endpoint `GET /api/tri/price-lists/{id}/pdf`, tlačítko v UI. S fotkami, cenami a jednotkami; hlavička dodavatele jako na nabídce.
- [ ] **B3 — Picker do nabídek + flag Vyrábíme.** Migrace `9013_tri_line_manufactured.sql`: `is_manufactured TINYINT(1) NOT NULL DEFAULT 1` na `tri_quote_line_items`. Zapojit `catalog_item_id` (FK na `tri_price_list_items`, SET NULL) při vložení z ceníku. FE: v `VariantItemsNotionTable.vue` akce „Vložit z ceníku" (modal se searchem přes ceníky, multi-select, vloží snapshot vč. fotky), checkbox/toggle „Vyrábíme" na řádku. BE: `QuoteRepository` ukládá/čte obě nová pole; `TriQuoteLineItem` v `api/tri.ts` rozšířit. PHPUnit na snapshot mapping.
- [ ] **B4 — Picker do faktur.** V TRI `InvoiceEditor.vue` akce „Vložit z ceníku": snapshot do běžných `invoice_items` (description = title + description, unit, quantity = default_quantity, unit_price_without_vat, vat_rate → `vat_rate_id` mapping). Řádek je po vložení normálně editovatelný. Jádro faktur neměnit; jen FE + případný lehký endpoint pro čtení položek ceníku (už existuje z B1).

### C. Průvodky

- [ ] **C1 — Model + generování + list/detail.** Migrace `9014_tri_travelers.sql`: `tri_travelers` (id, job_id FK, quote_line_item_id FK SET NULL, number, designation, title, description, quantity, unit, status ENUM('open','done') default 'open', created_at, updated_at) a `tri_traveler_operations` (id, traveler_id FK CASCADE, station ENUM('konstrukce','narezove_centrum','cnc','olepovacka','dyhovani_brouseni','montaz','lakovna','brouseni','baleni'), hours DECIMAL(6,2) NULL, note VARCHAR(255) NULL, UNIQUE(traveler_id, station)). Generování: akce „Vytvořit průvodky" na potvrzené zakázce — z řádků schválené varianty s `is_manufactured=1`, 1 řádek = 1 průvodka, snapshot textů **bez cen**, předvytvořit operations pro všech 9 stanovišť s `hours NULL`. Opakované spuštění: doplní chybějící, nepřepisuje průvodky s vyplněnými hodinami. FE: `/tri/travelers` list (filtr dle zakázky, stav) + detail; sekce Průvodky na `JobDetail.vue`. Sidebar + i18n. PHPUnit na generování (jen manufactured, idempotence).
- [ ] **C2 — A4 PDF.** `TravelerPdfRenderer` + `api/templates/tri/traveler.twig`: A4 na výšku, číslo zakázky + zákazník + položka (designation, title, description, množství, fotka pokud je), **bez ceny**, tabulka 9 stanovišť s prázdnými kolonkami datum/hodiny/podpis pro ruční zápis. Endpoint `GET /api/tri/travelers/{id}/pdf` + hromadný tisk všech průvodek zakázky (`GET /api/tri/jobs/{id}/travelers/pdf`).
- [ ] **C3 — Zadání hodin + součet na zakázce.** Detail průvodky: formulář hodin po stanovištích (přepis z papíru), uložení `PUT /api/tri/travelers/{id}/operations`. Po vyplnění možnost označit průvodku `done`. `JobDetail.vue`: widget „Odpracované hodiny" — součet za zakázku + rozpad po stanovištích. PHPUnit na součty.

### D. Kalendář

- [ ] **D1 — Model + měsíční/týdenní view.** Migrace `9015_tri_calendar.sql`: `tri_calendar_events` (id, supplier_id, calendar ENUM('shifts','dispatch','production'), job_id FK NULL, title, station ENUM('konstrukce','vyroba','kompletace','lakovna','expedice','montaz') NULL — jen pro production, starts_at DATETIME, ends_at DATETIME NULL, all_day TINYINT(1) default 1, status ENUM('planned','confirmed','in_progress','done') default 'planned', note TEXT NULL, created_at, updated_at). BE: `CalendarEventRepository` + CRUD akce, `GET /api/tri/calendar?from&to&calendar=`. FE: `/tri/calendar` — měsíc + týden view (vlastní grid, žádná nová závislost), přepínač tří kalendářů, barevné rozlišení, klik = modal pro vytvoření/editaci, u událostí s `job_id` odkaz na zakázku. Sidebar + i18n.
- [ ] **D2 — Stavy plán/skutečnost.** Expedice: přepínání `planned` ↔ `confirmed` (vizuálně odlišit — čárkovaný vs. plný). Výroba: `planned` → tlačítko „Probíhá" (`in_progress`) → `done`. Směny jen `planned`. Na `JobDetail.vue` mini-sekce nadcházejících událostí zakázky (expedice + výroba). Validace: station povinná jen pro production kalendář.

### E. Reklamace

- [ ] **E1 — Agenda + chat.** Migrace `9016_tri_complaints.sql`: `tri_complaints` (id, job_id FK, title, description TEXT, status ENUM('open','closed') default 'open', created_at, closed_at DATETIME NULL, updated_at) + `tri_complaint_comments` (id, complaint_id FK CASCADE, user_id, body TEXT, created_at, updated_at NULL). BE: repository + CRUD + komentáře (vzor `JobActivityRepository`), routy `/api/tri/complaints...`. Založení/uzavření reklamace zaloguje event do `tri_job_activity`. FE: `/tri/complaints` list (filtr stav/zakázka) + detail s chatem (vzor `JobActivityFeed.vue`); na `JobDetail.vue` výpis reklamací zakázky s odkazem. Sidebar + i18n. `closed_at` při uzavření, při znovuotevření NULL.

### F. Dokumentace

- [ ] **F1 — Manuál.** Nové kapitoly `manual/`: Ceníky, Průvodky, Kalendář, Reklamace (česky, jen aktuální stav) + aktualizace `manual/14_Zakazky.md` (vyrábíme, hodiny, průvodky) a `INDEX.md`. Regenerovat: `php tools/generateManualHtml.php` + `php tools/exportManualToPdf.php`. Zkontrolovat `manual/manual.css` vs. nové tokeny z A1.

### G. Polish (nekonečná sekce — když je vše výše hotové, přidávej a ber odsud)

- [ ] **G1 — Restyle core Faktury** (`pages/invoices/**`) do Untitled vzhledu.
- [ ] **G2 — Restyle Dashboard, Klienti, Dokumenty.**
- [ ] **G3 — Restyle zbývajících core stránek** (banka, výkazy, admin, recurring…) — po menších dávkách, jedna oblast na tick.
- [ ] **G4 — PDF nabídky:** sladit `styles/quote.css` s novým vizuálem (typografie, hlavička).
- [ ] **G5 — PDF faktury:** opatrně sladit `styles/invoice.css` (nezasahovat do náležitostí dokladu).
- [ ] **G6 — Mobile pass:** karty/tabulky nových agend na malých šířkách.
- [ ] **G7 — Empty states + loading skeletony** všech nových agend.
- [ ] **G8 — Dark mode pass** nových agend a restylovaných stránek.
- Sem zapisuj nově nalezené bugy a follow-upy jako další `G` řádky.

## Log

- **2026-08-13 21:47 A1** — Untitled gray neutrals (light + inverted `.dark`), radius md/lg/xl = 8/12/16, `shadow-xs/sm/md/lg`, utility `.focus-ring` (4px primary wash). Primary indigo beze změny. Status badge tokeny sladěny s novou škálou (vč. dark). `manual/manual.css` zrcadlí barvy. Commit: `ui(tri): Untitled UI design tokeny (A1)`.
- **2026-08-13 21:52 A2** — Primitiva `UiButton/Badge/PageHeader/Card/Input/Table` v `web/src/components/ui/`. Pilot jen na TRI seznam kontaktů (header, search, tabulka, badge, load more). Ostatní stránky beze změny.
- **2026-08-13 21:56 A3** — Shell: sidebar 40px pill (active primary-50/700), jemná sekční popiska, topbar vyčištěný, canvas `max-w-7xl`. Drawer / TriLogo / TriTopbarNav / skryté toggle zachovány. AppShell + login karta sladěny.
- **2026-08-13 22:05 A4** — Restyle TRI kontakty (list/detail/form) na A2 primitiva: UiPageHeader, UiCard, UiTable, UiButton, UiInput, EmptyState. KPI/grafy na detailu jen shadow-xs. Embedded ContactForm (JobForm modal) zachován.
- **2026-08-14 07:00 A5** — Restyle TRI zakázky: list (header/table/badge/empty), form (card + inputs), dvousloupcový detail (varianty + faktury v kartách), chat feed na UiCard/UiButton. Dvou-sloupcový layout zachován.
