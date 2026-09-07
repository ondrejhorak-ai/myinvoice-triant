# 19 — MyÚčto integrace: fázové briefy pro implementaci (Grok 4.6)

Architektura a závazná pravidla: [`source/18-myucto-split-architecture.md`](18-myucto-split-architecture.md).
Nasazení stacků: [`source/20-office-deploy-runbook.md`](20-office-deploy-runbook.md).

Každá fáze je samostatně dokončitelná, testovatelná a nasaditelná. Fáze se
dělají **v pořadí**; nezačínej další, dokud předchozí neprošla svou definition
of done. Pracuje se v `/opt/office/repo` (ne v `/opt/myinvoice-triant`).

**Definition of done (každá fáze):**

1. `cd web && pnpm build` projde (pokud se sahalo do `web/src`).
2. Nové migrace přes `php api/bin/migrate.php` (nikdy mysql klientem).
3. `cd api && php vendor/bin/phpunit` — nové testy prochází, staré nerozbité.
4. Deploy `/opt/office/scripts/up.sh` + `curl -fsS https://office.triant.cz/api/health` OK.
5. i18n klíče v obou locale; commit česky (`feat(tri-myucto): …`).

---

## Fáze 1 — Freeze + scaffold `MyUctoClient`

**Cíl:** základní HTTP klient na MyÚčto API + CLI ping. Nic v UI.

- Env klíče (čtené přes `Config`/`getenv` stejně jako ostatní `MYINVOICE_*`):
  `TRI_MYUCTO_ENABLED`, `TRI_MYUCTO_BASE_URL`, `TRI_MYUCTO_PUBLIC_URL`,
  `TRI_MYUCTO_TOKEN`. Doplnit do `cfg.sample.php` komentované sekce.
- `api/src/Tri/MyUcto/MyUctoClient.php` (Guzzle, PHP-DI autowire):
  - hlavička `Authorization: Bearer <token>`, `Accept: application/json`;
  - retry na `429` podle `Retry-After` (max 3 pokusy) a na network error (1 retry);
  - mapování `{error:{code,message}}` → `MyUctoApiException(code, message, httpStatus)`;
  - metody dle §5.1 architektury (zatím stačí: `health`, `apiMe`, `codebooks`,
    `listClients`, `listProjects`, `listInvoices`, `getInvoice`) — zbytek
    doplňují další fáze.
- CLI `api/bin/tri-myucto.php` s příkazy `ping` (health + api-me, vypíše firmu,
  scope a rate-limit hlavičky) a `status` (zatím stub).
- PHPUnit: `api/tests/Tri/MyUcto/MyUctoClientTest.php` s Guzzle `MockHandler` —
  úspěch, error envelope, 429 retry, network timeout.

**Akceptace:** `php api/bin/tri-myucto.php ping` proti běžícímu MyÚčtu vypíše
firmu a verzi; s vypnutým `TRI_MYUCTO_ENABLED` skončí srozumitelnou hláškou;
phpunit zeleně.

## Fáze 2 — Pull zrcadlo (`mu_*` + sync)

**Cíl:** Triant DB obsahuje aktuální zrcadlo klientů, projektů, faktur
a číselníků. Běží paralelně se stávající fakturací (shadow mode, nic nerozbíjí).

- Migrace `9017_tri_myucto_mirror.sql` (idempotentní): `mu_invoices`,
  `mu_projects`, `mu_vat_rates`, `mu_currencies`, `mu_units`,
  `mu_branding_profiles`, `mu_sync_state`, `mu_sync_log`, `mu_commands`;
  `ALTER clients ADD COLUMN IF NOT EXISTS myucto_id/myucto_updated_at/mu_synced_at`;
  `ALTER tri_jobs ADD COLUMN IF NOT EXISTS myucto_project_id`. Sloupce dle §4
  architektury; PK zrcadel = MyÚčto id, žádné AUTO_INCREMENT, žádné FK na core.
- `api/src/Tri/MyUcto/{ClientSync,ProjectSync,InvoiceSync,CodebookSync,SyncRunner}.php`
  dle §5.2 (hot okno 120 dní + drafty; cold 1× denně; upsert podle `updated_at`;
  zmizelé id z hot okna → `deleted_at`).
