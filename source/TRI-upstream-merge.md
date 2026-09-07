# TRIANT plugin — upstream merge workflow

Fork: [ondrejhorak-ai/myinvoice-triant](https://github.com/ondrejhorak-ai/myinvoice-triant)  
Upstream: [radekhulan/myinvoice](https://github.com/radekhulan/myinvoice)

## Isolation rules

| Area | Convention |
|------|------------|
| PHP code | `api/src/Tri/**` namespace `MyInvoice\Tri\` |
| DB tables | prefix `tri_` |
| Migrations | `db/migrations/9000_tri_*.sql` (always after upstream `0xxx`) |
| Frontend | `web/src/pages/tri/**`, `web/src/api/tri.ts`, `web/src/router/tri.ts` |

## The only 3 upstream hook lines

After each upstream merge, re-apply if conflicts removed them:

1. [api/src/Routes.php](../api/src/Routes.php) — end of `Routes::register()`:
   ```php
   \MyInvoice\Tri\TriRoutes::register($app);
   ```

2. [web/src/router/index.ts](../web/src/router/index.ts) — import `triRoutes` and `...triRoutes` in AppLayout children.

3. [web/src/components/layout/AppLayout.vue](../web/src/components/layout/AppLayout.vue) — TRIANT nav section + [TriSidebarSettingsAction.php](../api/src/Action/Settings/TriSidebarSettingsAction.php) `ALLOWED_MODULE_IDS` for `tri-jobs`, `tri-contacts`, `tri-invoices`, `tri-tags`.

### AppLayout.vue — TRI layout customizations (since 2026-07)

Beyond the TRIANT nav section, `AppLayout.vue` carries these TRI UI changes. After an upstream merge, prefer the upstream version of conflicting blocks and re-apply:

1. **Imports** (script top): `SHOW_LOCALE_SWITCHER, SHOW_THEME_TOGGLE` from `@/config/triUi`, plus `TriTopbarNav` and `TriLogo` from `./tri/`.
2. **Topbar logo** — upstream `<img src="/styles/logo.svg">` + "MyInvoice.cz" text replaced by `<TriLogo class="h-7 w-auto" />` inside the home `RouterLink`, followed by `<TriTopbarNav />` (direct links to Kontakty/Zakázky/Faktury TRI).
3. **Locale switcher + ThemeToggle** — both desktop topbar and mobile drawer-footer variants get `v-if="SHOW_LOCALE_SWITCHER"` / `v-if="SHOW_THEME_TOGGLE"` (markup kept, just not rendered).
4. **Sidebar is always a drawer** (hamburger on all widths):
   - backdrop `div`: remove `lg:hidden`
   - `<aside>`: `fixed lg:sticky` → `fixed`, remove `lg:z-auto`, `-translate-x-full lg:translate-x-0` → `-translate-x-full`
   - hamburger button: remove `lg:hidden`
5. **AppShell.vue** (login/setup) — logo replaced by `<TriLogo class="h-9 w-auto text-neutral-900" />`, "MyInvoice.cz" heading removed.

TRI-only files involved (no upstream conflicts expected): `web/src/config/triUi.ts`, `web/src/components/layout/tri/TriTopbarNav.vue`, `web/src/components/layout/tri/TriLogo.vue`.

Optional small upstream touches (document in merge notes):

- `ClientRepository` / `ListClientsAction` — `tri_tag_id` filter on Klienti list.
- [InvoiceRepository.php](../api/src/Repository/InvoiceRepository.php) `listGroupedByMonth()` — `tri_linked` / `tri_job_id` filter (JOIN `tri_job_invoices`).
- [InvoiceDetail.vue](../web/src/pages/invoices/InvoiceDetail.vue) — cross-link blok „Zakázka TRI".
- [InvoiceEditor.vue](../web/src/pages/invoices/InvoiceEditor.vue) — `TriJobLinkField` + query `tri_job_id`.

## Monthly merge procedure

```bash
cd /opt/myinvoice-triant/repo
git fetch upstream
git fetch origin
git checkout master
git branch backup/pre-upstream-$(date +%Y%m%d)
git checkout -b integration/upstream-vX.Y.Z
git merge upstream/master   # or upstream tag vX.Y.Z
# Resolve conflicts — prefer upstream for core files; keep Tri/ + 9xxx migrations + hooks
php api/bin/migrate.php
cd web && pnpm install && pnpm build
```

Smoke test:

- Login, Klienti + tagy
- Zakázky TRI — create job, edit variant, totals
- Faktury TRI — záloha/konečná faktura z zakázky, seznam Faktury TRI
- Sidebar TRI settings still visible

## Migrations

`migrate.php` runs all `db/migrations/*.sql` in sort order. TRI files `9000_*` run after upstream `0099_*`. No changes to migrator required.

## Merge history

### 2026-09-07 — upstream v4.56.4 (from v4.54.0)

- **Branch:** `integration/upstream-v4.56.4` → merged to `master`
- **Rollback branch:** `backup/pre-upstream-update-20260907`
- **Conflicts resolved (3 files):**
  - `AppLayout.vue` — TRI `moduleId` systémové položky + upstream `/admin/upgrade` (Přechod na MyÚčto) + TRI settings/tags
  - `cs.json` / `en.json` — `myucto_upgrade` + `tri_settings`
- **Auto-merged (TRI hooks intact):** `Routes.php` (MyuctoUpgradeAction + `TriRoutes::register()`), `router/index.ts` (`admin/upgrade` + `...triRoutes`), `InvoiceRepository.php`
- **New upstream migrations:** `0150_purchase_vat_classification_30_cleanup.sql`
- **Sidebar `ALLOWED_MODULE_IDS` extended:** `myucto-upgrade`
- **Upstream highlights:** one-click MyÚčto upgrade page, VAT/KH fixes, Fio/RB bank notice parsers, settings Save-per-section
- **Poznámka:** WIP PDF nabídek stashnuté (`WIP quote PDF before upstream 4.56.4 merge`)

### 2026-08-17 — upstream v4.54.0 (from v4.37.3)

- **Branch:** `integration/upstream-v4.54.0` → merged to `master`
- **Rollback branch:** `backup/pre-upstream-update-20260817`
- **Conflicts resolved (18 files):**
  - `Routes.php` — both `TriSidebarSettingsAction` and `SupplierInvoiceCounterAction`; `TriRoutes::register()` already at end
  - `InvoiceRepository.php` — TRI `tri_job_*` hydrate + upstream `branding_profile_id`
  - `Validation.php` / `openapi.yaml` — upstream: street/city/zip povinné, `main_email` nullable
  - `router/index.ts` — upstream bank-accounts → `/bank` redirect + `admin-tri-settings` + `...triRoutes`
  - `settings.ts` — upstream email preview `branding_profile_id` + TRI sidebar API
  - `package.json` / `pnpm-lock.yaml` — upstream deps (Vue 3.5.40, Pinia 4, PHP image 8.5) + ponecháno `vuedraggable` (TRI tabulky)
  - `cs.json` / `en.json` — upstream OSS / email_profiles / price_list + `tri_settings`
  - `AppLayout.vue` — TRI drawer/logo/topbar/moduleId + skrytí locale/theme; upstream price-list, OSS, canWrite importy, session lock, MyÚčto patička; bankovní účty přesunuté pod Finance
  - Core pages (Untitled restyle zachován, doplněné upstream funkce): InvoiceList (bulk PDF + FilterBar), InvoiceDetail (public viewed badge), InvoiceEditor (ceník položek), ClientForm (jméno + branding), ClientDetail (jméno)
  - `README.md` — vzato z upstreamu (sekce nativní instalace)
- **New upstream migrations:** `0115`–`0149` (OSS, branding profiles, passkeys/MFA, user_suppliers, price_list_items, public invoice links, email profiles, …).
- **TRI adapter migrations:** `0114_tri_drop_supplier_fks.sql` + `0115_tri_widen_supplier_fks.sql` — upstream `0115` mění `supplier.id` TINYINT→INT; TRI FK by jinak ALTER zablokovaly. `9000_*` `supplier_id` sjednoceno na `INT UNSIGNED` pro čisté instalace.
- **Sidebar `ALLOWED_MODULE_IDS` extended:** `price-list`, `reports-oss`. `bank-accounts` v menu Systém odstraněno (upstream přesunul banku pod Finance).
- **Runtime:** PHP `^8.5` (Dockerfile `php:8.5-apache`).
- **Poznámka:** WIP úpravy PDF nabídek byly před merge stashnuté (`WIP quote PDF before upstream 4.54 merge`).

### 2026-06-22 — upstream v4.37.3 (from v4.13.1)

- **Branch:** `integration/upstream-v4.37.3` → merged to `master`
- **Rollback tag:** `tri-baseline-pre-v4.37.3` (TRI plugin committed); DB dump `data/backups/myinvoice-20260622-084950.sql.gz`; Docker image `myinvoice-triant:rollback-20260622`
- **Conflicts resolved (7 files):**
  - `ClientRepository.php` — kept upstream `tax_number` + TRI `phone` + `tri_stats` JOIN
  - `Routes.php` — upstream logbook routes + `TriRoutes::register()` at end
  - `clients.ts` — merged `tri_*` fields + upstream `email_contacts`
  - `AppLayout.vue` — upstream payment-orders + logbook nav with TRI `moduleId` sidebar keys
  - `cs.json` / `en.json` — merged TRI nav keys + upstream logbook/payment_orders/smtp_logs
  - `router/index.ts` — both `triRoutes` import and `useSupplierStore` (onboarding gate)
- **New upstream migrations applied:** `0100`–`0114` (logbook, payment orders, client email contacts, tax_number, …)
- **Sidebar `ALLOWED_MODULE_IDS` extended:** `payment-orders`, `logbook`
- **Optional touches not yet done:** InvoiceDetail/InvoiceEditor cross-link to TRI job (still only in TRI invoice pages)
