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
