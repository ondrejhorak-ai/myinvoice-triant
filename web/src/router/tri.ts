import type { RouteRecordRaw } from 'vue-router'

export const triRoutes: RouteRecordRaw[] = [
  { path: 'tri/contacts', name: 'tri-contacts', component: () => import('@/pages/tri/contacts/ContactList.vue') },
  { path: 'tri/contacts/new', name: 'tri-contact-new', component: () => import('@/pages/tri/contacts/ContactForm.vue'), meta: { requiresWrite: true } },
  { path: 'tri/contacts/:id(\\d+)', name: 'tri-contact-detail', component: () => import('@/pages/tri/contacts/ContactDetail.vue') },
  { path: 'tri/contacts/:id(\\d+)/edit', name: 'tri-contact-edit', component: () => import('@/pages/tri/contacts/ContactForm.vue'), meta: { requiresWrite: true } },
  { path: 'tri/jobs', name: 'tri-jobs', component: () => import('@/pages/tri/jobs/JobList.vue') },
  { path: 'tri/jobs/new', name: 'tri-job-new', component: () => import('@/pages/tri/jobs/JobForm.vue'), meta: { requiresWrite: true } },
  { path: 'tri/jobs/:id(\\d+)', name: 'tri-job-detail', component: () => import('@/pages/tri/jobs/JobDetail.vue') },
  { path: 'tri/jobs/:id(\\d+)/edit', name: 'tri-job-edit', component: () => import('@/pages/tri/jobs/JobForm.vue'), meta: { requiresWrite: true } },
  { path: 'tri/jobs/:jobId(\\d+)/variants/:variantId(\\d+)', name: 'tri-variant-edit', component: () => import('@/pages/tri/jobs/VariantEditor.vue'), meta: { requiresWrite: true } },
  { path: 'tri/invoices', name: 'tri-invoices', component: () => import('@/pages/tri/invoices/InvoiceList.vue') },
  { path: 'tri/invoices/new', name: 'tri-invoice-new', component: () => import('@/pages/tri/invoices/InvoiceEditor.vue'), meta: { requiresWrite: true } },
  { path: 'tri/invoices/:id(\\d+)', name: 'tri-invoice-detail', component: () => import('@/pages/tri/invoices/InvoiceDetail.vue') },
  { path: 'tri/invoices/:id(\\d+)/edit', name: 'tri-invoice-edit', component: () => import('@/pages/tri/invoices/InvoiceEditor.vue'), meta: { requiresWrite: true } },
  { path: 'admin/tri-tags', name: 'tri-tags', component: () => import('@/pages/tri/admin/TagAdmin.vue'), meta: { adminOnly: true } },
]