- `SyncRunner` zapojit do cron mechanismu kontejneru (viz jak běží stávající
  cron úlohy; interval 5 min) + CLI `tri-myucto.php sync [--full]` a `status`
  (vypíše `mu_sync_state`).
- Admin stránka `web/src/pages/tri/admin/MyUctoSync.vue` (`/tri/admin/myucto`):
  stav běhů, poslední chyby z `mu_sync_log`, tlačítko „Synchronizovat teď",
  health MyÚčta. Route + sidebar (Admin sekce) + i18n.
- PHPUnit: upsert logika (nový/změněný/nezměněný záznam, tombstone) s MockHandler.

**Akceptace:** po `tri-myucto.php sync --full` odpovídají počty v `mu_*`
tabulkách datům v MyÚčtu; opakovaný sync je no-op (idempotence); admin stránka
ukazuje zelený stav.

## Fáze 3 — Write-through kontakty

**Cíl:** kontakty se zakládají/upravují z Triantu, master je MyÚčto.

- `api/src/Tri/MyUcto/ContactGateway.php` + akce `POST/PUT /api/tri/contacts`
  (a archive/unarchive): payload → `ClientInput` (pozor `country_iso2`),
  volání MyÚčta, upsert `clients` z odpovědi (vč. `myucto_id`). 4xx
  `validation_failed` → 422 s per-field chybami pro UI; při network chybě 503
  „MyÚčto nedostupné", lokálně nic nezapsat.
- `pages/tri/contacts/*` přepojit na nové endpointy (UX beze změny); u kontaktu
  odkaz „Otevřít v MyÚčtu" (`TRI_MYUCTO_PUBLIC_URL`), viditelný jen adminovi.
- Core client write akce (`Routes.php` — create/update/delete klienta)
  vrátí 410 s odkazem na `/api/tri/contacts` (čtecí endpointy zůstávají).
- ARES lookup: použít `POST /api/v1/clients/lookup-ares` MyÚčta přes gateway
  (nahrazuje lokální ARES v core, pokud ho TRI form používá).
- PHPUnit: gateway (úspěch, validace 4xx, network), mapování `ClientInput`.

**Akceptace:** založení kontaktu v Triantu ho vytvoří v MyÚčtu (vidět v jeho
UI) a lokální řádek má `myucto_id`; nevalidní PSČ vrátí chybu formuláře
a v MyÚčtu nic nevznikne.

## Fáze 4 — Zakázky ↔ projekty + `InvoiceGateway` (koncepty)

**Cíl:** zakázka se zrcadlí jako projekt MyÚčta; koncepty faktur vznikají
z Triantu; TRI faktury čtou zrcadlo. Odstranění `tri_job_invoices`.

- `ProjectGateway::ensureProjectForJob()` dle §5.3; volat při potvrzení zakázky
  + tlačítko v `JobDetail`; při `completed|rejected` → `PUT status=closed`.
- `InvoiceGateway` — zatím: `createDraft`, `updateDraft`, `deleteDraft`,
  `previewVarsymbol`, `pdf`, `getDetail` (GET + upsert zrcadla). Akce pod
  `/api/tri/invoices/*`. `createDraft` přes `mu_commands` (pending → POST →
  done; timeout → `unknown` + reconciliace re-listem draftů klienta/projektu).
- `JobInvoiceBuilder` přepsat dle §5.3 (staví `InvoiceInput`, vat_rate mapping
  přes `mu_vat_rates`, zálohy ze zrcadla) → `createDraft` → vrací id pro redirect.
- `web/src/api/triInvoices.ts`; `InvoiceList` nad zrcadlem;
  `InvoiceEditor` nad `getDetail`/`updateDraft` (jen `status=draft`; pole
  zakázka = výběr `tri_jobs.myucto_project_id`); `JobDetail` panel faktur ze
  zrcadla + tlačítka „Vytvořit zálohu/konečnou fakturu" + „Obnovit z MyÚčta".
