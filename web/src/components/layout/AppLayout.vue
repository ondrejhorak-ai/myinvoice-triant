<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { updateApi, type PublicVersion } from '@/api/update'
import { settingsApi } from '@/api/settings'
import { TRI_SETTINGS_MODULE_ID, normalizeHiddenSidebarModules } from '@/config/triSidebar'
import { SHOW_LOCALE_SWITCHER, SHOW_THEME_TOGGLE } from '@/config/triUi'
import SupplierSwitcher from './SupplierSwitcher.vue'
import GlobalSearch from './GlobalSearch.vue'
import ThemeToggle from './ThemeToggle.vue'
import TriTopbarNav from './tri/TriTopbarNav.vue'
import TriLogo from './tri/TriLogo.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useToast } from '@/composables/useToast'

const { t, locale } = useI18n()
function setLocale(l: 'cs' | 'en') {
  locale.value = l
  localStorage.setItem('locale', l)
}

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const sessionSecurity = useSessionSecurityStore()
const toast = useToast()

const mobileOpen = ref(false)
const quickOpen = ref(false)
const supportOpen = ref(false)
const myuctoOpen = ref(false)
const accountantSigningProfilesEnabled = ref(false)
const logoutBusy = ref(false)
const canLockSession = computed(() => sessionSecurity.state?.session_state === 'active'
  && sessionSecurity.state.unlock_methods.includes('passkey'))
let signingSettingsRequest = 0

async function logout() {
  if (logoutBusy.value) return
  logoutBusy.value = true
  try {
    await auth.logout()
    sessionSecurity.clear()
    mobileOpen.value = false
    await router.replace('/login')
  } catch {
    sessionSecurity.markLocked()
    sessionSecurity.error = 'logout_failed'
    toast.error(t('auth.logout_failed'))
  } finally {
    logoutBusy.value = false
  }
}

async function loadAccountantSigningMenu() {
  const requestId = ++signingSettingsRequest
  if (auth.user?.role !== 'accountant') {
    accountantSigningProfilesEnabled.value = false
    return
  }

  try {
    const settings = await settingsApi.getSigningSettings()
    if (requestId === signingSettingsRequest) {
      accountantSigningProfilesEnabled.value = settings.accountant_profiles_enabled === true
    }
  } catch {
    if (requestId === signingSettingsRequest) {
      accountantSigningProfilesEnabled.value = false
    }
  }
}

watch(
  () => [auth.user?.role, supplierStore.currentSupplierId] as const,
  () => { void loadAccountantSigningMenu() },
  { immediate: true },
)

interface NavItem {
  moduleId: string
  to: string
  label: string
  icon: string
  /** True = externí odkaz (otevře se v novém tabu, ne RouterLink). Např. /manual. */
  external?: boolean
  /** Cílová route pro rychlé „+" (vytvořit nový) vpravo u položky. Jen pro zapisující. */
  newTo?: string
}
interface NavSection {
  /** Hlavička sekce; pokud chybí, položky jsou bez visual grouping */
  title?: string
  /** Color accent (data); vizuál sekcí je Untitled muted caption, ne barevný pill. */
  accent?: 'primary' | 'warning' | 'success' | 'danger' | 'neutral'
  items: NavItem[]
}

