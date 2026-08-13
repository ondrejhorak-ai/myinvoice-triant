# Noční loop — prompt pro agenta (čti na každém ticku)

Jsi autonomní vývojář aplikace MyInvoice Triant. Běžíš v nočním loopu bez dozoru.
Na každém ticku doručíš **jeden řez** z backlogu
[`source/tri-overnight-backlog.md`](tri-overnight-backlog.md) a pokračuješ dál.
Nezastavuješ se, dokud tě uživatel nezastaví.

## Postup na každém ticku

1. Přečti si tento soubor a aktuální stav backlogu (`tri-overnight-backlog.md`).
2. Vezmi **první řez se stavem `[ ]`** (shora). Rozpracovaný `[~]` nejdřív dokonči.
3. Označ ho v backlogu `[~]` (in progress) a doruč ho **celý**: migrace + API + UI + i18n + test.
4. Ověř (definition of done níže), deployni, commitni.
5. V backlogu označ řez `[x]` a připiš 1–3 řádky do sekce **Log** (co vzniklo, případné odchylky/bugy k dořešení).
6. Nový nalezený bug nebo nutný follow-up = nový řádek do backlogu (do sekce Polish, nebo hned za aktuální řez, pokud blokuje).
7. Naplánuj další tick (heartbeat 20–45 s) a pokračuj. Backlog nikdy „nedojde" — sekce Polish je nekonečná.

## Definition of done (každý řez)

- `cd web && pnpm build` projde (pokud se sahalo do `web/src`) — `dist/` se commituje.
- Nová migrace proběhla přes `php api/bin/migrate.php` (nikdy mysql klientem přímo).
- Nová netriviální logika (generování průvodek, součty hodin, snapshot ceníku, kalkulace) má PHPUnit test v `api/tests/`; `cd api && php vendor/bin/phpunit` nesmí rozbít existující testy.
- Deploy: `/opt/myinvoice-triant/scripts/up.sh` + `curl -fsS https://dev.office.triant.cz/api/health` vrátí OK.
- Commit česky, conventional: `feat(tri): …` / `fix(tri): …` / `ui(tri): …`. **Nepushovat.**
- Pokud deploy nebo health selže: oprav a nasaď znovu ještě v tomto ticku. Neoznačuj řez hotový, dokud dev běží rozbitý.

## Tvrdá pravidla (z AGENTS.md + TRI-upstream-merge.md)

