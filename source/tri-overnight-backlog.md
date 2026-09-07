# Noční backlog — UI + ceníky, průvodky, kalendář, reklamace

Živý backlog pro noční loop. Pravidla a rozhodnutí: `[tri-overnight-loop-prompt.md](tri-overnight-loop-prompt.md)`.

Stavy: `[ ]` čeká · `[~]` rozpracováno · `[x]` hotovo. Loop bere první nehotový řez **shora**.
Po dokončení řezu připiš záznam do sekce **Log** dole.

## Řezy

### A. UI foundation (Untitled UI vzhled; primary od G3 = Triant petrolej)

- [x] **A1 — Design tokeny.** V `web/src/styles/main.css` přiblížit tokeny Untitled UI: 4px grid spacing, radius 8/12/16 (`--radius-md/lg/xl`), jemné stíny (shadow-xs/sm jako utility či tokeny), neutral škála čistší/chladnější ve stylu Untitled gray (light i `.dark` varianta), focus ring styl (4px světlý ring v primary). Primary tehdy indigo (nahrazeno v G3). Ověřit, že se nerozpadly existující stránky (bg-neutral-50/200/900, status badge tokeny).
- [x] **A2 — Primitiva.** Do `web/src/components/ui/` přidat `UiButton.vue` (primary/secondary/outline/ghost/danger, sm 36px / md 40px, loading, ikona), `UiBadge.vue` (pill, barevné varianty mapované na status tokeny), `UiPageHeader.vue` (title + subtitle + actions slot, spodní divider), `UiCard.vue`, `UiInput.vue` (label, hint, error, prefix ikona), `UiTable.vue` (hlavička uppercase 12px, řádkový hover, sticky first column přes existující `table-sticky-first`). Vizuálně podle Untitled UI. Nikde je zatím plošně nenasazovat — jen vytvořit + použít na jedné stránce jako pilot (TRI kontakty list).
- [x] **A3 — Shell.** `AppLayout.vue`: sidebar podle Untitled UI (sekce s jemnými popisky, položky 40px, aktivní stav plný pill v primary-50/primary-700, hover neutral-100), topbar vyčistit, page canvas `max-w` + konzistentní padding. `AppShell.vue` (login) sladit. **Zachovat TRI úpravy:** drawer chování na všech šířkách, `TriLogo`, `TriTopbarNav`, skryté přepínače (`SHOW_LOCALE_SWITCHER/SHOW_THEME_TOGGLE`).
- [x] **A4 — Restyle TRI Kontakty** (`pages/tri/contacts/`**): list, detail, form modal — nasadit primitiva z A2, tabulka + page header + empty state podle Untitled.
- [x] **A5 — Restyle TRI Zakázky** (`JobList.vue`, `JobForm.vue`, `JobDetail.vue`): karty, status badge, dvousloupcový detail, chat feed vizuálně sladit.
- [x] **A6 — Restyle editor varianty** (`VariantEditor.vue`, `VariantItemsNotionTable.vue`, `QuoteAdjustmentPanel.vue`): tabulka položek, toolbar, souhrn cen — Untitled table + inputs. Neměnit chování kalkulací.
- [x] **A7 — Restyle TRI Faktury** (`pages/tri/invoices/`**): list, editor, detail, ActionBar.

### B. Ceníky

