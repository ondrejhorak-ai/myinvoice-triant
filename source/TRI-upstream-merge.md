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