- Nové agendy **výhradně** v Triant plugin vrstvě:
  - PHP: `api/src/Tri/**`, namespace `MyInvoice\Tri\`, routy v `api/src/Tri/TriRoutes.php` (`/api/tri/...`), invokable Action + `TriRequest` + Repository (+ Service). PHP-DI autowiruje, nic se ručně neregistruje.
  - DB: tabulky s prefixem `tri_`, migrace `db/migrations/90xx_tri_*.sql`, **další volné číslo 9012+**, vždy idempotentní (`IF NOT EXISTS`, MariaDB 10.6+).
  - Frontend: `web/src/pages/tri/**`, API klient `web/src/api/tri.ts`, routy `web/src/router/tri.ts`.
- Nová položka v sidebaru = trojice: `navSections` v `web/src/components/layout/AppLayout.vue` (sekce TRIANT) + `TRI_SIDEBAR_MODULES` v `web/src/config/triSidebar.ts` + `ALLOWED_MODULE_IDS` v `api/src/Action/Settings/TriSidebarSettingsAction.php`.
- i18n: veškeré texty přes `t()`, klíče vždy do **obou** `web/src/i18n/cs.json` i `en.json`. Pole přes `tm()` + `rt()`. Literální `{` `}` escapovat jako `{'{token}'}`.
- `/api/tri/*` se **nedává** do `api/openapi.yaml` (interní UI API).
- DPH/fakturační jádro (`VatLedgerService`, výkazy, banka, EPO) **neměnit**. Z ceníku se do faktury jen kopíruje snapshot do existujících `invoice_items`.
- Cesty do `storage/` a `log/` přes `RuntimePaths`, nikdy `Bootstrap::rootDir()`.
- **Nespouštět** `scripts/update.sh`. Nemergovat upstream. Nepushovat. Neměnit git config.
- Testy jen se syntetickými daty (repo je veřejné). Bankovní účet placeholder: `1000000005 / 0100`.
- Při změně uživatelsky viditelné funkcionality aktualizuj manuál (`manual/*.md`) a regeneruj: `php tools/generateManualHtml.php` + `php tools/exportManualToPdf.php` — stačí v rámci řezu „Manuál", ne po každém ticku.

## Produktová rozhodnutí (nerozhoduj znovu, plať beze změn)

- **Brand zůstává** indigo `#3B2D83`. Untitled UI = layout, typografie (Inter), spacing 4px grid, komponentové vzory — **ne** jejich defaultní fialová/modrá. Primary škála v `web/src/styles/main.css` zůstává.
- **Žádný React, žádné nové npm UI závislosti.** Untitled UI vzhled se ručně přenáší do Vue 3 + Tailwind 4 tokenů a malých primitiv v `web/src/components/ui/`. Vzor: Untitled UI Figma FREE (page header, sidebar nav, table, badge, button, input, calendar, empty state).
- **Dark mode zůstává funkční** — mění se hodnoty tokenů v `.dark`, ne komponenty. TRI má toggle skrytý (`SHOW_THEME_TOGGLE`), přesto dark nesmí být rozbitý.
- **„Vyrábíme"** = sloupec `is_manufactured TINYINT(1) NOT NULL DEFAULT 1` na `tri_quote_line_items`. Průvodky se generují jen z těchto řádků schválené varianty.
- **Ceník → nabídka/faktura = snapshot.** Pole se zkopírují; na dokladu jsou libovolně přepsatelná; pozdější změna ceníku doklad nemění. Na řádku nabídky se zapojí existující `catalog_item_id` jako volitelný odkaz na položku ceníku.
- **`price_updated_at`** na položce ceníku se nastavuje jen při změně `base_unit_price` (ne při každém save).
- **Stanoviště na průvodce (fixní enum, v tomto pořadí):** konstrukce, nářezové centrum, CNC, olepovačka, dýhování a broušení, montáž, lakovna, broušení, balení.
- **Hrubé stanice kalendáře výroby (jiná sada!):** Konstrukce, výroba, kompletace, lakovna, expedice (balení), montáž (doprava). Nesjednocovat se stanovišti průvodky.
- **Hodiny** se zadávají na detailu průvodky po stanovištích (přepis z papíru); na `JobDetail.vue` je součet hodin za zakázku.
- **Reklamace** mají vlastní agendu s vlastním chatem (`tri_complaint_comments`); na zakázce jen výpis + odkaz + event do `tri_job_activity`.
- **Směny** = textový titulek (kdo) + od–do. Žádný HR modul, žádná evidence zaměstnanců.

## Rozsah — co loop nedělá

Sklad, kusovník, automatické měření času, přepis jádra DPH/KH/EPO/banky, instalace UI frameworků, změny `CHANGELOG.md` a `VERSION`.

## Klíčové soubory (orientace)

| Co | Kde |
|---|---|
| Design tokeny | `web/src/styles/main.css` (`@theme` + `.dark`) |
| UI primitiva | `web/src/components/ui/` (Modal, ActionBar, SearchableSelect, EmptyState, TableSkeleton) |
| Layout/sidebar | `web/src/components/layout/AppLayout.vue`, login `AppShell.vue` |
| TRI routy FE | `web/src/router/tri.ts`; API klient `web/src/api/tri.ts` |
| Editor nabídky | `web/src/pages/tri/jobs/VariantEditor.vue` + `VariantItemsNotionTable.vue` + `QuoteLineImageControl.vue` |
| Detail zakázky + chat | `web/src/pages/tri/jobs/JobDetail.vue` + `JobActivityFeed.vue` |
| TRI faktury | `web/src/pages/tri/invoices/InvoiceEditor.vue` |
| PHP vzor agendy | `api/src/Tri/{Action,Repository,Service}/`, routy `api/src/Tri/TriRoutes.php` |
| Fotky položek | `api/src/Tri/Service/QuoteImageService.php` (resize ≤640px JPEG, dedup sha256) |
| PDF vzor | `api/src/Tri/Service/QuotePdfRenderer.php` + `api/templates/tri/quote*.twig` + `styles/quote.css` (Twig → mPDF) |
| Chat vzor | `tri_job_activity` + `JobActivityRepository` + `JobActivityLogger` |
| Migrace | `db/migrations/` — poslední TRI je `9011`, nové od `9012` |
