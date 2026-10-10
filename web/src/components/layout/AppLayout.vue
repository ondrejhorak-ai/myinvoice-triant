<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { systemApi } from '@/api/client'
import { settingsApi } from '@/api/settings'
import { normalizeHiddenSidebarModules } from '@/config/triSidebar'
import { SHOW_LOCALE_SWITCHER, SHOW_THEME_TOGGLE } from '@/config/triUi'
import SupplierSwitcher from './SupplierSwitcher.vue'
import GlobalSearch from './GlobalSearch.vue'
import ThemeToggle from './ThemeToggle.vue'
import TriTopbarNav from './tri/TriTopbarNav.vue'
import TriLogo from './tri/TriLogo.vue'
import OfficeNavIcon from '@/components/tri/OfficeNavIcon.vue'
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
  invoices:   'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  proforma:   'M2.25 8.25h19.5M2.25 9v6.75A2.25 2.25 0 0 0 4.5 18h15a2.25 2.25 0 0 0 2.25-2.25V9A2.25 2.25 0 0 0 19.5 6.75h-15A2.25 2.25 0 0 0 2.25 9zM14 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  clients:    'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  settings:   'M10.325 4.317a1 1 0 0 1 1.94 0l.31 1.241a7.5 7.5 0 0 1 2.106.873l1.097-.633a1 1 0 0 1 1.371.366l.97 1.683a1 1 0 0 1-.366 1.366l-1.094.632a7.5 7.5 0 0 1 0 2.428l1.094.632a1 1 0 0 1 .366 1.366l-.97 1.683a1 1 0 0 1-1.371.366l-1.097-.633a7.5 7.5 0 0 1-2.106.873l-.31 1.241a1 1 0 0 1-1.94 0l-.31-1.241a7.5 7.5 0 0 1-2.106-.873l-1.097.633a1 1 0 0 1-1.371-.366l-.97-1.683a1 1 0 0 1 .366-1.366l1.094-.632a7.5 7.5 0 0 1 0-2.428l-1.094-.632a1 1 0 0 1-.366-1.366l.97-1.683a1 1 0 0 1 1.371-.366l1.097.633a7.5 7.5 0 0 1 2.106-.873l.31-1.241zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  codebooks:  'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10',
  users:      'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  email:      'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  approvals:  'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  log:        'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6m-6 4h4',
  cron:       'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  myucto_upgrade: 'M13 7l5 5m0 0l-5 5m5-5H6',
  api_tokens: 'M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z',
  tri_jobs: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
  tri_price_lists: 'M4 6h16M4 10h16M4 14h10M4 18h6',
  tri_travelers: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  tri_calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  tri_complaints: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
}

