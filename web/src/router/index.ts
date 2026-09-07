import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { triRoutes } from './tri'
import { useSupplierStore } from '@/stores/supplier'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'home', redirect: { name: 'tri-jobs' } },
      { path: 'clients', name: 'clients', redirect: '/tri/contacts' },
      { path: 'clients/new', name: 'client-new', redirect: '/tri/contacts/new' },
      { path: 'clients/:id(\\d+)', name: 'client-detail', redirect: to => `/tri/contacts/${to.params.id}` },
      { path: 'clients/:id(\\d+)/edit', name: 'client-edit', redirect: to => `/tri/contacts/${to.params.id}/edit` },
      { path: 'projects', name: 'projects', redirect: '/tri/jobs' },
      { path: 'projects/new', name: 'project-new', redirect: '/tri/jobs/new' },
      { path: 'projects/:id(\\d+)', name: 'project-detail', redirect: '/tri/jobs' },
      { path: 'projects/:id(\\d+)/edit', name: 'project-edit', redirect: '/tri/jobs' },
      { path: 'invoices', name: 'invoices', redirect: '/tri/invoices' },
      { path: 'invoices/new', name: 'invoice-new', redirect: to => ({ path: '/tri/invoices/new', query: to.query }) },
      { path: 'invoices/:id(\\d+)', name: 'invoice-detail', redirect: to => `/tri/invoices/${to.params.id}` },
      { path: 'invoices/:id(\\d+)/edit', name: 'invoice-edit', redirect: to => `/tri/invoices/${to.params.id}/edit` },
      { path: 'purchase-invoices', name: 'purchase-invoices', redirect: '/tri/jobs' },
      { path: 'purchase-invoices/export', name: 'purchase-invoices-export', redirect: '/tri/jobs' },
      { path: 'purchase-invoices/payment-orders', name: 'purchase-invoices-payment-orders', redirect: '/tri/jobs' },
      { path: 'purchase-invoices/new', name: 'purchase-invoice-new', redirect: '/tri/jobs' },
      { path: 'purchase-invoices/:id(\\d+)', name: 'purchase-invoice-detail', redirect: '/tri/jobs' },
      { path: 'purchase-invoices/:id(\\d+)/edit', name: 'purchase-invoice-edit', redirect: '/tri/jobs' },
      { path: 'documents', name: 'documents', redirect: '/tri/jobs' },
      { path: 'documents/:id(\\d+)', name: 'document-detail', redirect: '/tri/jobs' },
      { path: 'logbook', name: 'logbook', redirect: '/tri/jobs' },
      { path: 'stats', name: 'stats', redirect: '/tri/jobs' },
      { path: 'purchase-stats', name: 'purchase-stats', redirect: '/tri/jobs' },
      { path: 'bank', name: 'bank-statements', redirect: '/tri/jobs' },
      { path: 'bank/:id(\\d+)', name: 'bank-detail', redirect: '/tri/jobs' },
      // Admin (M6)
      { path: 'admin/activity-log',     name: 'activity-log',   component: () => import('@/pages/admin/ActivityLog.vue'), meta: { adminOnly: true } },
      { path: 'admin/sent-emails',      name: 'sent-emails',    component: () => import('@/pages/admin/SentEmails.vue'), meta: { adminOnly: true } },
      { path: 'admin/cron-jobs',        name: 'cron-jobs',      component: () => import('@/pages/admin/CronJobs.vue'),    meta: { adminOnly: true } },
      { path: 'admin/users',            name: 'admin-users',    component: () => import('@/pages/admin/Users.vue'),       meta: { adminOnly: true } },
      { path: 'admin/settings',         name: 'admin-settings', component: () => import('@/pages/admin/Settings.vue'),    meta: { adminOnly: true } },
      // Bývalá stránka Systém → Bankovní účty je nyní součástí /bank (Finance) jako záložky.
      // Redirect zachovává bookmarks vč. původního ?tab=.
      {
        path: 'admin/bank-accounts',
        name: 'admin-bank-accounts',
        redirect: to => ({
          path: '/bank',
          query: { tab: ['accounts', 'balances', 'email'].includes(String(to.query.tab)) ? String(to.query.tab) : 'accounts' },
        }),
      },
      { path: 'admin/bank-email-notices', name: 'admin-bank-email-notices', redirect: '/bank?tab=email' },
      { path: 'admin/tri-settings',     name: 'admin-tri-settings', component: () => import('@/pages/admin/TriSettings.vue'), meta: { adminOnly: true } },
      // /admin/suppliers byla samostatná stránka — Suppliers jsou nyní embedded jako první tab v Codebooks.
      // Redirect zachovává bookmarks / staré odkazy.
      { path: 'admin/suppliers',        name: 'admin-suppliers', redirect: '/admin/codebooks' },
      { path: 'admin/codebooks',        name: 'admin-codebooks', component: () => import('@/pages/admin/Codebooks.vue'),  meta: { adminOnly: true } },
      { path: 'admin/electronic-signatures', name: 'admin-electronic-signatures', component: () => import('@/pages/admin/ElectronicSignatures.vue'), meta: { requiresWrite: true, signingProfiles: true } },
      { path: 'admin/export', name: 'admin-export', redirect: '/tri/jobs' },
      { path: 'admin/import', name: 'admin-import', redirect: '/tri/jobs' },
      { path: 'admin/integrations',     name: 'admin-integrations', component: () => import('@/pages/admin/Integrations.vue'), meta: { adminOnly: true } },
      { path: 'crm', name: 'crm-dashboard', redirect: '/tri/jobs' },
      { path: 'reports/dph', name: 'reports-dph', redirect: '/tri/jobs' },
      { path: 'reports/kh', name: 'reports-kh', redirect: '/tri/jobs' },
      { path: 'reports/dph-book', name: 'reports-dph-book', redirect: '/tri/jobs' },
      { path: 'reports/shv', name: 'reports-shv', redirect: '/tri/jobs' },
      { path: 'reports/income-tax', name: 'reports-income-tax', redirect: '/tri/jobs' },
      { path: 'reports/submissions', name: 'reports-submissions', redirect: '/tri/jobs' },
      { path: 'reports/monthly-export', name: 'reports-monthly-export', redirect: '/tri/jobs' },
      { path: 'reports/oss', name: 'reports-oss', redirect: '/tri/jobs' },
      { path: 'tax', name: 'tax-optimizer', redirect: '/tri/jobs' },
      { path: 'admin/email-templates',  name: 'admin-email-templates', component: () => import('@/pages/admin/EmailTemplates.vue'), meta: { adminOnly: true } },
      // Sekce E-maily — záložky: Odeslané / Šablony / Elektronické podpisy (vzor Codebooks)
      { path: 'admin/emails',           name: 'admin-emails',    component: () => import('@/pages/admin/Emails.vue'), meta: { adminOnly: true } },
      { path: 'admin/approvals', name: 'admin-approvals', redirect: '/tri/jobs' },
      { path: 'admin/price-list', name: 'admin-price-list', redirect: '/tri/price-lists' },
      { path: 'admin/price-list/new', name: 'admin-price-list-new', redirect: '/tri/price-lists/new' },
      { path: 'admin/price-list/:id(\\d+)/edit', name: 'admin-price-list-edit', redirect: to => `/tri/price-lists/${to.params.id}` },
      { path: 'recurring', name: 'recurring', redirect: '/tri/invoices' },
      { path: 'recurring/new', name: 'recurring-new', redirect: '/tri/invoices/new' },
      { path: 'recurring/:id(\\d+)', name: 'recurring-detail', redirect: '/tri/invoices' },
      { path: 'recurring/:id(\\d+)/edit', name: 'recurring-edit', redirect: '/tri/invoices' },
      { path: 'admin/update',           name: 'admin-update',    component: () => import('@/pages/admin/Update.vue'),    meta: { adminOnly: true } },
      // Přechodový nástroj MyInvoice→MyÚčto odstraněn — office je trvalá nadstavba, konverze DB by ji zničila.
      { path: 'admin/upgrade',          name: 'admin-myucto-upgrade', redirect: '/tri/admin/myucto' },
      // Staré profilové URL zůstávají funkční, ale UI je zobrazuje jako záložky
      // na /profile/password. Redirecty zachovávají ostatní query stringy.
      { path: 'profile/totp',           name: 'profile-totp',          redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'totp' } }) },
      { path: 'profile/password',       name: 'profile-password',      component: () => import('@/pages/PasswordChange.vue') },
      { path: 'profile/api-tokens',     name: 'profile-api-tokens',    component: () => import('@/pages/ApiTokens.vue') },
      { path: 'profile/passkeys',       name: 'profile-passkeys',      redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'passkeys' } }) },
      { path: 'profile/session-lock',   name: 'profile-session-lock',  redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'session-lock' } }) },
      { path: 'profile/signing-profiles', name: 'profile-signing-profiles', redirect: '/admin/electronic-signatures' },
      ...triRoutes,
    ],
  },
  { path: '/login',  name: 'login',  component: () => import('@/pages/Login.vue'),          meta: { public: true } },
  { path: '/setup',  name: 'setup',  component: () => import('@/pages/Setup.vue'),          meta: { public: true } },
  { path: '/setup-mfa', name: 'setup-mfa', component: () => import('@/pages/ForcedMfaSetup.vue'), meta: { requiresAuth: true, mfaSetupOnly: true } },
  { path: '/setup-totp', name: 'setup-totp', redirect: { path: '/setup-mfa', query: { method: 'totp' } } },
  { path: '/forgot', name: 'forgot', component: () => import('@/pages/ForgotPassword.vue'), meta: { public: true } },
  { path: '/reset',  name: 'reset',  component: () => import('@/pages/ResetPassword.vue'),  meta: { public: true } },
  { path: '/approval/:token([a-f0-9]{32,128})', name: 'approval',
    component: () => import('@/pages/ApprovalPublic.vue'), meta: { public: true } },
  { path: '/work-report/:token([a-f0-9]{32,128})', name: 'work-report-tracking',
    component: () => import('@/pages/WorkReportTrackingPublic.vue'), meta: { public: true } },
  // Web faktura — veřejný náhled vystavené faktury (singular /invoice/…, interní UI je /invoices/…)
  { path: '/invoice/:token([a-f0-9]{32,128})', name: 'invoice-public',
    component: () => import('@/pages/InvoicePublic.vue'), meta: { public: true } },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFound.vue'),
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  // Scroll-to-top při navigaci sidebar linky; respektuj #hash a back/forward
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (_to.hash) return { el: _to.hash, behavior: 'smooth' }
    return { top: 0, left: 0 }
  },
})