- [x] **B1 — Model + CRUD.** Migrace `9012_tri_price_lists.sql`: `tri_price_lists` (id, supplier_id, name, note, created_at, updated_at) a `tri_price_list_items` (id, price_list_id FK CASCADE, sort_order, image_id FK na `tri_quote_images`, designation, title, description, default_quantity, unit default 'ks', base_unit_price, vat_rate default 21, `price_updated_at DATETIME NULL`, created_at, updated_at). PHP: `PriceListRepository` + akce (list/get/create/update/delete, items batch save) v `api/src/Tri/`, routy `/api/tri/price-lists...`. `price_updated_at` nastavovat v repository jen při změně `base_unit_price`. FE: `/tri/price-lists` list + editor položek (v duchu `VariantItemsNotionTable`, včetně fotky přes `QuoteLineImageControl` vzor a zobrazení „cena aktualizována"), API typy do `api/tri.ts`, routy, sidebar (AppLayout + `TRI_SIDEBAR_MODULES` + `ALLOWED_MODULE_IDS`), i18n cs+en. Fotky: rozšířit `QuoteImageService` upload endpoint pro ceníky (`POST /api/tri/price-lists/{id}/images`), stejná pravidla (jpeg/png/webp/gif, max 10 MB, resize).
- [x] **B2 — PDF ceníku.** `PriceListPdfRenderer` podle `QuotePdfRenderer` + `api/templates/tri/price_list.twig`, endpoint `GET /api/tri/price-lists/{id}/pdf`, tlačítko v UI. S fotkami, cenami a jednotkami; hlavička dodavatele jako na nabídce.
- [x] **B3 — Picker do nabídek + flag Vyrábíme.** Migrace `9013_tri_line_manufactured.sql`: `is_manufactured TINYINT(1) NOT NULL DEFAULT 1` na `tri_quote_line_items`. Zapojit `catalog_item_id` (FK na `tri_price_list_items`, SET NULL) při vložení z ceníku. FE: v `VariantItemsNotionTable.vue` akce „Vložit z ceníku" (modal se searchem přes ceníky, multi-select, vloží snapshot vč. fotky), checkbox/toggle „Vyrábíme" na řádku. BE: `QuoteRepository` ukládá/čte obě nová pole; `TriQuoteLineItem` v `api/tri.ts` rozšířit. PHPUnit na snapshot mapping.
- [x] **B4 — Picker do faktur.** V TRI `InvoiceEditor.vue` akce „Vložit z ceníku": snapshot do běžných `invoice_items` (description = title + description, unit, quantity = default_quantity, unit_price_without_vat, vat_rate → `vat_rate_id` mapping). Řádek je po vložení normálně editovatelný. Jádro faktur neměnit; jen FE + případný lehký endpoint pro čtení položek ceníku (už existuje z B1).

### C. Průvodky

- [x] **C1 — Model + generování + list/detail.** Migrace `9014_tri_travelers.sql`: `tri_travelers` (id, job_id FK, quote_line_item_id FK SET NULL, number, designation, title, description, quantity, unit, status ENUM('open','done') default 'open', created_at, updated_at) a `tri_traveler_operations` (id, traveler_id FK CASCADE, station ENUM('konstrukce','narezove_centrum','cnc','olepovacka','dyhovani_brouseni','montaz','lakovna','brouseni','baleni'), hours DECIMAL(6,2) NULL, note VARCHAR(255) NULL, UNIQUE(traveler_id, station)). Generování: akce „Vytvořit průvodky" na potvrzené zakázce — z řádků schválené varianty s `is_manufactured=1`, 1 řádek = 1 průvodka, snapshot textů **bez cen**, předvytvořit operations pro všech 9 stanovišť s `hours NULL`. Opakované spuštění: doplní chybějící, nepřepisuje průvodky s vyplněnými hodinami. FE: `/tri/travelers` list (filtr dle zakázky, stav) + detail; sekce Průvodky na `JobDetail.vue`. Sidebar + i18n. PHPUnit na generování (jen manufactured, idempotence).
- [x] **C2 — A4 PDF.** `TravelerPdfRenderer` + `api/templates/tri/traveler.twig`: A4 na výšku, číslo zakázky + zákazník + položka (designation, title, description, množství, fotka pokud je), **bez ceny**, tabulka 9 stanovišť s prázdnými kolonkami datum/hodiny/podpis pro ruční zápis. Endpoint `GET /api/tri/travelers/{id}/pdf` + hromadný tisk všech průvodek zakázky (`GET /api/tri/jobs/{id}/travelers/pdf`).
- [x] **C3 — Zadání hodin + součet na zakázce.** Detail průvodky: formulář hodin po stanovištích (přepis z papíru), uložení `PUT /api/tri/travelers/{id}/operations`. Po vyplnění možnost označit průvodku `done`. `JobDetail.vue`: widget „Odpracované hodiny" — součet za zakázku + rozpad po stanovištích. PHPUnit na součty.

### D. Kalendář

- [x] **D1 — Model + měsíční/týdenní view.** Migrace `9015_tri_calendar.sql`: `tri_calendar_events` (id, supplier_id, calendar ENUM('shifts','dispatch','production'), job_id FK NULL, title, station ENUM('konstrukce','vyroba','kompletace','lakovna','expedice','montaz') NULL — jen pro production, starts_at DATETIME, ends_at DATETIME NULL, all_day TINYINT(1) default 1, status ENUM('planned','confirmed','in_progress','done') default 'planned', note TEXT NULL, created_at, updated_at). BE: `CalendarEventRepository` + CRUD akce, `GET /api/tri/calendar?from&to&calendar=`. FE: `/tri/calendar` — měsíc + týden view (vlastní grid, žádná nová závislost), přepínač tří kalendářů, barevné rozlišení, klik = modal pro vytvoření/editaci, u událostí s `job_id` odkaz na zakázku. Sidebar + i18n.
- [x] **D2 — Stavy plán/skutečnost.** Expedice: přepínání `planned` ↔ `confirmed` (vizuálně odlišit — čárkovaný vs. plný). Výroba: `planned` → tlačítko „Probíhá" (`in_progress`) → `done`. Směny jen `planned`. Na `JobDetail.vue` mini-sekce nadcházejících událostí zakázky (expedice + výroba). Validace: station povinná jen pro production kalendář.

### E. Reklamace

- [x] **E1 — Agenda + chat.** Migrace `9016_tri_complaints.sql`: `tri_complaints` (id, job_id FK, title, description TEXT, status ENUM('open','closed') default 'open', created_at, closed_at DATETIME NULL, updated_at) + `tri_complaint_comments` (id, complaint_id FK CASCADE, user_id, body TEXT, created_at, updated_at NULL). BE: repository + CRUD + komentáře (vzor `JobActivityRepository`), routy `/api/tri/complaints...`. Založení/uzavření reklamace zaloguje event do `tri_job_activity`. FE: `/tri/complaints` list (filtr stav/zakázka) + detail s chatem (vzor `JobActivityFeed.vue`); na `JobDetail.vue` výpis reklamací zakázky s odkazem. Sidebar + i18n. `closed_at` při uzavření, při znovuotevření NULL.

### F. Dokumentace

- [x] **F1 — Manuál.** Nové kapitoly `manual/`: Ceníky, Průvodky, Kalendář, Reklamace (česky, jen aktuální stav) + aktualizace `manual/14_Zakazky.md` (vyrábíme, hodiny, průvodky) a `INDEX.md`. Regenerovat: `php tools/generateManualHtml.php` + `php tools/exportManualToPdf.php`. Zkontrolovat `manual/manual.css` vs. nové tokeny z A1.

### H. MyÚčto integrace (přednost před Polish; záměrně před sekcí G)

Spec: [`18-myucto-split-architecture.md`](18-myucto-split-architecture.md) · Briefy s akceptačními kritérii: [`19-myucto-phase-briefs.md`](19-myucto-phase-briefs.md).
Pozor: tyto řezy se dělají **v `/opt/office/repo`** až po provedení runbooku ([`20-office-deploy-runbook.md`](20-office-deploy-runbook.md)) — dokud stack `/opt/office` neběží, tuto sekci přeskoč a ber Polish.

- [x] **H1 — Freeze + scaffold `MyUctoClient`.** Env klíče, Guzzle klient s retry/error mapping, CLI `tri-myucto.php ping`, PHPUnit s MockHandler. Brief: fáze 1.
- [x] **H2 — Pull zrcadlo.** Migrace `9017` (`mu_*` + sloupce na `clients`/`tri_jobs`), `ClientSync/ProjectSync/InvoiceSync/CodebookSync`, `SyncRunner` + cron 5 min, admin stránka MyÚčto synchronizace. Brief: fáze 2.
- [x] **H3 — Write-through kontakty.** `ContactGateway`, přepojení TRI kontaktů, core client write → 410, ARES přes MyÚčto. Brief: fáze 3.
- [x] **H4 — Zakázky ↔ projekty + koncepty faktur.** `ProjectGateway`, `InvoiceGateway` (draft část), nový `JobInvoiceBuilder`, `mu_commands`, `triInvoices.ts`, editor/list nad zrcadlem, migrace `9018` (drop `tri_job_invoices`), úklid core hooků. Brief: fáze 4.
- [ ] **H5 — Fakturační operace.** `issue`/`send`/`reminder`/`publicLink`/platby/`clone` v gateway + `InvoiceDetail` akce, role guardy, mapování chyb, activity log. Brief: fáze 5.
- [ ] **H6 — Zúžení aplikace.** Sidebar/router jen TRI + Kontakty + Faktury + Admin, `RoleMiddleware` allowlist `/api/tri/*`, core invoice write → 410, dashboard → zakázky. Brief: fáze 6.

### G. Polish (nekonečná sekce — když je vše výše hotové, přidávej a ber odsud)

- [x] **G1 — Restyle core Faktury** (`pages/invoices/`**) do Untitled vzhledu.

- [x] **G2 — Restyle Dashboard, Klienti, Dokumenty.**

- [ ] **G3 — Triant brand primary.** Nahradit MyInvoice indigo `#3B2D83` Triant petrolejem z PDF nabídky. **Škála (povinná, neimprovizovat):** viz produktové rozhodnutí v loop promptu (`50 #EAF1F5` … `700 #0B4F7C` … `900 #062C45`). Warning-500 přiblížit jantaru nabídky `#F59B00` (warning-50 nechat měkký tint). Success/danger beze změny. Untitled gray / radius / stíny / Inter neměnit.
  - `web/src/styles/main.css`: celá `--color-primary-*` light + `.dark`, `--color-ring` / `--shadow-focus-ring` na nový `primary-100`, `--color-status-issued-*` na primary-50/700 (light i dark).
  - `manual/manual.css` + `manual/index.php` `theme-color`.
  - PHP fallback jedním místem: `AccentColor::DEFAULT` → `#0B4F7C` a všechny `: '#3B2D83'` fallbacky (Settings, Mailer, e-mail layout, public work-report, OpenAPI docs CSS, `client.ts` error page). I18n hint v Settings (cs+en) — už ne „fialová MyInvoice“.
  - Grafy: `useTheme.ts` `CHART_PALETTE_*`, `InvoiceSizeChart.vue`.
  - PDF faktury **jen výměna hexu** `#3B2D83` → `#0B4F7C` v `styles/invoice.css` + hardcoded v `work_report.twig` / `PurchaseInvoicePdfRenderer` / `PdfBranding` komentáře. `InvoicePdfRenderer` override: default teď znamená nový hex (generovat CSS jen když se supplier accent liší od `#0B4F7C`). **Náležitosti dokladu, layout, DPH — neměnit.**
  - `source/05-design.md`: primary paleta = Triant škála (odstranit emerald i indigo jako brand).
  - PHPUnit `AccentColorTest` a cokoli assertuje starý default. `pnpm build`, health, commit `ui(tri): Triant petrolej místo MyInvoice indigo`.
- [ ] **G4 — Restyle zbývajících core stránek** (banka, výkazy, admin, recurring…) — po menších dávkách, jedna oblast na tick. Už na nové primary.
- [ ] **G5 — PDF nabídky:** neměnit. Je zdroj brand barev (`#0B4F7C`, `#EAF1F5`, `#F59B00`).
- [ ] **G6 — PDF faktury polish:** po G3 zkontrolovat vizuál (hlavička, tabulka, k úhradě). Když zbydou indigo ostrůvky nebo rozbitý branding override, opravit. Nesahej na náležitosti dokladu.
- [ ] **G7 — Mobile pass:** karty/tabulky nových agend na malých šířkách.
- [ ] **G8 — Empty states + loading skeletony** všech nových agend.
- [ ] **G9 — Dark mode pass** nových agend a restylovaných stránek (vč. nové primary v `.dark`).

- Sem zapisuj nově nalezené bugy a follow-upy jako další `G` řádky.

## Log

- **2026-08-13 21:47 A1** — Untitled gray neutrals (light + inverted `.dark`), radius md/lg/xl = 8/12/16, `shadow-xs/sm/md/lg`, utility `.focus-ring` (4px primary wash). Primary indigo beze změny. Status badge tokeny sladěny s novou škálou (vč. dark). `manual/manual.css` zrcadlí barvy. Commit: `ui(tri): Untitled UI design tokeny (A1)`.
- **2026-08-13 21:52 A2** — Primitiva `UiButton/Badge/PageHeader/Card/Input/Table` v `web/src/components/ui/`. Pilot jen na TRI seznam kontaktů (header, search, tabulka, badge, load more). Ostatní stránky beze změny.
- **2026-08-13 21:56 A3** — Shell: sidebar 40px pill (active primary-50/700), jemná sekční popiska, topbar vyčištěný, canvas `max-w-7xl`. Drawer / TriLogo / TriTopbarNav / skryté toggle zachovány. AppShell + login karta sladěny.
- **2026-08-13 22:05 A4** — Restyle TRI kontakty (list/detail/form) na A2 primitiva: UiPageHeader, UiCard, UiTable, UiButton, UiInput, EmptyState. KPI/grafy na detailu jen shadow-xs. Embedded ContactForm (JobForm modal) zachován.
- **2026-08-14 07:00 A5** — Restyle TRI zakázky: list (header/table/badge/empty), form (card + inputs), dvousloupcový detail (varianty + faktury v kartách), chat feed na UiCard/UiButton. Dvou-sloupcový layout zachován.
- **2026-08-14 07:05 A6** — Restyle editor varianty: UiCard/UiButton, focus-ring na ghost inputs, Untitled hlavička tabulky (notion i classic), panel slev/provizí. Kalkulace beze změny.
- **2026-08-14 07:10 A7** — Restyle TRI faktury: list (header, filtry, empty, load more), editor (header, položky, save), detail (page header + akce), ActionBar (shadow-xs, focus-ring, dropdown).
- **2026-08-14 07:25 B1** — Ceníky CRUD: migrace `9012`, `PriceListRepository` + akce/routy `/api/tri/price-lists`, FE list+editor (tabulka v duchu VariantItemsNotionTable, fotky přes QuoteLineImageControl, `price_updated_at` jen při změně ceny), sidebar, i18n cs+en. PHPUnit `PriceListPricingTest`.
- **2026-08-14 07:30 B2** — PDF ceníku: `PriceListPdfRenderer` + `price_list.twig` (hlavička dodavatele jako nabídka, fotky, ceny, jednotky, DPH souhrn), `GET /api/tri/price-lists/{id}/pdf`, tlačítko v editoru. PHPUnit `PriceListPdfRendererTest`.
- **2026-08-14 07:33 B3** — Picker ceníku do nabídek: migrace `9013` (`is_manufactured` + FK `catalog_item_id`), `GET /api/tri/catalog-items`, `CatalogSnapshot` + modal multi-select, checkbox Vyrábíme (notion i classic). Snapshot kopíruje pole vč. fotky, default Vyrábíme=ano. PHPUnit `CatalogSnapshotTest`.
- **2026-08-14 07:36 B4** — Picker ceníku do TRI faktur: snapshot do `invoice_items` (popis = označení+název+popis, množství, jednotka, cena bez DPH, DPH % → `vat_rate_id`). Jádro faktur beze změny. PHPUnit `CatalogSnapshot::toInvoiceLine` + `mapVatRateId`.
- **2026-08-14 07:52 C1** — Průvodky: migrace `9014`, `TravelerGenerator` (jen `is_manufactured`, 9 stanovišť, idempotence bez přepisu), list `/tri/travelers` + detail, sekce na zakázce, sidebar. Generování jen confirmed/completed se schválenou variantou. PHPUnit `TravelerGeneratorTest`.
- **2026-08-14 10:50 C2** — A4 PDF průvodky (portrait): `TravelerPdfRenderer` + `traveler.twig`, bez cen, fotka z řádku nabídky, 9 prázdných kolonek datum/hodiny/podpis. `GET /api/tri/travelers/{id}/pdf` a hromadný `GET /api/tri/jobs/{id}/travelers/pdf`. Tlačítka na detailu a na zakázce. PHPUnit `TravelerPdfRendererTest`.
- **2026-08-14 10:55 C3** — Hodiny na průvodce: `PUT /api/tri/travelers/{id}/operations`, přepis z papíru, označení done/open. Widget součtu na zakázce + rozpad po stanovištích. PHPUnit `TravelerHoursTest`.
- **2026-08-14 11:05 D1** — Kalendář: migrace `9015`, CRUD `/api/tri/calendar`, měsíc+týden grid (Po–Ne), tři kalendáře (směny/expedice/výroba), stanice jen u výroby (jiná sada než průvodka). Sidebar. PHPUnit `CalendarEventRulesTest`.
- **2026-08-14 11:10 D2** — Stavy kalendáře: expedice planned↔confirmed (čárkovaný vs. plný), výroba planned→in_progress→done, směny jen planned. Mini-sekce nadcházejících termínů na zakázce (`GET /api/tri/jobs/{id}/calendar`). PHPUnit přechody stavů.
- **2026-08-14 13:50 E1** — Reklamace: migrace `9016`, CRUD + chat `/api/tri/complaints`, eventy do `tri_job_activity` (opened/closed/reopened). FE list+detail s chatem, sekce na zakázce, sidebar. PHPUnit `ComplaintRulesTest`. `CzkRecapTest` (jádro DPH) padá 511.66 vs 511.67 — mimo řez, neopraveno.
- **2026-08-14 13:52 F1** — Manuál: kapitoly 42–45 (Ceníky, Průvodky, Kalendář, Reklamace), `14_Zakazky.md` § 14.10 (Vyrábíme, hodiny, odkazy), INDEX sekce TRIANT. HTML+PDF regenerováno. `manual/manual.css` už zrcadlí A1 tokeny.
- **2026-08-14 14:05 G1** — Restyle core faktur (`InvoiceList/Detail/Editor`): UiPageHeader, UiButton, UiCard, UiInput, UiBadge, ActionBar v headeru detailu, shadow-xs, Untitled caption/thead. Chování faktur/DPH beze změny.
- **2026-08-14 14:15 G2** — Restyle Dashboard, Klienti (list/detail/form) a Dokumenty (browser/detail): primitiva A2, shadow-xs, Untitled caption/thead. KPI čísla a chování beze změny.
- **2026-08-14 20:55 plán** — Brand decision změněn: MyInvoice indigo `#3B2D83` → Triant `#0B4F7C` (PDF nabídky). Loop prompt odemčen. Nový první řez **G3**; původní G3–G8 posunuté na G4–G9. Implementace až v G3, ne v tomto zápisu.
- **2026-09-07 H1** — `MyUctoClient` (Guzzle, 429 retry, error envelope), env `TRI_MYUCTO_*`, CLI `tri-myucto.php ping|status|sync|contract`. PHPUnit MockHandler. Ping proti běžícímu MyÚčtu po deployi.
- **2026-09-07 H2** — Migrace `9017` (`mu_*` + `clients.myucto_id` + `tri_jobs.myucto_project_id`), pull sync (hot 120 dní + drafty, cold 1× denně, tombstone), cron `*/5`, admin `/tri/admin/myucto`. PHPUnit upsert/tombstone na SQLite. `CzkRecapTest` a `ClientValidationTest` padají stejně jako dřív (mimo řez).
- **2026-09-07 H3** — `ContactGateway` write-through (create/update/archive + ARES), TRI formuláře na `/api/tri/contacts`, core client write → 410. `RoleMiddleware` pouští `/api/tri/*` účetní/readonly GET. Sloupec `mu_sync_state.sync_cursor` (MariaDB rezervované `cursor`).
- **2026-09-07 H4** — `ProjectGateway` (zakázka → projekt MyÚčta při potvrzení), `InvoiceGateway` koncepty + `mu_commands` (timeout → reconciliace), `JobInvoiceBuilder` staví InvoiceInput, list/editor nad zrcadlem `mu_invoices`, migrace `9018` drop `tri_job_invoices`. PHPUnit InvoiceGateway + builder. Vystavení/odeslání/platby až H5.