- Migrace `9018_tri_drop_job_invoices.sql`: `DROP TABLE IF EXISTS
  tri_job_invoices`; `CREATE TABLE IF NOT EXISTS tri_job_invoice_overrides
  (invoice_id BIGINT UNSIGNED PRIMARY KEY, job_id INT UNSIGNED NOT NULL)`.
- Úklid hooků: `InvoiceRepository` (`tri_columns`/`tri_job_id`/`tri_linked`),
  `TriDemoEnricher` (zápis do `invoices`), `TriJobLinkField` a query
  `tri_job_id` v core `InvoiceEditor.vue`.
- PHPUnit: `JobInvoiceBuilder` (proforma i final, vat mapping, zálohy),
  `mu_commands` stavový automat.

**Akceptace:** ze zakázky vznikne draft v MyÚčtu navázaný na projekt; editor
v Triantu ho umí upravit a smazat; seznam TRI faktur ukazuje zrcadlo; po
timeoutu založení nevznikne duplicitní draft (reconciliace).

## Fáze 5 — Fakturační operace v Triantu

**Cíl:** obchodník celý cyklus (vystavit → odeslat → upomínka → platba) zvládne
z Triantu, bez přístupu do MyÚčta.

- `InvoiceGateway` doplnit: `issue`, `recipients`, `send`, `reminder`,
  `publicLink`, `markPaid`, `payments`, `addPayment`, `deletePayment`,
  `unmarkPaid`, `clone`, `linkAdvance`.
- Role guardy (viz §5.3): `readonly` jen čtení; platby jen `admin`/`accountant`;
  storno/dobropis/mazání vystavených není v gateway vůbec.
- Mapování chyb: uzamčené období a zákonné validace při `issue` (403/409) →
  srozumitelná hláška „období je uzavřeno, kontaktujte účetní" apod.
- `InvoiceDetail.vue`: Vystavit (s `previewVarsymbol`), Odeslat (modal
  s příjemci z `recipients`, cc/bcc, poznámka), Upomínka, veřejný odkaz, sekce
  plateb, Klonovat; vystavená faktura read-only; „Otevřít v MyÚčtu" jen admin.
- Triant activity log: každá operace zapíše reálného uživatele (kdo vystavil,
  odeslal, označil platbu).
- PHPUnit: každá nová gateway metoda (úspěch, 4xx, role guard).

**Akceptace:** testovací faktura projde celým cyklem čistě z Triantu; uživatel
s rolí `readonly` nemůže nic mutovat; druhé kliknutí na Vystavit po refreshi
nezpůsobí chybu ani duplicitu.

## Fáze 6 — Zúžení aplikace

**Cíl:** Triant office ukazuje jen svoje agendy; core fakturační moduly pryč.

- Sidebar/router: viditelné jen TRIANT moduly (zakázky, nabídky, ceníky,
  průvodky, kalendář, reklamace), Kontakty, Faktury (TRI), Admin. Core položky
  (core faktury, banka, nákup, reporty, price-list, OSS…) odstranit
  z `ALLOWED_MODULE_IDS` + `TRI_SIDEBAR_MODULES` + `navSections`; core routy
  z `router/index.ts` odebrat nebo redirect na TRI ekvivalent.
- `RoleMiddleware`: doplnit `/api/tri/*` do allowlistů `accountant`/`readonly`.
- Core invoice write akce → 410 (čtecí nechat kvůli interním službám, pokud je
  něco používá — ověřit grep).
- Dashboard → přehled zakázek (nebo redirect na `/tri/jobs`).
- Projít i18n a odstranit mrtvé odkazy v UI (empty states apod.).

**Akceptace:** obchodník po přihlášení vidí jen TRI agendy; `pnpm build` bez
mrtvých importů; smoke test všech TRI stránek projde; core `/api/invoices`
write vrací 410.

---

## Fáze 7 (runbook, ručně s uživatelem) a 8 (úklid)

Fáze 7 = nasazení stacků dle [`source/20-office-deploy-runbook.md`](20-office-deploy-runbook.md) —
**nedělá se v nočním loopu**. Fáze 8 (smazání mrtvého core kódu) je samostatné
budoucí rozhodnutí, do backlogu nepatří.