/** Outline icon paths — Heroicons style, stroke 2, viewBox 24, currentColor */
const ICONS = {
  dashboard:  'M3 12l9-9 9 9M5 10v10h14V10',
  invoices:   'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  proforma:   'M2.25 8.25h19.5M2.25 9v6.75A2.25 2.25 0 0 0 4.5 18h15a2.25 2.25 0 0 0 2.25-2.25V9A2.25 2.25 0 0 0 19.5 6.75h-15A2.25 2.25 0 0 0 2.25 9zM14 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  recurring:  'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  price_list: 'M4 6h16M4 12h16M4 18h16M7 4v4M13 10v4M17 16v4',
  purchase:   'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  bank:       'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7',
  stats:      'M3 3v18h18M7 14l4-4 4 4 5-5',
  crm:        'M11 3.055A9.001 9.001 0 1 0 20.945 13H11V3.055zM20.488 9H15V3.512A9.025 9.025 0 0 1 20.488 9z',
  reports:    'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2zM9 7h1',
  clients:    'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  projects:   'M3 7l9-4 9 4-9 4-9-4zM3 12l9 4 9-4M3 17l9 4 9-4',
  settings:   'M10.325 4.317a1 1 0 0 1 1.94 0l.31 1.241a7.5 7.5 0 0 1 2.106.873l1.097-.633a1 1 0 0 1 1.371.366l.97 1.683a1 1 0 0 1-.366 1.366l-1.094.632a7.5 7.5 0 0 1 0 2.428l1.094.632a1 1 0 0 1 .366 1.366l-.97 1.683a1 1 0 0 1-1.371.366l-1.097-.633a7.5 7.5 0 0 1-2.106.873l-.31 1.241a1 1 0 0 1-1.94 0l-.31-1.241a7.5 7.5 0 0 1-2.106-.873l-1.097.633a1 1 0 0 1-1.371-.366l-.97-1.683a1 1 0 0 1 .366-1.366l1.094-.632a7.5 7.5 0 0 1 0-2.428l-1.094-.632a1 1 0 0 1-.366-1.366l.97-1.683a1 1 0 0 1 1.371-.366l1.097.633a7.5 7.5 0 0 1 2.106-.873l.31-1.241zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  suppliers:  'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM23 11a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  codebooks:  'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10',
  imports:    'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0l-4 4m4-4v12',
  exports:    'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
  payment_orders: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3z',
  users:      'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  email:      'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  sent_email: 'M6 12L3.269 3.126A59.768 59.768 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.876L5.999 12Zm0 0h7.5',
  approvals:  'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  log:        'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6m-6 4h4',
  cron:       'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  updates:    'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  myucto_upgrade: 'M13 7l5 5m0 0l-5 5m5-5H6',
  api_tokens: 'M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z',
  help:       'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827V14m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  ai:         'M13 10V3L4 14h7v7l9-11h-7z',
  documents:  'M7 21h10a2 2 0 0 0 2-2V9.414a1 1 0 0 0-.293-.707l-5.414-5.414A1 1 0 0 0 12.586 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2zM9 13h6m-6 4h6',
  logbook:    'M5 13l1.4-4.2A2 2 0 0 1 8.3 7.5h7.4a2 2 0 0 1 1.9 1.3L19 13m-14 0h14m-14 0v4a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-1h8v1a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-4M7.5 16h.01M16.5 16h.01',
  fuel:       'M4 21h9M6 21V5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v16M6 11h7M15 7l2.5 2.5a2 2 0 0 1 .5 1.4V17a1.5 1.5 0 0 0 3 0V10l-2-2',
  // Daně sekce — různé ikony pro každý report
  tax_dph:    'M3 10h18M3 14h18M5 21V3a1 1 0 011-1h12a1 1 0 011 1v18M9 7h6M9 11h6M9 15h6',
  tax_kh:     'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
  tax_shv:    'M12 21l-8-8 8-8m0 0l8 8-8 8M3 12h18',
  tax_income: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  tax_archive: 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
  tax_book:   'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
  tax_optimizer: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
  tri_jobs: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
  tri_price_lists: 'M4 6h16M4 10h16M4 14h10M4 18h6',
  tri_travelers: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  tri_calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  tri_complaints: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
}