const navIconNames: Record<string, string> = {
  'tri-overview': 'overview', 'tri-jobs': 'jobs', 'tri-invoices': 'invoices',
  'tri-contacts': 'contacts', 'tri-price-lists': 'price-lists', settings: 'settings',
  'tri-travelers': 'travelers', 'tri-calendar': 'calendar', 'tri-complaints': 'complaints',
  codebooks: 'codebooks', users: 'users', emails: 'emails', 'activity-log': 'log',
  'cron-jobs': 'cron', 'api-tokens': 'tokens', 'tri-tags': 'tags', 'tri-myucto': 'myucto',
  'electronic-signatures': 'system',
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
    sections.push({
      title: t('nav.system'),
      accent: 'neutral',
      items: [
        { moduleId: 'settings', to: '/admin/settings', label: t('nav.settings'), icon: ICONS.settings },
        { moduleId: 'codebooks', to: '/admin/codebooks', label: t('nav.codebooks'), icon: ICONS.codebooks },
        { moduleId: 'users', to: '/admin/users', label: t('nav.users'), icon: ICONS.users },
        { moduleId: 'emails', to: '/admin/emails', label: t('nav.emails'), icon: ICONS.email },
        { moduleId: 'activity-log', to: '/admin/activity-log', label: t('nav.log'), icon: ICONS.log },
        { moduleId: 'cron-jobs', to: '/admin/cron-jobs', label: t('nav.cron_jobs'), icon: ICONS.cron },
        { moduleId: 'api-tokens', to: '/profile/api-tokens', label: t('nav.api_tokens'), icon: ICONS.api_tokens },
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

  const mainOrder=['tri-overview','tri-jobs','tri-invoices','tri-contacts','tri-price-lists','settings']
  const all=sections.flatMap(s=>s.items)
  all.push({moduleId:'tri-overview',to:'/tri/overview',label:t('office.overview'),icon:ICONS.tri_jobs})
  const main=mainOrder.flatMap(id=>all.filter(item=>item.moduleId===id))
  return [{title:'',accent:'primary',items:main},...sections.map(section=>({...section,title:section.accent==='primary'?t('office.other_agendas'):section.title,items:section.items.filter(item=>!mainOrder.includes(item.moduleId))})).filter(section=>section.items.length)]
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
      items: section.items.filter((item) => !hidden.has(item.moduleId)),
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

const versionInfo = ref<string | null>(null)
onMounted(async () => {
  const [versionResult, sidebarResult] = await Promise.allSettled([
    systemApi.health(),
    settingsApi.getTriSidebarSettings(),
  ])
  if (versionResult.status === 'fulfilled') {
    versionInfo.value = versionResult.value.version
  }
  if (sidebarResult.status === 'fulfilled') {
    hiddenSidebarModules.value = normalizeHiddenSidebarModules(sidebarResult.value.hidden_modules)
  }
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">

    <!-- ═════════════════════ TOPBAR ═════════════════════ -->
    <header class="sticky top-0 z-30 bg-surface border-b border-neutral-200 lg:hidden">
      <div class="h-14 px-4 sm:px-6 flex items-center justify-between gap-3">
        <!-- Logo + přímé odkazy na agendy -->
        <div class="flex items-center gap-3 min-w-0 shrink-0">
          <RouterLink to="/" class="flex items-center shrink-0 text-neutral-900" @click="mobileOpen = false">
            <TriLogo class="h-10 w-auto" />
          </RouterLink>
          <TriTopbarNav />
        </div>

        <!-- Globální hledání (hlavní menu) -->
        <div class="hidden sm:block flex-1 min-w-0 max-w-sm mx-2">
          <GlobalSearch variant="topbar" :menu-items="flatNavItems" />
        </div>

        <!-- Pravá strana topbaru -->
        <div class="flex items-center gap-1.5 text-sm shrink-0">
          <!-- Rychlé vytvoření (desktop, jen pro zapisující) -->
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

          <!-- Locale switcher (CZ / EN) — TRIANT: skryto (viz config/triUi.ts) -->
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

          <!-- Přepínač motivu — TRIANT: skryto (viz config/triUi.ts) -->
          <div v-if="SHOW_THEME_TOGGLE" class="hidden sm:inline-flex">
            <ThemeToggle />
          </div>

          <!-- Hamburger — menu vždy v draweru -->
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
        class="fixed inset-0 bg-black/50 z-20 lg:hidden"
        aria-hidden="true"
      ></div>

      <!-- ── SIDEBAR (drawer za hamburgerem) ── -->
      <aside
        :class="[
          'office-sidebar fixed top-14 lg:top-0 z-30',
          'h-[calc(100dvh-3.5rem)] lg:h-dvh w-[248px] shrink-0',
          'bg-surface border-r border-neutral-200',
          'flex flex-col',
          'transition-transform duration-200 ease-in-out lg:translate-x-0',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        ]"
      >
        <RouterLink to="/tri/jobs" class="hidden lg:block px-4 pt-5 pb-7"><img src="/assets/tri/logo.svg" alt="TRIANT" class="office-logo" /></RouterLink>
          <div class="mx-4 mb-7 shrink-0">
            <GlobalSearch :menu-items="flatNavItems" @navigated="mobileOpen = false" />
          </div>
        <nav class="flex-1 min-h-0 overflow-y-auto scrollbar-slim px-4 pb-4">
          <component :is="si===0?'div':'details'" v-for="(section, si) in visibleNavSections" :key="si" :class="si > 0 ? 'office-nav-group' : 'mb-4'" :open="section.items.some(item=>isActive(item))">
            <summary v-if="si>0" class="office-nav-link flex items-center gap-3 h-11 mb-1 pl-3 pr-2 cursor-pointer list-none text-neutral-600" :class="{ 'text-primary-700 font-semibold': section.items.some(item=>isActive(item)) }">
              <OfficeNavIcon :name="section.accent === 'primary' ? 'other' : 'system'" class="w-5 h-5" />
              <span class="flex-1">{{ section.title }}</span>
              <span class="w-8 h-8 flex items-center justify-center"><OfficeNavIcon name="chevron" class="office-nav-chevron w-4 h-4" /></span>
            </summary>

            <template v-for="item in section.items" :key="item.to">
              <a
                v-if="item.external"
                :href="item.to"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-3 h-10 px-3 text-sm transition-colors leading-tight text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100"
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
                  class="office-nav-link flex items-center gap-3 mb-1 pr-2 text-sm transition-colors leading-tight"
                  :class="[
                    isActive(item)
                      ? 'bg-primary-50 text-primary-700 font-semibold'
                      : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100',
                    si === 0 ? 'h-11 pl-3' : 'h-9 pl-8',
                    item.newTo && auth.canWrite ? 'pr-12' : '',
                  ]"
                >
                  <OfficeNavIcon :name="navIconNames[item.moduleId] || 'system'" :class="si === 0 ? 'w-5 h-5' : 'w-4 h-4'" />
                  {{ item.label }}
                </RouterLink>
                <RouterLink
                  v-if="item.newTo && auth.canWrite"
                  :to="item.newTo"
                  :title="`${t('nav.quick_new')} — ${item.label}`"
                  :aria-label="`${t('nav.quick_new')} — ${item.label}`"
                  class="office-nav-link absolute right-2 top-[6px] inline-flex items-center justify-center w-8 h-8 text-neutral-500 hover:text-primary-700 hover:bg-primary-100"
                  :class="{ 'text-primary-700': isActive(item) }"
                >
                  <OfficeNavIcon name="plus" class="w-4 h-4" />
                </RouterLink>
              </div>
            </template>
          </component>
        </nav>

        <!-- Verze (dole) — plain text, bez MyInvoice update -->
        <div v-if="false" class="px-4 py-2.5 border-t border-neutral-100 flex items-center gap-2">
          <span class="text-xs text-neutral-500">Triant office</span>
          <span class="text-xs text-neutral-400">v{{ versionInfo }}</span>
        </div>

        <!-- Profil + odhlášení (v hamburgeru na všech šířkách) -->
        <details class="mx-4 mb-4 px-2.5 py-2 bg-neutral-100">
          <summary class="flex gap-2.5 items-center cursor-pointer list-none"><span class="w-8 h-8 rounded-full bg-primary-600 shrink-0"/><span class="text-xs"><strong class="font-semibold">{{auth.user?.name}}</strong><span class="block text-[11px] text-neutral-500">TRIANT</span></span></summary>
          <div class="space-y-2 pt-2">
          <RouterLink
            to="/profile/password"
            @click="mobileOpen = false"
            class="group block min-w-0 rounded-md -ml-2 px-2 py-1.5 text-sm hover:bg-surface"
            :title="t('auth.profile_title')"
          >
            <div class="truncate font-medium text-neutral-900 group-hover:text-primary-700 group-hover:underline">
              {{ auth.user?.name }}
            </div>
            <div class="truncate text-xs text-neutral-500">TRIANT</div>
          </RouterLink>
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
        </details>
      </aside>

      <!-- ── HLAVNÍ OBSAH ── -->
      <div class="flex-1 min-w-0 flex flex-col lg:ml-[248px]">
        <main class="office-page flex-1 w-full max-w-[1280px] mx-auto px-4 sm:px-6 lg:px-10 py-8 lg:py-10">
          <RouterView />
        </main>

        <footer class="hidden">
          <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 text-xs text-neutral-500 flex flex-wrap items-center gap-x-1.5 gap-y-1 leading-none">
            <span>Triant office</span>
          </div>
        </footer>
      </div>
    </div>

  </div>
</template>
