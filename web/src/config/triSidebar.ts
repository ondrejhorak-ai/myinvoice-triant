export const TRI_SETTINGS_MODULE_ID = 'tri-settings'

export const TRI_SIDEBAR_MODULES = [
  { id: 'tri-contacts', labelKey: 'nav.tri_contacts', hideable: true },
  { id: 'tri-jobs', labelKey: 'nav.tri_jobs', hideable: true },
  { id: 'tri-invoices', labelKey: 'nav.tri_invoices', hideable: true },
  { id: 'tri-price-lists', labelKey: 'nav.tri_price_lists', hideable: true },
  { id: 'tri-travelers', labelKey: 'nav.tri_travelers', hideable: true },
  { id: 'tri-calendar', labelKey: 'nav.tri_calendar', hideable: true },
  { id: 'tri-complaints', labelKey: 'nav.tri_complaints', hideable: true },
  { id: 'settings', labelKey: 'nav.settings', hideable: true },
  { id: 'codebooks', labelKey: 'nav.codebooks', hideable: true },
  { id: 'users', labelKey: 'nav.users', hideable: true },
  { id: 'emails', labelKey: 'nav.emails', hideable: true },
  { id: 'activity-log', labelKey: 'nav.log', hideable: true },
  { id: 'integrations', labelKey: 'nav.integrations', hideable: true },
  { id: 'cron-jobs', labelKey: 'nav.cron_jobs', hideable: true },
  { id: 'updates', labelKey: 'nav.updates', hideable: true },
  { id: 'api-tokens', labelKey: 'nav.api_tokens', hideable: true },
  { id: TRI_SETTINGS_MODULE_ID, labelKey: 'nav.tri_settings', hideable: false },
  { id: 'tri-tags', labelKey: 'nav.tri_tags', hideable: true },
  { id: 'tri-myucto', labelKey: 'nav.tri_myucto', hideable: true },
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