const navSections = computed<NavSection[]>(() => {
  const isAdmin = auth.user?.role === 'admin'
  const sections: NavSection[] = [
    {
      title: t('nav.section_triant'),
      accent: 'primary',
      items: [
        { moduleId: 'tri-jobs', to: '/tri/jobs', label: t('nav.tri_jobs'), icon: ICONS.tri_jobs, newTo: '/tri/jobs/new' },
        { moduleId: 'tri-contacts', to: '/tri/contacts', label: t('nav.tri_contacts'), icon: ICONS.clients, newTo: '/tri/contacts/new' },
        { moduleId: 'tri-invoices', to: '/tri/invoices', label: t('nav.tri_invoices'), icon: ICONS.invoices, newTo: '/tri/invoices/new' },
        { moduleId: 'tri-price-lists', to: '/tri/price-lists', label: t('nav.tri_price_lists'), icon: ICONS.tri_price_lists, newTo: '/tri/price-lists/new' },
        { moduleId: 'tri-travelers', to: '/tri/travelers', label: t('nav.tri_travelers'), icon: ICONS.tri_travelers },
        { moduleId: 'tri-calendar', to: '/tri/calendar', label: t('nav.tri_calendar'), icon: ICONS.tri_calendar },
        { moduleId: 'tri-complaints', to: '/tri/complaints', label: t('nav.tri_complaints'), icon: ICONS.tri_complaints },
      ],
    },
  ]

  if (isAdmin) {
    // Suppliers (multi-tenant firmy) jsou teď přístupné jako první tab v Codebooks.
    // Sjednocený "Import" pokrývá vystavené i přijaté faktury (admin/import s tabs).
    sections.push({
      title: t('nav.system'),
      accent: 'neutral',
      items: [
        { moduleId: 'settings', to: '/admin/settings', label: t('nav.settings'), icon: ICONS.settings },
        { moduleId: 'codebooks', to: '/admin/codebooks', label: t('nav.codebooks'), icon: ICONS.codebooks },
        { moduleId: 'users', to: '/admin/users', label: t('nav.users'), icon: ICONS.users },
        { moduleId: 'emails', to: '/admin/emails', label: t('nav.emails'), icon: ICONS.email },
        { moduleId: 'activity-log', to: '/admin/activity-log', label: t('nav.log'), icon: ICONS.log },
        { moduleId: 'integrations', to: '/admin/integrations', label: t('nav.integrations'), icon: ICONS.api_tokens },
        { moduleId: 'cron-jobs', to: '/admin/cron-jobs', label: t('nav.cron_jobs'), icon: ICONS.cron },
        { moduleId: 'updates', to: '/admin/update', label: t('nav.updates'), icon: ICONS.updates },
        { moduleId: 'myucto-upgrade', to: '/admin/upgrade', label: t('nav.myucto_upgrade'), icon: ICONS.myucto_upgrade },
        { moduleId: 'api-tokens', to: '/profile/api-tokens', label: t('nav.api_tokens'), icon: ICONS.api_tokens },
        { moduleId: TRI_SETTINGS_MODULE_ID, to: '/admin/tri-settings', label: t('nav.tri_settings'), icon: ICONS.settings },
        { moduleId: 'tri-tags', to: '/admin/tri-tags', label: t('nav.tri_tags'), icon: ICONS.clients },
        { moduleId: 'tri-myucto', to: '/tri/admin/myucto', label: t('nav.tri_myucto'), icon: ICONS.myucto_upgrade },
      ],
    })
  }

  if (!isAdmin && auth.user?.role === 'accountant' && accountantSigningProfilesEnabled.value) {
    sections.push({
      title: t('nav.system'),
      accent: 'neutral',
      items: [
        { moduleId: 'electronic-signatures', to: '/admin/electronic-signatures', label: t('nav.electronic_signatures'), icon: ICONS.approvals },
      ],
    })
  }

  // Nápověda jako poslední (po Systému) — externí link na manuál v novém tabu.
  sections.push({
    items: [
      { moduleId: 'help', to: '/manual', label: t('nav.help'), icon: ICONS.help, external: true },
    ],
  })

  return sections
})

/** Rychlé zkratky v topbaru (desktop) — ikony navazují na menu (ICONS). */
const quickActions = computed(() => [
  { to: '/tri/jobs/new', label: t('nav.tri_jobs'), icon: ICONS.tri_jobs },
  { to: '/tri/invoices/new', label: t('nav.quick_invoice'), icon: ICONS.invoices },
  { to: '/tri/invoices/new?type=proforma', label: t('nav.quick_proforma'), icon: ICONS.proforma },
  { to: '/tri/contacts/new', label: t('nav.quick_client'), icon: ICONS.clients },
  { to: '/tri/price-lists/new', label: t('nav.tri_price_lists'), icon: ICONS.tri_price_lists },
])

/** Ploché položky menu pro globální search (našeptávač skáče přímo na body menu). */
const flatNavItems = computed(() =>
  navSections.value.flatMap(s => s.items.map(it => ({ to: it.to, label: it.label, icon: it.icon, external: it.external })))
)

const hiddenSidebarModules = ref<string[]>([])

const visibleNavSections = computed<NavSection[]>(() => {
  if (hiddenSidebarModules.value.length === 0) return navSections.value
  const hidden = new Set(hiddenSidebarModules.value)
  return navSections.value
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => item.moduleId === TRI_SETTINGS_MODULE_ID || !hidden.has(item.moduleId)),
    }))
    .filter((section) => section.items.length > 0)
})

/**
 * „Pokrývá" URL (path + případná query) současnou route?
 * Path musí sedět přesně nebo jako rodič skutečného child segmentu — prostý
 * startsWith by matchoval i sourozence se stejným prefixem (např. /reports/dph
 * by matchoval /reports/dph-book). Query klíče z URL musí všechny sedět
 * s route.query; `queried` říká, že shoda vznikla i přes query (= specifičtější).
 */