/**
 * Bezpečný fallback při zamítnutí. Vrací `true` (pusť dál), když už cílíme na
 * `home` — jinak by vznikla NEKONEČNÁ smyčka, kdyby dashboard sám propadl
 * některým z gatů níž (přesně tak zamrzl prohlížeč u uživatele bez přístupu
 * k firmě v MyÚčtu). Radši pustit dál a nechat stránku zobrazit prázdný stav
 * nebo chybu z API, než točit prohlížeč donekonečna.
 */
function denyFallback(toName: unknown) {
  return toName === 'tri-jobs' ? true : { name: 'tri-jobs' }
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.setupStatus === null) {
    try {
      await auth.fetchSetupStatus()
    } catch {
      // ignore
    }
  }

  if (auth.needsSetup && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (!auth.needsSetup && to.name === 'setup') {
    return { name: 'login' }
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth)
  if (requiresAuth && !auth.isAuthenticated) {
    // Rozhoduje stav storu, ne návratová hodnota: refresh() při síťovém výpadku
    // vrací false, ale známou identitu si záměrně drží.
    await auth.refresh()
    if (!auth.isAuthenticated) return { name: 'login' }
  }
  if (requiresAuth && auth.lockedSession) {
    useSessionSecurityStore().apply(auth.lockedSession)
    return true
  }
  if (requiresAuth) {
    const sessionSecurity = useSessionSecurityStore()
    if (sessionSecurity.state === null) {
      await sessionSecurity.refresh()
    }
  }

  // Setup session nemá přístup k business routám, dokud uživatel nedokončí MFA.
  const mustSetupMfa = auth.mustSetupMfa || auth.mustSetupTotp
  if (auth.isAuthenticated && mustSetupMfa && to.name !== 'setup-mfa' && requiresAuth) {
    return { name: 'setup-mfa' }
  }
  if (auth.isAuthenticated && !mustSetupMfa && to.name === 'setup-mfa') {
    return { name: 'tri-jobs' }
  }

  // Admin-only stránky
  const adminOnly = to.matched.some((r) => r.meta.adminOnly)
  if (adminOnly && auth.user?.role !== 'admin') {
    return denyFallback(to.name)
  }

  // Zápisové stránky (zakládání/editace dokladů, klientů, zakázek, recurring).
  // readonly smí jen číst/exportovat → na write routes ho přesměrujeme na dashboard.
  const requiresWrite = to.matched.some((r) => r.meta.requiresWrite)
  if (requiresWrite && !auth.canWrite) {
    return denyFallback(to.name)
  }

  // Onboarding gate: pokud uživatel v úvodním nastavení přeskočil dodavatele, nemá v DB
  // žádného supplier-a. Data (klienti, faktury, currencies) jsou supplier-scoped, takže
  // zakládací formuláře by jinak spadly na matoucí „Validace selhala" (#151). Místo toho
  // ho pošleme na dashboard, kde se zobrazí výzva k vytvoření prvního dodavatele.
  const requiresSupplier = to.matched.some((r) => r.meta.requiresSupplier)
  if (requiresSupplier && auth.isAuthenticated && !useSupplierStore().hasSupplier) {
    return denyFallback(to.name)
  }

  // OSS gate: režim je opt-in v nastavení firmy (default vypnuto). Bez registrace nemá
  // kvartální přehled co ukázat, takže na něj nepustíme ani přes přímou URL.
  const requiresOss = to.matched.some((r) => r.meta.requiresOss)
  if (requiresOss && auth.isAuthenticated && useSupplierStore().currentSupplier?.oss_enabled !== true) {
    return denyFallback(to.name)
  }

  const signingProfiles = to.matched.some((r) => r.meta.signingProfiles)
  if (signingProfiles && auth.user?.role !== 'admin' && auth.user?.role !== 'accountant') {
    return denyFallback(to.name)
  }

  return true
})
