export const TRI_SETTINGS_MODULE_ID = 'tri-settings'

export const TRI_SIDEBAR_MODULES = [
  { id: 'dashboard', labelKey: 'nav.dashboard', hideable: true },
  { id: 'invoices', labelKey: 'nav.invoices', hideable: true },
  { id: 'recurring', labelKey: 'nav.recurring', hideable: true },
  { id: 'clients', labelKey: 'nav.clients', hideable: true },
  { id: 'projects', labelKey: 'nav.projects', hideable: true },
  { id: 'approvals', labelKey: 'nav.approvals', hideable: true },
  { id: 'exports', labelKey: 'nav.exports', hideable: true },
  { id: 'imports-issued', labelKey: 'nav.imports_issued', hideable: true },
  { id: 'purchase-invoices', labelKey: 'nav.purchase_invoices', hideable: true },
  { id: 'vendors', labelKey: 'nav.vendors', hideable: true },
  { id: 'purchase-export', labelKey: 'nav.purchase_export', hideable: true },
  { id: 'imports-purchase', labelKey: 'nav.imports_purchase', hideable: true },
  { id: 'ai-import', labelKey: 'nav.ai_import', hideable: true },
  { id: 'crm', labelKey: 'nav.crm', hideable: true },
  { id: 'stats', labelKey: 'nav.stats', hideable: true },
  { id: 'purchase-stats', labelKey: 'nav.purchase_stats', hideable: true },
  { id: 'bank', labelKey: 'nav.bank', hideable: true },
  { id: 'reports-dph', labelKey: 'nav.reports_dph', hideable: true },
  { id: 'reports-kh', labelKey: 'nav.reports_kh', hideable: true },
  { id: 'reports-dph-book', labelKey: 'nav.reports_dph_book', hideable: true },
  { id: 'reports-shv', labelKey: 'nav.reports_shv', hideable: true },
  { id: 'reports-income-tax', labelKey: 'nav.reports_income_tax', hideable: true },
  { id: 'reports-submissions', labelKey: 'nav.reports_submissions', hideable: true },
  { id: 'reports-monthly-export', labelKey: 'nav.reports_monthly_export', hideable: true },
  { id: 'settings', labelKey: 'nav.settings', hideable: true },
  { id: 'codebooks', labelKey: 'nav.codebooks', hideable: true },
  { id: 'integrations', labelKey: 'nav.integrations', hideable: true },
  { id: 'users', labelKey: 'nav.users', hideable: true },
  { id: 'email-templates', labelKey: 'nav.email_templates', hideable: true },
  { id: 'activity-log', labelKey: 'nav.log', hideable: true },
  { id: 'cron-jobs', labelKey: 'nav.cron_jobs', hideable: true },
  { id: 'updates', labelKey: 'nav.updates', hideable: true },
  { id: 'api-tokens', labelKey: 'nav.api_tokens', hideable: true },
  { id: TRI_SETTINGS_MODULE_ID, labelKey: 'nav.tri_settings', hideable: false },
  { id: 'help', labelKey: 'nav.help', hideable: true },
] as const

const HIDEABLE_IDS = new Set<string>(
  TRI_SIDEBAR_MODULES.filter((item) => item.hideable).map((item) => String(item.id)),
)

export function normalizeHiddenSidebarModules(moduleIds: unknown): string[] {
  if (!Array.isArray(moduleIds)) return []
  const unique = new Set<string>()
  for (const item of moduleIds) {
    if (typeof item !== 'string') continue
    if (!HIDEABLE_IDS.has(item)) continue
    unique.add(item)
  }
  return [...unique]
}