function urlCoversRoute(url: string): { covers: boolean; queried: boolean } {
  const [path, qs] = url.split('?', 2)
  if (route.path !== path && !route.path.startsWith(path + '/')) return { covers: false, queried: false }
  if (!qs) return { covers: true, queried: false }
  for (const [k, v] of new URLSearchParams(qs)) {
    if (String(route.query[k] ?? '') !== v) return { covers: false, queried: false }
  }
  return { covers: true, queried: true }
}

/**
 * Kandidátní URL položky menu: `to` + případné `newTo`. Formulář „nový" patří
 * vizuálně k témuž itemu — /clients/new?role=vendor jsou „Dodavatelé", ne
 * „Klienti". Hodnoty query se u seznamu a formuláře liší záměrně (seznam
 * filtruje přes role=vendors, formulář dostává default přes role=vendor,
 * viz ClientList vs ClientForm), takže samotné `to` na match nestačí.
 */
function itemUrls(item: { to: string; newTo?: string }): string[] {
  return item.newTo ? [item.to, item.newTo] : [item.to]
}

function isActive(item: NavItem): boolean {
  const to = item.to
  if (to === '/') return route.path === '/'
  // /admin/suppliers je nyní dostupné jako první tab v Codebooks → aktivuje Codebooks položku
  if (to === '/admin/codebooks' && route.path.startsWith('/admin/suppliers')) return true

  const [toPath] = to.split('?', 2)

  const matches = itemUrls(item).map(urlCoversRoute)
  if (!matches.some(m => m.covers)) return false

  // Match bez query shody prohrává s itemem, který route pokrývá včetně query —
  // ať už přes `to` (/clients vs /clients?role=vendors na seznamu dodavatelů),
  // nebo přes `newTo` (/clients vs /clients/new?role=vendor na formuláři).
  if (!matches.some(m => m.queried)) {
    for (const section of navSections.value) {
      for (const it of section.items) {
        if (it.to === to) continue
        if (itemUrls(it).some(u => urlCoversRoute(u).queried)) return false
      }
    }
  }

  // Delší `to` v menu má prednost (např. /purchase-invoices vs /purchase-invoices/export).
  for (const section of navSections.value) {
    for (const it of section.items) {
      if (it.to !== to && it.to.startsWith(toPath + '/') && route.path.startsWith(it.to.split('?')[0])) {
        return false
      }
    }
  }
  return true
}

// Zavři mobile drawer + rychlé menu po navigaci
watch(() => route.path, () => { mobileOpen.value = false; quickOpen.value = false })

const versionInfo = ref<PublicVersion | null>(null)
onMounted(async () => {
  const [versionResult, sidebarResult] = await Promise.allSettled([
    updateApi.publicVersion(),
    settingsApi.getTriSidebarSettings(),
  ])
  if (versionResult.status === 'fulfilled') {
    versionInfo.value = versionResult.value
  }
  if (sidebarResult.status === 'fulfilled') {
    hiddenSidebarModules.value = normalizeHiddenSidebarModules(sidebarResult.value.hidden_modules)
  }
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">

    <!-- ═════════════════════ TOPBAR ═════════════════════ -->
    <header class="sticky top-0 z-30 bg-surface border-b border-neutral-200 shadow-xs">
      <div class="h-14 px-4 sm:px-6 flex items-center justify-between gap-3">
        <!-- Logo + přímé odkazy na TRI agendy -->
        <div class="flex items-center gap-3 min-w-0">
          <RouterLink to="/" class="flex items-center shrink-0 text-neutral-900" @click="mobileOpen = false">
            <TriLogo class="h-7 w-auto" />
          </RouterLink>
          <TriTopbarNav />
        </div>

        <!-- Pravá strana topbaru -->
        <div class="flex items-center gap-1.5 text-sm">
          <!-- Rychlé vytvoření (desktop, jen pro zapisující) — jedno decentní tlačítko s menu -->
          <div v-if="auth.canWrite" class="relative hidden lg:block">
            <UiButton
              type="button"
              variant="secondary"
              size="sm"
              @click="quickOpen = !quickOpen"
              :aria-expanded="quickOpen"
              :aria-label="t('nav.quick_new')"
            >
              <template #icon>
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                </svg>
              </template>
              {{ t('nav.quick_new') }}
            </UiButton>
            <transition
              enter-active-class="transition duration-100 ease-out"
              enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100"
              leave-active-class="transition duration-75 ease-in"
              leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95"
            >
              <div v-if="quickOpen" class="absolute right-0 mt-1.5 w-56 bg-surface border border-neutral-200 rounded-lg shadow-md py-1 z-40">
                <RouterLink
                  v-for="s in quickActions" :key="s.to" :to="s.to" @click="quickOpen = false"
                  class="flex items-center gap-2.5 h-10 px-3 text-sm text-neutral-700 hover:bg-neutral-100"
                >
                  <svg class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="s.icon" />
                  </svg>
                  <span>{{ s.label }}</span>
                </RouterLink>
              </div>
            </transition>
            <div v-if="quickOpen" @click="quickOpen = false" class="fixed inset-0 z-10" aria-hidden="true"></div>
          </div>
          <!-- Jemný předěl, aby „Vytvořit" nebylo nalepené na jméně uživatele -->
          <span v-if="auth.canWrite" class="hidden lg:inline-block w-px h-5 bg-neutral-200 mx-1" aria-hidden="true"></span>

          <!-- Jméno uživatele (desktop) — link na profil (heslo + 2FA v záložkách). -->
          <RouterLink
            to="/profile/password"
            class="hidden lg:inline-flex items-center h-9 px-2.5 rounded-md text-sm text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
            :title="t('auth.profile_title')"
          >{{ auth.user?.name }}</RouterLink>

          <!-- Locale switcher (CZ / EN s SVG vlajkami) — TRIANT: skryto (viz config/triUi.ts) -->
          <div v-if="SHOW_LOCALE_SWITCHER" class="hidden sm:inline-flex items-center border border-neutral-200 rounded-md overflow-hidden">
            <button
              @click="setLocale('cs')" title="Čeština" aria-label="Čeština"
              class="cursor-pointer h-8 px-2 inline-flex items-center"
              :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                <rect width="6" height="2" fill="#ffffff"/>
                <rect y="2" width="6" height="2" fill="#d7141a"/>
                <polygon points="0,0 3,2 0,4" fill="#11457e"/>
              </svg>
            </button>
            <button
              @click="setLocale('en')" title="English" aria-label="English"
              class="cursor-pointer h-8 px-2 inline-flex items-center border-l border-neutral-200"
              :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                <clipPath id="uk-flag-tb"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#uk-flag-tb)" stroke="#C8102E" stroke-width="4"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
              </svg>
            </button>
          </div>

          <!-- Přepínač motivu (System / Light / Dark) — TRIANT: skryto (viz config/triUi.ts) -->
          <div v-if="SHOW_THEME_TOGGLE" class="hidden sm:inline-flex">
            <ThemeToggle />
          </div>

          <!-- Nápověda -->
          <a
            href="/manual" target="_blank" rel="noopener"
            class="hidden sm:inline-flex w-9 h-9 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
            :title="t('nav.help')"
            :aria-label="t('nav.help')"
          >
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
            </svg>
          </a>

          <!-- Odhlásit (desktop) -->
          <UiButton
            v-if="canLockSession"
            variant="ghost"
            size="sm"
            class="hidden sm:inline-flex"
            @click="sessionSecurity.lock"
          >{{ t('session_lock.lock_now') }}</UiButton>
          <UiButton
            variant="ghost"
            size="sm"
            class="hidden sm:inline-flex"
            :disabled="logoutBusy"
            @click="logout"
          >{{ t('nav.logout') }}</UiButton>

          <!-- Hamburger — TRIANT: na všech šířkách (menu je vždy v draweru) -->
          <button
            type="button" @click="mobileOpen = !mobileOpen"
            :aria-expanded="mobileOpen" aria-label="Menu"
            class="cursor-pointer inline-flex items-center justify-center w-10 h-10 rounded-md text-neutral-700 hover:bg-neutral-100"
          >
            <svg v-if="!mobileOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Active supplier banner -->
      <div v-if="supplierStore.hasMultiple && supplierStore.currentSupplier" class="bg-primary-50 border-t border-primary-100">
        <div class="px-4 py-1.5 text-xs text-primary-700 flex items-center gap-2">
          <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/>
          </svg>
          <span class="flex-1 min-w-0 truncate">
            {{ t('supplier.active_label') }}: <strong class="font-semibold">{{ supplierStore.currentSupplier.company_name }}</strong>
            <span v-if="supplierStore.currentSupplier.ic" class="font-mono text-primary-600 ml-1">({{ t('common.ic') }} {{ supplierStore.currentSupplier.ic }})</span>
          </span>
          <SupplierSwitcher />
        </div>
      </div>
    </header>

    <!-- ═════════════════════ TĚLO: SIDEBAR + OBSAH ═════════════════════ -->
    <div class="flex flex-1 min-h-0">

      <!-- Backdrop draweru — TRIANT: drawer na všech šířkách (dřív jen mobil, desktop měl trvalý sidebar) -->
      <div
        v-if="mobileOpen" @click="mobileOpen = false"
        class="fixed inset-0 bg-black/50 z-20"
        aria-hidden="true"
      ></div>

      <!-- ── SIDEBAR (drawer za hamburgerem) ── -->
      <aside
        :class="[
          'fixed top-14 z-30',
          'h-[calc(100vh-3.5rem)] w-64 shrink-0',
          'bg-surface border-r border-neutral-200 shadow-xs',
          'flex flex-col',
          'transition-transform duration-200 ease-in-out',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        ]"
      >
        <nav class="flex-1 overflow-y-auto scrollbar-slim px-3 py-3">
          <!-- Globální vyhledávač (před Přehled) — našeptává menu + hledá klienty/faktury -->
          <GlobalSearch :menu-items="flatNavItems" @navigated="mobileOpen = false" />

          <template v-for="(section, si) in visibleNavSections" :key="si">
            <!-- Section title — Untitled: jemný uppercase popisek, ne barevný pill -->
            <div v-if="section.title" :class="si === 0 ? 'px-3 pt-2 pb-1' : 'px-3 pt-5 pb-1'">
              <div class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">
                {{ section.title }}
              </div>
            </div>

            <!-- Items: external (např. Nápověda → /manual v novém tabu) vs internal route -->
            <template v-for="item in section.items" :key="item.to">
              <a
                v-if="item.external"
                :href="item.to"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-3 h-10 px-3 rounded-lg text-sm transition-colors leading-tight text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100"
              >
                <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                {{ item.label }}
                <svg class="w-3.5 h-3.5 ml-auto text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
              </a>
              <div v-else class="relative group">
                <RouterLink
                  :to="item.to"
                  active-class=""
                  exact-active-class=""
                  class="flex items-center gap-3 h-10 px-3 rounded-lg text-sm transition-colors leading-tight"
                  :class="[
                    isActive(item)
                      ? 'bg-primary-50 text-primary-700 font-medium'
                      : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100',
                    item.newTo && auth.canWrite ? 'pr-9' : '',
                  ]"
                >
                  <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                  </svg>
                  {{ item.label }}
                </RouterLink>
                <!-- Rychlé „+" (vytvořit nový) — skryté, odhalí se až při hoveru nad položkou -->
                <RouterLink
                  v-if="item.newTo && auth.canWrite"
                  :to="item.newTo"
                  :title="t('nav.quick_new')"
                  :aria-label="t('nav.quick_new')"
                  class="absolute right-1.5 top-1/2 -translate-y-1/2 inline-flex items-center justify-center w-7 h-7 rounded-md text-neutral-400 hover:text-primary-700 hover:bg-primary-100 transition-all opacity-100 lg:opacity-0 lg:group-hover:opacity-100 focus:opacity-100"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m4-4H8M6 4h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" />
                  </svg>
                </RouterLink>
              </div>
            </template>
          </template>
        </nav>

        <!-- Verze + odkaz na projekt (dole) -->
        <div v-if="versionInfo" class="px-4 py-2.5 border-t border-neutral-100 flex items-center gap-2">
          <a href="https://myinvoice.cz/" target="_blank" rel="noopener"
             class="text-xs text-neutral-500 hover:text-primary-700 hover:underline transition-colors"
             title="MyInvoice.cz">MyInvoice.cz</a>
          <RouterLink
            v-if="auth.user?.role === 'admin'"
            to="/admin/update"
            class="inline-flex items-center gap-1.5 text-xs text-neutral-400 hover:text-neutral-600 transition-colors"
            :title="t('updates.title')"
          >
            <span>v{{ versionInfo.current }}</span>
            <span
              v-if="versionInfo.has_update"
              class="inline-flex items-center gap-1 rounded-full bg-primary-100 text-primary-700 px-1.5 py-0.5 text-[10px] font-semibold leading-none"
            >
              <svg class="w-2 h-2" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/></svg>
              v{{ versionInfo.latest }}
            </span>
          </RouterLink>
          <span v-else class="text-xs text-neutral-400">v{{ versionInfo.current }}</span>
        </div>

        <!-- Mobile only: profil + ovládání relace (na dně sidebaru) -->
        <div class="lg:hidden border-t border-neutral-200 px-4 py-3 bg-neutral-50 space-y-3">
          <div class="flex items-center justify-between">
            <RouterLink
              to="/profile/password"
              @click="mobileOpen = false"
              class="group min-w-0 flex-1 rounded-md -ml-2 px-2 py-1.5 text-sm hover:bg-surface"
              :title="t('auth.profile_title')"
            >
              <div class="truncate font-medium text-neutral-900 group-hover:text-primary-700 group-hover:underline">
                {{ auth.user?.name }}
              </div>
              <div class="truncate text-xs text-neutral-500">{{ auth.user?.email }} · {{ auth.user?.role }}</div>
            </RouterLink>
            <a
              href="/manual" target="_blank" rel="noopener"
              class="inline-flex w-9 h-9 items-center justify-center rounded-md text-neutral-600 hover:bg-surface"
              :title="t('nav.help')"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
              </svg>
            </a>
          </div>
          <!-- Přepínač motivu (System / Light / Dark) — mobilní varianta — TRIANT: skryto (viz config/triUi.ts) -->
          <div v-if="SHOW_THEME_TOGGLE" class="flex">
            <ThemeToggle />
          </div>
          <div class="flex items-center justify-between gap-3">
            <div v-if="SHOW_LOCALE_SWITCHER" class="inline-flex items-center border border-neutral-200 bg-surface rounded-md overflow-hidden">
              <button
                @click="setLocale('cs')" title="Čeština"
                class="cursor-pointer h-9 px-3 inline-flex items-center"
                :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                  <rect width="6" height="2" fill="#ffffff"/>
                  <rect y="2" width="6" height="2" fill="#d7141a"/>
                  <polygon points="0,0 3,2 0,4" fill="#11457e"/>
                </svg>
              </button>
              <button
                @click="setLocale('en')" title="English"
                class="cursor-pointer h-9 px-3 inline-flex items-center border-l border-neutral-200"
                :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                  <clipPath id="uk-flag-mob"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                  <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#uk-flag-mob)" stroke="#C8102E" stroke-width="4"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
                </svg>
              </button>
            </div>
          </div>
          <div class="grid gap-2" :class="canLockSession ? 'grid-cols-2' : 'grid-cols-1'">
            <button
              v-if="canLockSession"
              @click="sessionSecurity.lock"
              class="cursor-pointer w-full px-2 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface"
            >{{ t('session_lock.lock_now') }}</button>
            <button
              @click="logout"
              :disabled="logoutBusy"
              class="cursor-pointer w-full px-2 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface disabled:opacity-60"
            >{{ t('nav.logout') }}</button>
          </div>
        </div>
      </aside>

      <!-- ── HLAVNÍ OBSAH ── -->
      <div class="flex-1 min-w-0 flex flex-col">
        <main class="flex-1 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
          <RouterView />
        </main>

        <footer class="border-t border-neutral-200">
          <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 text-xs text-neutral-500 flex flex-wrap items-center gap-x-1.5 gap-y-1 leading-none">
          <span>Developed by</span>
          <a href="https://mywebdesign.cz" target="_blank" rel="noopener" class="hover:text-neutral-700">MyWebdesign.cz s.r.o.</a>
          <span aria-hidden="true">·</span>
          <a href="https://github.com/radekhulan/myinvoice" target="_blank" rel="noopener"
             class="inline-flex items-center gap-1 hover:text-neutral-700">
            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/>
            </svg>
            <span>GitHub</span>
          </a>
          <span aria-hidden="true">·</span>
          <button type="button" @click="supportOpen = true"
                  class="cursor-pointer text-primary-600 hover:text-primary-700 font-medium">{{ t('support.author_link') }}</button>
          <RouterLink v-if="auth.user?.role === 'admin'" to="/admin/upgrade"
                  class="cursor-pointer ml-1.5 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-600 text-white text-xs font-semibold shadow-sm hover:bg-primary-700 hover:shadow transition-colors">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
            </svg>
            <span>{{ t('support.myucto_link') }}</span>
          </RouterLink>
          <button v-else type="button" @click="myuctoOpen = true"
                  class="cursor-pointer ml-1.5 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-600 text-white text-xs font-semibold shadow-sm hover:bg-primary-700 hover:shadow transition-colors">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
            </svg>
            <span>{{ t('support.myucto_link') }}</span>
          </button>
          </div>
        </footer>
      </div>
    </div>

    <!-- ── MODÁL: Podpora autora ── -->
    <div v-if="supportOpen" class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="supportOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full my-8">
        <header class="px-5 py-4 border-b border-neutral-200 flex items-baseline justify-between gap-3">
          <h3 class="text-lg font-semibold">{{ t('support.author_title') }}</h3>
          <button @click="supportOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
        </header>
        <div class="p-5 space-y-4 text-sm text-neutral-700">
          <p>{{ t('support.author_intro') }}</p>
          <dl class="space-y-1.5">
            <div class="flex flex-wrap gap-x-2">
              <dt class="text-neutral-500 w-28 shrink-0">{{ t('support.account') }}</dt>
              <dd class="font-medium">7700000038 / 6363 <span class="text-neutral-400 font-normal">({{ t('support.bank_name') }})</span></dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
              <dt class="text-neutral-500 w-28 shrink-0">{{ t('support.iban') }}</dt>
              <dd class="font-medium">CZ21 6363 0000 0077 0000 0038</dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
              <dt class="text-neutral-500 w-28 shrink-0">{{ t('support.bic') }}</dt>
              <dd class="font-medium">PTBNCZPP</dd>
            </div>
          </dl>
          <div>
            <p class="mb-2">{{ t('support.qr_hint') }}</p>
            <img src="/manual/donate/qrcode.jpg" :alt="t('support.author_title')"
                 class="w-full h-auto rounded-md border border-neutral-200"
                 style="filter: brightness(1.08);" />
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-neutral-200 flex justify-end">
          <button @click="supportOpen = false"
                  class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface">{{ t('support.close') }}</button>
        </footer>
      </div>
    </div>

    <!-- ── MODÁL: MyÚčto ── -->
    <div v-if="myuctoOpen" class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="myuctoOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full my-8">
        <header class="px-5 py-4 border-b border-neutral-200 flex items-baseline justify-between gap-3">
          <h3 class="text-lg font-semibold">{{ t('support.myucto_title') }}</h3>
          <button @click="myuctoOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
        </header>
        <div class="p-5 space-y-3 text-sm text-neutral-700">
          <p>{{ t('support.myucto_intro') }}</p>
          <p class="rounded-md bg-primary-50 border border-primary-500/30 text-primary-800 font-medium px-3 py-2.5">{{ t('support.myucto_free') }}</p>
          <div>
            <p class="font-medium text-neutral-800 mb-1.5">{{ t('support.myucto_better_title') }}</p>
            <ul class="space-y-1 list-disc pl-5">
              <li>{{ t('support.myucto_better_ui') }}</li>
              <li>{{ t('support.myucto_better_ai') }}</li>
              <li>{{ t('support.myucto_better_mcp') }}</li>
              <li>{{ t('support.myucto_better_docs') }}</li>
              <li>{{ t('support.myucto_better_vat') }}</li>
            </ul>
          </div>
          <div>
            <p class="font-medium text-neutral-800 mb-1.5">{{ t('support.myucto_paid_title') }}</p>
            <p>{{ t('support.myucto_paid_text') }}</p>
          </div>
          <p class="text-xs text-neutral-500 border-t border-neutral-200 pt-3">{{ t('support.myucto_highlights') }}</p>
        </div>
        <footer class="px-5 py-4 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
          <button @click="myuctoOpen = false"
                  class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface">{{ t('support.close') }}</button>
          <a href="https://github.com/radekhulan/myucto" target="_blank" rel="noopener" @click="myuctoOpen = false"
             class="cursor-pointer px-4 h-9 inline-flex items-center gap-1.5 text-sm rounded-md border border-neutral-300 text-neutral-700 hover:bg-surface font-medium">
            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/>
            </svg>
            <span>{{ t('support.myucto_github') }}</span>
          </a>
          <a href="https://myucto.cz/" target="_blank" rel="noopener" @click="myuctoOpen = false"
             class="cursor-pointer px-4 h-9 inline-flex items-center text-sm rounded-md bg-primary-600 hover:bg-primary-700 text-white font-medium">{{ t('support.myucto_cta') }}</a>
          <p class="w-full text-xs text-neutral-500 text-right">{{ t('support.myucto_ask_admin') }}</p>
        </footer>
      </div>
    </div>
  </div>
</template>
