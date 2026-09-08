<script setup lang="ts">
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { invoicesApi, type Invoice, type WorkReport, type InvoiceAttachment, type AdvanceCandidate } from '@/api/invoices'
import { triInvoicesApi } from '@/api/triInvoices'
import { clientsApi, type Client } from '@/api/clients'
import { clientMissingAddress } from '@/utils/clientCompleteness'
import {
  settingsApi,
  type PdfSignatureDocumentEntityType,
  type PdfSignatureDocumentSelection,
  type PdfSignatureDocumentSelectionSource,
  type SigningProfile,
} from '@/api/settings'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate, formatPercent, statusLabel, typeLabel, statusBadgeClass } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import WorkReportModal from '@/components/modals/WorkReportModal.vue'
import { triApi, type TriJobLink } from '@/api/tri'
import UiButton from '@/components/ui/UiButton.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiBadge from '@/components/ui/UiBadge.vue'

const { t, locale } = useI18n()
const toast = useToast()

const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')
/** Cyklus vystavit / odeslat / platby je přes MyÚčto (H5). */
const h5Ready = true

const supplierStore = useSupplierStore()
const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)

function formatRate(rate: number): string {
  const tag = locale.value === 'cs' ? 'cs-CZ' : 'en-US'
  return rate.toLocaleString(tag, { minimumFractionDigits: 3, maximumFractionDigits: 4 })
}

const route = useRoute()
const router = useRouter()

const invoice = ref<Invoice | null>(null)
const clientForWarnings = ref<Client | null>(null)
const clientAddressMissing = computed(() =>
  clientForWarnings.value ? clientMissingAddress(clientForWarnings.value) : false,
)
const triJob = ref<TriJobLink | null>(null)
const wrModalOpen = ref(false)
const loading = ref(true)
const busy = ref<string | null>(null)

// V režimu „ceny s DPH" nese unit_price_without_vat brutto (kvůli haléřově přesnému
// výpočtu DPH koeficientem). Pro zobrazení proto ukazujeme skutečné NETTO dopočtené
// z uloženého řádkového základu (total_without_vat / množství). V běžném režimu je
// unit_price_without_vat už netto → vracíme ho beze změny.
function displayUnitPriceNet(item: { quantity: number; unit_price_without_vat: number; total_without_vat?: number }): number {
  if (invoice.value?.prices_include_vat && Number(item.quantity)) {
    return Math.round(((item.total_without_vat ?? 0) / Number(item.quantity)) * 100) / 100
  }
  return item.unit_price_without_vat
}

// Cancel modal state
const cancelOpen = ref(false)
const cancelMode = ref<'internal' | 'credit_note' | 'delete'>('credit_note')
const cancelReason = ref('')

// Send modal state
const sendOpen = ref(false)
const sendTo = ref('')
const sendHasNoRecipient = computed(() => sendOpen.value && !sendTo.value.trim())
const sendNote = ref('')

// Reminder modal state
const reminderOpen = ref(false)

// Approval modals
const activity = ref<Array<{ id: number; user_email: string | null; user_name: string | null; action: string; payload: any; ip: string | null; created_at: string }>>([])
const activityOpen = ref(false)
const pdfHistory = ref<Array<{ id: number; filename: string; size_bytes: number; sha256: string; was_sent: boolean; sent_to: string[] | null; reason: string; archived_at: string }>>([])
const pdfHistoryOpen = ref(false)
const attachments = ref<InvoiceAttachment[]>([])
const attachmentsBusy = ref(false)
const attachmentsDragOver = ref(false)
const attachmentInput = ref<HTMLInputElement | null>(null)
const workReport = ref<WorkReport | null>(null)
const signatureSelections = ref<Partial<Record<PdfSignatureDocumentEntityType, PdfSignatureDocumentSelection>>>({})
const signingProfiles = ref<SigningProfile[]>([])
const signatureSelectionLoading = ref(false)
const signatureSelectionSaving = ref<PdfSignatureDocumentEntityType | null>(null)
const hasPdfSigningProfiles = computed(() => signingProfiles.value.some(
  profile => profile.is_active && profile.allowed_usages.includes('pdf'),
))
// Kartu „Elektronický podpis dokumentu" ukážeme jen když je podepisování pro
// tohoto dodavatele skutečně nastavené (existuje aktivní profil s PDF využitím).
// Jinak by ji u každé faktury viděl i uživatel, který nikdy nepodepisuje (balast).
const canManageSignatureSelection = computed(() => auth.canWrite && hasPdfSigningProfiles.value)
const adminSigningProfiles = computed(() => signingProfiles.value.filter(
  profile => profile.owner_user_id === null && profile.is_active && profile.allowed_usages.includes('pdf'),
))
// Pravdivá indikace pro badge „Podepsáno": backend říká, zda se TENTO doklad
// reálně podepíše (zapnutý výstup + resolvovatelný profil s certifikátem),
// ne jen že existuje nějaký profil.
const invoiceWillBeSigned = computed(() => signatureSelection('invoice')?.effective_will_sign === true)
const signatureSelectionRows = computed(() => {
  const rows: Array<{ entityType: PdfSignatureDocumentEntityType; label: string }> = [
    { entityType: 'invoice', label: t('invoice.signing.output_invoice') as string },
  ]
  if (workReport.value) rows.push({ entityType: 'work_report', label: t('invoice.signing.output_work_report') as string })
  return rows
})

async function load() {
  loading.value = true
  invoice.value = await triInvoicesApi.get(Number(route.params.id))
  loading.value = false
  clientForWarnings.value = null
  if (invoice.value?.client_id) {
    clientsApi.get(invoice.value.client_id).then(c => { clientForWarnings.value = c }).catch(() => {})
  }
  if (auth.canWrite) {
    await loadSignatureProfiles()
    if (hasPdfSigningProfiles.value) loadSignatureSelection('invoice')
  }
  // Activity log + work report + PDF historie (parallel, ne blokuje UI)
  invoicesApi.activity(Number(route.params.id))
    .then(a => { activity.value = a })
    .catch(() => {})
  invoicesApi.getWorkReport(Number(route.params.id))
    .then(wr => {
      workReport.value = wr
      if (wr && canManageSignatureSelection.value) loadSignatureSelection('work_report')
    })
    .catch(() => {})
  invoicesApi.listPdfs(Number(route.params.id))
    .then(items => { pdfHistory.value = items })
    .catch(() => {})
  invoicesApi.listAttachments(Number(route.params.id))
    .then(items => { attachments.value = items })
    .catch(() => {})
  triApi.invoices.getJob(Number(route.params.id))
    .then((job) => { triJob.value = job })
    .catch(() => { triJob.value = null })
}

async function loadSignatureProfiles() {
  try {
    signingProfiles.value = await settingsApi.listSigningProfiles()
  } catch {
    signingProfiles.value = []
  }
}

async function loadSignatureSelection(entityType: PdfSignatureDocumentEntityType) {
  if (!invoice.value) return
  signatureSelectionLoading.value = true
  try {
    const selection = await settingsApi.getPdfSignatureDocumentSelection(entityType, invoice.value.id)
    signatureSelections.value = { ...signatureSelections.value, [entityType]: selection }
  } catch {
    // UI zůstane bez řádku nastavení, pokud endpoint není pro roli dostupný.
  } finally {
    signatureSelectionLoading.value = false
  }
}

function signatureSelection(entityType: PdfSignatureDocumentEntityType): PdfSignatureDocumentSelection | null {
  return signatureSelections.value[entityType] || null
}

function setSignatureSelectionSource(entityType: PdfSignatureDocumentEntityType, source: PdfSignatureDocumentSelectionSource) {
  const current = signatureSelection(entityType)
  if (!current) return
  signatureSelections.value = {
    ...signatureSelections.value,
    [entityType]: {
      ...current,
      selection_source: source,
      admin_profile_id: source === 'admin_profile_settings' ? current.admin_profile_id : null,
    },
  }
}

function setSignatureAdminProfile(entityType: PdfSignatureDocumentEntityType, profileId: number | null) {
  const current = signatureSelection(entityType)
  if (!current) return
  signatureSelections.value = {
    ...signatureSelections.value,
    [entityType]: {
      ...current,
      admin_profile_id: profileId,
    },
  }
}

function signatureSelectionSourceLabel(source: string): string {
  if (source === 'inherit') return t('invoice.signing.source_inherit') as string
  return t(`settings.signing_output_source_${source}`) as string
}

function signatureProfileName(profileId: number | null): string {
  if (profileId === null) return t('settings.signing_output_profile_none') as string
  return signingProfiles.value.find(profile => profile.id === profileId)?.name || `#${profileId}`
}

async function saveSignatureSelection(entityType: PdfSignatureDocumentEntityType) {
  if (!invoice.value) return
  const selection = signatureSelection(entityType)
  if (!selection) return
  signatureSelectionSaving.value = entityType
  try {
    const saved = await settingsApi.updatePdfSignatureDocumentSelection(entityType, invoice.value.id, {
      selection_source: selection.selection_source,
      admin_profile_id: selection.selection_source === 'admin_profile_settings' && isAdmin.value
        ? selection.admin_profile_id
        : null,
    })
    signatureSelections.value = { ...signatureSelections.value, [entityType]: saved }
    toast.success(t('invoice.signing.saved'))
    if (entityType === 'invoice') {
      invoicesApi.listPdfs(invoice.value.id).then(items => { pdfHistory.value = items }).catch(() => {})
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    signatureSelectionSaving.value = null
  }
}

function attachmentsAvailable(inv: Invoice | null): boolean {
  if (!inv) return false
  return ['invoice', 'proforma', 'credit_note'].includes(inv.invoice_type)
}

async function uploadAttachmentFiles(files: File[]) {
  if (!invoice.value || files.length === 0) return
  attachmentsBusy.value = true
  try {
    const r = await invoicesApi.uploadAttachments(invoice.value.id, files)
    attachments.value = r.items
    toast.success(t('invoice.attachments.upload_done', { n: r.created.length }))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('invoice.attachments.upload_failed')))
  } finally {
    attachmentsBusy.value = false
    if (attachmentInput.value) attachmentInput.value.value = ''
  }
}

async function onAttachmentInputChange(ev: Event) {
  const input = ev.target as HTMLInputElement
  const files = input.files ? Array.from(input.files) : []
  await uploadAttachmentFiles(files)
}

async function onAttachmentDrop(ev: DragEvent) {
  ev.preventDefault()
  attachmentsDragOver.value = false
  const files = ev.dataTransfer?.files ? Array.from(ev.dataTransfer.files) : []
  await uploadAttachmentFiles(files)
}

async function deleteAttachment(att: InvoiceAttachment) {
  if (!invoice.value) return
  if (!window.confirm(t('invoice.attachments.confirm_delete', { name: att.original_name }))) return
  try {
    await invoicesApi.deleteAttachment(invoice.value.id, att.id)
    attachments.value = attachments.value.filter(a => a.id !== att.id)
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('invoice.attachments.delete_failed')))
  }
}

function pdfReasonLabel(reason: string): string {
  const map: Record<string, string> = {
    'sent': 'invoice.pdf_history.reason.sent',
    'invalidate_update': 'invoice.pdf_history.reason.update',
    'invalidate_issue': 'invoice.pdf_history.reason.issue',
    'invalidate_allocate': 'invoice.pdf_history.reason.allocate',
    'invalidate_workreport': 'invoice.pdf_history.reason.workreport',
    'invalidate_signature_selection': 'invoice.pdf_history.reason.signature_selection',
    'invalidate_signature_config': 'invoice.pdf_history.reason.signature_config',
    'approval_request': 'invoice.pdf_history.reason.approval_request',
    'approval_reminder': 'invoice.pdf_history.reason.approval_reminder',
    'invalidate_currency': 'invoice.pdf_history.reason.currency',
    'invalidate_manual': 'invoice.pdf_history.reason.manual',
    'backfill_sent': 'invoice.pdf_history.reason.backfill_sent',
  }
  return map[reason] ? (t(map[reason]) as string) : reason
}

function formatBytes(n: number): string {
  if (n < 1024) return n + ' B'
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB'
  return (n / (1024 * 1024)).toFixed(2) + ' MB'
}

onMounted(load)
// Detail se recykluje při navigaci /tri/invoices/:id → :id (proklik na související doklad,
// dobropis/parent) → onMounted se znovu nespustí, proto přenačtení řídí watch.
watch(() => route.params.id, load)

function actionLabel(a: string): string {
  const map: Record<string, string> = {
    'invoice.created': 'invoice.actions.created',
    'invoice.updated': 'invoice.actions.updated',
    'invoice.force_updated': 'invoice.actions.force_updated',
    'invoice.issued': 'invoice.actions.issued',
    'invoice.paid': 'invoice.actions.paid',
    'invoice.cancelled': 'invoice.actions.cancelled',
    'invoice.cloned': 'invoice.actions.cloned',
    'invoice.credit_note_created': 'invoice.actions.credit_note_created',
    'invoice.reminder_sent': 'invoice.actions.reminder_sent',
    'invoice.sent': 'invoice.actions.sent',
    'email.sent': 'invoice.actions.email_sent',
    'email.sent_test': 'invoice.actions.email_sent_test',
    'email.sent_test_reminder': 'invoice.actions.email_sent_test_reminder',
    'pdf.generated': 'invoice.actions.pdf_generated',
    'invoice.approval_requested':     'invoice.actions.approval_requested',
    'invoice.approval_request_test':  'invoice.actions.approval_request_test',
    'invoice.approval_approved':      'invoice.actions.approval_approved',
    'invoice.approval_rejected':      'invoice.actions.approval_rejected',
    'invoice.approval_reset':         'invoice.actions.approval_reset',
    'proforma.final_issued':          'invoice.actions.proforma_final_issued',
  }
  return map[a] ? (t(map[a]) as string) : a
}

function actionColor(a: string): string {
  if (a.includes('reminder')) return 'bg-warning-50 text-warning-600'
  if (a.includes('approval_approved')) return 'bg-success-50 text-success-600'
  if (a.includes('approval_rejected')) return 'bg-danger-50 text-danger-500'
  if (a.includes('approval')) return 'bg-primary-100 text-primary-700'
  if (a.includes('issued') || a.includes('paid') || a.includes('sent')) return 'bg-success-50 text-success-600'
  if (a.includes('cancelled') || a.includes('force')) return 'bg-warning-50 text-warning-600'
  if (a.includes('credit_note') || a.includes('cloned')) return 'bg-primary-100 text-primary-700'
  return 'bg-neutral-100 text-neutral-600'
}

function payloadText(payload: any): string {
  if (!payload) return ''
  return Object.entries(payload)
    .map(([k, v]) => k + '=' + (typeof v === 'object' ? JSON.stringify(v) : String(v)))
    .join(' · ')
}

async function deleteInvoice() {
  if (!invoice.value) return
  // Pro cancellation doklad: smaž PARENT (cascade pak odstraní i tento storno),
  // jinak by zůstala originálka v 'cancelled' bez storno dokladu — interní storno
  // bez parenta nedává smysl jako samostatný účetní doklad.
  // Dobropis se ale maže samostatně — je to plnohodnotný účetní doklad, parent
  // zůstává v cancelled stavu (admin si může případně ručně upravit).
  if (invoice.value.invoice_type === 'cancellation' && invoice.value.parent_invoice_id) {
    return deleteCancellationParent()
  }
  // Per-status confirm — pro vystavené/odeslané/zaplacené/stornované je delší vysvětlující
  // hláška (force-delete účetního dokladu, cascade na storno/dobropis).
  // UI tlačítko force-delete se admin-only zobrazuje (canDelete), backend má stejný guard.
  const status = invoice.value.status
  const isCN = invoice.value.invoice_type === 'credit_note'
  let confirmKey: string
  switch (status) {
    case 'draft':     confirmKey = isCN ? 'invoice.delete_draft_confirm_cn'     : 'invoice.delete_draft_confirm';     break
    case 'cancelled': confirmKey = isCN ? 'invoice.delete_cancelled_confirm_cn' : 'invoice.delete_cancelled_confirm'; break
    case 'paid':      confirmKey = 'invoice.delete_paid_confirm';                                                     break
    case 'sent':      confirmKey = isCN ? 'invoice.delete_sent_confirm_cn'      : 'invoice.delete_sent_confirm';      break
    case 'issued':
    case 'reminded':
    default:          confirmKey = isCN ? 'invoice.delete_issued_confirm_cn'    : 'invoice.delete_issued_confirm';    break
  }
  const vs = invoice.value.varsymbol || `#${invoice.value.id}`
  if (!confirm(t(confirmKey, { varsymbol: vs }))) return
  busy.value = 'delete'
  try {
    await triInvoicesApi.remove(invoice.value.id)
    router.push('/tri/invoices')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.delete_failed'))
  } finally {
    busy.value = null
  }
}

async function deleteCancellationParent() {
  if (!invoice.value || !invoice.value.parent_invoice_id) return
  const parentId = invoice.value.parent_invoice_id
  // Najdi varsymbol parenta pro hezčí confirm — fallback na #id
  let parentVs = `#${parentId}`
  try {
    const parent = await invoicesApi.get(parentId)
    if (parent?.varsymbol) parentVs = parent.varsymbol
  } catch { /* ignore — fallback stačí */ }
  if (!confirm(t('invoice.delete_cancelled_confirm', { varsymbol: parentVs }))) return
  busy.value = 'delete'
  try {
    const res = await invoicesApi.delete(parentId)
    if (res?.cascade_deleted && res.cascade_deleted > 0) {
      toast.success(t('invoice.deleted_with_cascade', { n: res.cascade_deleted }))
    }
    router.push('/tri/invoices')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.delete_failed'))
  } finally {
    busy.value = null
  }
}

async function issue() {
  if (!invoice.value || invoice.value.status !== 'draft') return
  if (invoice.value.items.length === 0) {
    toast.error( t('invoice.issue_no_items'))
    return
  }
  if (!canIssueDraft.value) {
    toast.error(t('invoice.amount_positive_required'))
    return
  }
  if (!confirm(t('invoice.issue_confirm'))) return
  busy.value = 'issue'
  try {
    invoice.value = await triInvoicesApi.issue(invoice.value.id)
    toast.success( t('invoice.issued_as', { varsymbol: invoice.value.varsymbol }))
    invoicesApi.activity(invoice.value.id).then(a => { activity.value = a }).catch(() => {})
    invoicesApi.listPdfs(invoice.value.id).then(items => { pdfHistory.value = items }).catch(() => {})
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.issue_failed'))
  } finally {
    busy.value = null
  }
}

const paidAtInput = ref<string>(new Date().toISOString().slice(0, 10))
const markPaidOpen = ref(false)

useHotkey('escape', () => {
  if (markPaidOpen.value)     markPaidOpen.value = false
  else if (cancelOpen.value)  cancelOpen.value = false
  else if (sendOpen.value)    sendOpen.value = false
  else if (reminderOpen.value) reminderOpen.value = false
})

// Děkovný e-mail za úhradu (issue #57)
const sendThanks = ref(false)
const thanksEnabled = computed(() => supplierStore.currentSupplier?.payment_thanks_enabled ?? false)
const thanksHasRecipient = computed(() => !!invoice.value?.client_main_email)
function openMarkPaid() {
  paidAtInput.value = new Date().toISOString().slice(0, 10)
  sendThanks.value = thanksEnabled.value && thanksHasRecipient.value
    && (supplierStore.currentSupplier?.payment_thanks_default_checked ?? false)
  markPaidOpen.value = true
}

async function markPaid() {
  if (!invoice.value) return
  busy.value = 'paid'
  try {
    invoice.value = await triInvoicesApi.markPaid(invoice.value.id, paidAtInput.value)
    markPaidOpen.value = false
    toast.success( t('invoice.marked_paid_at', { date: paidAtInput.value }))
    const pt = invoice.value.payment_thanks
    if (pt?.status === 'sent') toast.success(t('invoice.payment_thanks_sent'))
    else if (pt?.status === 'failed') toast.warning(t('invoice.payment_thanks_failed'))
    else if (pt?.status === 'skipped' && pt.reason === 'no_recipient') toast.warning(t('invoice.payment_thanks_no_recipient'))
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.operation_failed'))
  } finally {
    busy.value = null
  }
}

async function unmarkPaid() {
  if (!invoice.value) return
  if (!window.confirm(t('invoice.unmark_paid_confirm', { varsymbol: invoice.value.varsymbol || '' }))) return
  busy.value = 'unmark-paid'
  try {
    invoice.value = await triInvoicesApi.unmarkPaid(invoice.value.id)
    toast.success(t('invoice.unmark_paid_done'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.operation_failed'))
  } finally {
    busy.value = null
  }
}

function openCancelModal() {
  // Dobropisu nelze vystavit další dobropis — default mode = internal.
  cancelMode.value = isCreditNoteSource.value ? 'internal' : 'credit_note'
  cancelOpen.value = true
}

async function cancel() {
  if (!invoice.value) return
  // 3. možnost v modalu — force-delete účetního dokladu (admin only).
  // Modal jen otevře potvrzovací dialog s detailním per-status warningem v deleteInvoice().
  if (cancelMode.value === 'delete') {
    cancelOpen.value = false
    await deleteInvoice()
    return
  }
  busy.value = 'cancel'
  try {
    const result = await invoicesApi.cancel(invoice.value.id, cancelMode.value, cancelReason.value)
    cancelOpen.value = false
    cancelReason.value = ''
    if (result.credit_note_id) {
      router.push(`/tri/invoices/${result.credit_note_id}/edit`)
    } else {
      await load()
      toast.success( t('invoice.cancelled_ok'))
    }
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.cancel_failed'))
  } finally {
    busy.value = null
  }
}

async function issueFinalFromProforma() {
  if (!invoice.value) return
  if (invoice.value.invoice_type !== 'proforma' || invoice.value.status !== 'paid') return
  if (!confirm(t('invoice.issue_final_confirm', { varsymbol: invoice.value.varsymbol || `#${invoice.value.id}` }))) return
  busy.value = 'issue-final'
  try {
    // MyÚčto vytvoří NOVÝ finální doklad navázaný na zálohu a vrátí ho celý.
    const r = await triInvoicesApi.issueFinal(invoice.value.id)
    if (!r?.id) {
      toast.error(t('invoice.invalid_response'))
      return
    }
    toast.success(t('invoice.issue_final_ok'))
    router.push(`/tri/invoices/${r.id}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.issue_final_failed'))
  } finally {
    busy.value = null
  }
}

// ── Propojení zálohové faktury (proforma) ↔ daňového dokladu — z obou stran ──
// 'advance' = jsem na daňovém dokladu, vybírám zálohu; 'final' = jsem na záloze,
// vybírám daňový doklad. Vazba (parent_invoice_id) se vždy ukládá na daňový doklad.
const advanceModalOpen = ref(false)
const pairMode = ref<'advance' | 'final'>('advance')
const advanceCandidates = ref<AdvanceCandidate[]>([])
const loadingCandidates = ref(false)
const linkingAdvance = ref(false)

// Daňový doklad bez rodiče → lze zpětně spárovat se zálohou.
const canPairAdvance = computed(() =>
  !!invoice.value
  && invoice.value.invoice_type === 'invoice'
  && !invoice.value.parent_invoice
  && invoice.value.has_advance_candidates === true
  && auth.canWrite)
// Nepropojená proforma → lze spárovat s daňovým dokladem (opačný směr).
const canPairFinal = computed(() =>
  !!invoice.value
  && invoice.value.invoice_type === 'proforma'
  && !invoice.value.final_invoice
  && invoice.value.has_final_candidates === true
  && auth.canWrite)
// Propojeno se zálohou (proforma) → lze odpojit. Rodič storna/dobropisu (non-proforma) ne.
const linkedProforma = computed(() =>
  invoice.value?.parent_invoice?.invoice_type === 'proforma' ? invoice.value.parent_invoice : null)

async function openPairModal(mode: 'advance' | 'final') {
  if (!invoice.value) return
  pairMode.value = mode
  advanceModalOpen.value = true
  loadingCandidates.value = true
  try {
    advanceCandidates.value = mode === 'advance'
      ? await invoicesApi.advanceCandidates(invoice.value.id)
      : await invoicesApi.finalCandidates(invoice.value.id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loadingCandidates.value = false
  }
}

// candId = id druhého dokladu (zálohy nebo daňového dokladu, dle pairMode).
async function pickCandidate(candId: number) {
  if (!invoice.value || linkingAdvance.value) return
  linkingAdvance.value = true
  try {
    if (pairMode.value === 'advance') {
      // Jsem na daňovém dokladu, candId = záloha → vazba na current.
      invoice.value = await invoicesApi.linkAdvance(invoice.value.id, candId)
    } else {
      // Jsem na záloze, candId = daňový doklad → vazba na něj, pak obnovím proformu.
      await invoicesApi.linkAdvance(candId, invoice.value.id)
      await load()
    }
    advanceModalOpen.value = false
    toast.success(t('invoice.advance_link.linked'))
    if (invoice.value) {
      invoicesApi.activity(invoice.value.id).then(a => { activity.value = a }).catch(() => {})
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    linkingAdvance.value = false
  }
}

// targetId = id daňového dokladu, jehož vazbu rušíme. Default = aktuální doklad
// (jsem na daňovém dokladu). Z detailu zálohy předáme id navázané faktury → reload.
async function unlinkAdvance(targetId?: number) {
  if (!invoice.value || linkingAdvance.value) return
  if (!confirm(t('invoice.advance_link.unlink_confirm'))) return
  linkingAdvance.value = true
  try {
    if (targetId && targetId !== invoice.value.id) {
      await invoicesApi.unlinkAdvance(targetId)
      await load()
    } else {
      invoice.value = await invoicesApi.unlinkAdvance(invoice.value.id)
    }
    toast.success(t('invoice.advance_link.unlinked'))
    if (invoice.value) {
      invoicesApi.activity(invoice.value.id).then(a => { activity.value = a }).catch(() => {})
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    linkingAdvance.value = false
  }
}

async function cloneInvoice() {
  if (!invoice.value) return
  if (!confirm(t('invoice.clone_confirm', { varsymbol: invoice.value.varsymbol || `#${invoice.value.id}` }))) return
  busy.value = 'clone'
  try {
    const r = await triInvoicesApi.clone(invoice.value.id)
    if (!r?.id) {
      toast.error( t('invoice.invalid_response'))
      return
    }
    router.push(`/tri/invoices/${r.id}/edit`)
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.clone_failed'))
  } finally {
    busy.value = null
  }
}

function editIssued() {
  if (!invoice.value) return
  const ok = confirm(t('invoice.edit_issued_confirm', {
    varsymbol: invoice.value.varsymbol || '',
    sent: invoice.value.sent_at ? t('invoice.edit_issued_confirm_sent') : '',
  }))
  if (!ok) return
  router.push(`/tri/invoices/${invoice.value.id}/edit?force=1`)
}

function downloadPdf() {
  if (!invoice.value) return
  window.open(triInvoicesApi.pdfUrl(invoice.value.id, false), '_blank')
}

async function sendTest() {
  if (!invoice.value) return
  busy.value = 'send-test'
  try {
    const r = await invoicesApi.sendTest(invoice.value.id)
    toast.success( t('invoice.send_test_done', { recipients: r.sent_to.join(', ') }))
    invoicesApi.activity(invoice.value.id).then(a => { activity.value = a }).catch(() => {})
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.send_test_failed'))
  } finally {
    busy.value = null
  }
}

async function sendTestReminder() {
  if (!invoice.value) return
  busy.value = 'send-test-reminder'
  try {
    const r = await invoicesApi.sendTestReminder(invoice.value.id)
    toast.success( t('invoice.send_test_reminder_done', { recipients: r.sent_to.join(', '), days: r.days_overdue }))
    invoicesApi.activity(invoice.value.id).then(a => { activity.value = a }).catch(() => {})
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.send_test_reminder_failed'))
  } finally {
    busy.value = null
  }
}

const canIssueDraft = computed(() => {
  if (!invoice.value) return false
  if (invoice.value.invoice_type === 'proforma') {
    return Number(invoice.value.amount_to_pay ?? 0) > 0
  }
  if (invoice.value.invoice_type === 'invoice' && invoice.value.parent_invoice_id) {
    return true
  }
  if (invoice.value.invoice_type !== 'invoice') return true
  return Number(invoice.value.amount_to_pay ?? 0) > 0
})

const canSendTestReminder = computed(() =>
  invoice.value
  && invoice.value.invoice_type === 'invoice'
  && (invoice.value.payment_method ?? 'bank_transfer') === 'bank_transfer'
  && Number(invoice.value.amount_to_pay ?? 0) > 0
)

function openSendModal() {
  if (!invoice.value) return
  sendNote.value = ''
  sendTo.value = ''
  void (async () => {
    try {
      const r = await triInvoicesApi.recipients(invoice.value!.id)
      const to = r.to ?? r.data?.to ?? []
      sendTo.value = Array.isArray(to) ? to.join(', ') : String(to || '')
    } catch {
      const main = invoice.value!.client_main_email || ''
      const billing = (invoice.value!.project_billing_emails || []).map(b => b.email)
      sendTo.value = Array.from(new Set([main, ...billing].filter(Boolean))).join(', ')
    }
    sendOpen.value = true
  })()
}

async function send() {
  if (!invoice.value) return
  const recipients = sendTo.value.split(',').map(e => e.trim()).filter(Boolean)
  if (!recipients.length) {
    toast.error( t('invoice.recipients_required'))
    return
  }
  busy.value = 'send'
  try {
    const note = sendNote.value.trim()
    await triInvoicesApi.send(invoice.value.id, {
      to: recipients,
      ...(note ? { note } : {}),
    })
    sendOpen.value = false
    toast.success( t('invoice.send_done', { recipients: recipients.join(', ') }))
    await load()
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.send_failed'))
  } finally {
    busy.value = null
  }
}

const isDraft = computed(() => invoice.value?.status === 'draft')
const isProforma = computed(() => invoice.value?.invoice_type === 'proforma')
const canIssueFinal = computed(() => isProforma.value && invoice.value?.status === 'paid')
const isIssued = computed(() => invoice.value && ['issued', 'sent', 'reminded'].includes(invoice.value.status))
// Admin „force" edit už vystaveného dokladu — viz tlačítko „Upravit (admin)".
const canAdminEdit = computed(() =>
  isAdmin.value && !isDraft.value && invoice.value?.invoice_type !== 'cancellation')
const hasPositiveAmountToPay = computed(() => {
  if (!invoice.value) return false
  if (!['invoice', 'proforma'].includes(invoice.value.invoice_type)) return true
  return Number(invoice.value.amount_to_pay ?? 0) > 0
})
// Zrcadlí backend InvoiceAmountPolicy::canBeMarkedPaid(): finální daňový doklad
// k zaplacené proformě má amount_to_pay = 0 (záloha pokryla celek), přesto je
// legitimní ho označit za zaplacený — inkaso (kasová metoda) se totiž do
// cash-flow/limitu paušální daně promítá až přes finální doklad, ne přes proformu.
const canMarkPaid = computed(() => {
  if (!invoice.value) return false
  if (invoice.value.invoice_type === 'invoice' && invoice.value.parent_invoice_id) return true
  return hasPositiveAmountToPay.value
})
const canCancel = computed(() => false)
// Dobropisu nelze vystavit další dobropis — v modalu skryjeme tu volbu.
const isCreditNoteSource = computed(() => invoice.value?.invoice_type === 'credit_note')
// Cancellation = interní storno doklad, nikdy se neposílá klientovi (na rozdíl od dobropisu)
const canSendEmail = computed(() =>
  invoice.value
  && ['issued', 'sent', 'reminded', 'paid'].includes(invoice.value.status)
  && invoice.value.invoice_type !== 'cancellation'
)
const canSendTest = computed(() => invoice.value && invoice.value.invoice_type !== 'cancellation')

// Upomínka — jen pro běžnou fakturu (ne proforma/dobropis/storno) ve stavu issued/sent/reminded,
// po splatnosti a placenou bankovním převodem (kartové/hotovostní úhrady se neupomínají).
const canSendReminder = computed(() => {
  if (!invoice.value) return false
  if (invoice.value.invoice_type !== 'invoice') return false
  if (!['issued', 'sent', 'reminded'].includes(invoice.value.status)) return false
  if ((invoice.value.payment_method ?? 'bank_transfer') !== 'bank_transfer') return false
  if (Number(invoice.value.amount_to_pay ?? 0) <= 0) return false
  const due = new Date(invoice.value.due_date)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return due < today
})

const daysOverdue = computed(() => {
  if (!invoice.value) return 0
  const due = new Date(invoice.value.due_date)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  due.setHours(0, 0, 0, 0)
  return Math.max(0, Math.floor((today.getTime() - due.getTime()) / 86_400_000))
})

function openReminderModal() {
  if (!invoice.value) return
  reminderOpen.value = true
}

async function sendReminder() {
  if (!invoice.value) return
  busy.value = 'reminder'
  try {
    invoice.value = await triInvoicesApi.reminder(invoice.value.id)
    reminderOpen.value = false
    toast.success(t('invoice.reminder_sent_ok', { recipients: sendTo.value || '—', days: daysOverdue.value }))
  } catch (e: any) {
    toast.error( e?.response?.data?.error?.message || t('invoice.reminder_failed'))
  } finally {
    busy.value = null
  }
}

// ───── Schvalování výkazu zákazníkem ─────────────────────────────────
const requiresApproval = computed(() =>
  !!invoice.value?.project_requires_approval && !!workReport.value
)
const approvalStatus = computed(() => invoice.value?.approval_status ?? 'none')
const canRequestApproval = computed(() =>
  requiresApproval.value && invoice.value?.status === 'draft' && canIssueDraft.value
)
const approvalTokenExpired = computed(() => {
  if (approvalStatus.value !== 'requested') return false
  const exp = invoice.value?.approval_token_expires_at
  if (!exp) return false
  return new Date(exp) < new Date()
})
const approvalBadgeClass = computed(() => {
  if (approvalTokenExpired.value) return 'bg-warning-50 text-warning-600'
  switch (approvalStatus.value) {
    case 'requested': return 'bg-primary-100 text-primary-700'
    case 'approved':  return 'bg-success-50 text-success-600'
    case 'rejected':  return 'bg-danger-50 text-danger-500'
    default:          return 'bg-neutral-100 text-neutral-600'
  }
})

async function requestApproval() {
  if (!invoice.value) return
  if (!confirm(t('invoice.approval.request_confirm'))) return
  busy.value = 'approval-request'
  try {
    const r = await invoicesApi.requestApproval(invoice.value.id)
    invoice.value = r.invoice
    toast.success(t('invoice.approval.request_sent', { recipients: r.sent_to.join(', ') }))
    invoicesApi.activity(invoice.value.id).then(a => { activity.value = a }).catch(() => {})
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.approval.request_failed'))
  } finally {
    busy.value = null
  }
}

async function requestApprovalTest() {
  if (!invoice.value) return
  busy.value = 'approval-test'
  try {
    const r = await invoicesApi.requestApprovalTest(invoice.value.id)
    toast.success(t('invoice.approval.test_sent', { recipients: r.sent_to.join(', ') }))
    invoicesApi.activity(invoice.value.id).then(a => { activity.value = a }).catch(() => {})
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.approval.test_failed'))
  } finally {
    busy.value = null
  }
}

</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else-if="invoice" class="max-w-5xl space-y-6">
    <RouterLink to="/tri/invoices" class="text-sm text-neutral-500 hover:text-neutral-900">{{ t('tri.invoices.back_to_list') }}</RouterLink>
    <UiPageHeader :title="invoice.varsymbol || t('invoice.draft_id', { id: invoice.id })">
      <template #below>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="statusBadgeClass(invoice.status)">
            {{ statusLabel(invoice.status) }}
          </span>
          <UiBadge variant="neutral">{{ typeLabel(invoice.invoice_type) }}</UiBadge>
          <span v-if="invoice.income_tax_exempt"
            class="text-xs px-2 py-0.5 rounded-full font-medium bg-warning-50 text-warning-700"
            :title="invoice.income_tax_exempt_reason || ''">
            {{ t('invoice.income_tax_exempt_badge') }}
          </span>
          <span v-if="requiresApproval"
            class="text-xs px-2 py-0.5 rounded-full font-medium" :class="approvalBadgeClass">
            {{ t('invoice.approval.badge') }}:
            {{ approvalTokenExpired
                ? t('invoice.approval.status_expired')
                : t('invoice.approval.status_' + approvalStatus) }}
          </span>
        </div>
      </template>
      <template #actions>
        <UiButton v-if="isDraft && auth.canWrite" :to="`/tri/invoices/${invoice.id}/edit`" variant="secondary" size="sm">
          {{ t('common.edit') }}
        </UiButton>
        <UiButton v-if="false && canRequestApproval && auth.canWrite" size="sm" :disabled="busy !== null" :loading="busy === 'approval-request'" @click="requestApproval">
          {{ busy === 'approval-request' ? '…' : t('invoice.approval.send_request') }}
        </UiButton>
        <UiButton v-if="h5Ready && isDraft && canIssueDraft && auth.canWrite" size="sm"
          :disabled="busy !== null || (requiresApproval && approvalStatus !== 'approved')"
          :title="requiresApproval && approvalStatus !== 'approved' ? t('invoice.approval.issue_blocked') : ''"
          :loading="busy === 'issue'"
          @click="issue">
          {{ busy === 'issue' ? '…' : t('invoice.issue') }}
        </UiButton>
        <UiButton v-if="false && isDraft && auth.canWrite" variant="outline" size="sm" :title="t('invoice.wr_btn')" @click="wrModalOpen = true">
          {{ t('invoice.wr_btn') }}
        </UiButton>
        <UiButton v-if="isDraft && auth.canWrite" variant="danger" size="sm" :disabled="busy !== null" @click="deleteInvoice">
          {{ t('common.delete') }}
        </UiButton>
        <UiButton v-if="canSendEmail && auth.canWrite" size="sm" :disabled="busy !== null" @click="openSendModal">
          {{ t('invoice.send_to_client') }}
        </UiButton>
        <UiButton v-if="canIssueFinal && auth.canWrite" size="sm" :disabled="busy !== null" :loading="busy === 'issue-final'" @click="issueFinalFromProforma">
          {{ busy === 'issue-final' ? '…' : t('invoice.issue_final') }}
        </UiButton>
        <UiButton v-if="isIssued && canMarkPaid && auth.canWrite" variant="outline" size="sm" :disabled="busy !== null" @click="openMarkPaid">
          {{ t('invoice.mark_paid') }}
        </UiButton>
        <UiButton v-if="canSendReminder && auth.canWrite" size="sm" :disabled="busy !== null" :title="t('invoice.reminder_tooltip', { days: daysOverdue })" @click="openReminderModal">
          {{ t('invoice.send_reminder') }}
        </UiButton>
        <UiButton v-if="h5Ready && (!isDraft && !['cancellation','credit_note'].includes(invoice.invoice_type)) && auth.canWrite" variant="outline" size="sm" :disabled="busy !== null" :loading="busy === 'clone'" @click="cloneInvoice">
          {{ busy === 'clone' ? '…' : t('invoice.clone') }}
        </UiButton>
        <UiButton v-if="!isDraft || invoice.items.length > 0" variant="outline" size="sm" :title="invoiceWillBeSigned ? (t('invoice.download_pdf_tooltip_signed') as string) : undefined" @click="downloadPdf">
          {{ t('invoice.download_pdf') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <div class="flex items-start justify-between gap-4">
      <div class="flex-1 min-w-0 space-y-1">
        <div class="text-lg font-semibold text-neutral-900">
          <RouterLink :to="`/tri/invoices?client_id=${invoice.client_id}`"
            class="text-primary-700 hover:text-primary-800 hover:underline"
            :title="t('invoice.show_invoices_for_client')">
            {{ invoice.client_company_name }}
          </RouterLink>
        </div>
        <div v-if="triJob" class="text-sm text-neutral-600">
          <span class="text-neutral-500">{{ t('tri.invoices.job_label') }}:</span>
          <RouterLink
            :to="{ name: 'tri-job-detail', params: { id: triJob.id } }"
            class="text-primary-700 hover:text-primary-800 hover:underline font-mono ml-1"
          >
            {{ triJob.number }}
          </RouterLink>
          <span> — {{ triJob.title }}</span>
        </div>
        <div v-if="invoice.created_by_name" class="text-sm text-neutral-600">
          <span class="text-neutral-500">{{ t('tri.invoices.issued_by') }}:</span>
          <span class="ml-1">{{ invoice.created_by_name }}</span>
        </div>
        <div v-if="invoice.client_main_email || invoice.project_billing_emails?.length" class="text-xs text-neutral-500 flex flex-wrap gap-x-3 gap-y-0.5">
          <span v-if="invoice.client_main_email">✉ {{ invoice.client_main_email }}</span>
          <span v-for="b in (invoice.project_billing_emails || []).filter(b => b.email !== invoice!.client_main_email)" :key="b.email">
            ✉ {{ b.email }}<span v-if="b.label" class="text-neutral-400"> ({{ b.label }})</span>
          </span>
        </div>
      </div>
      <div v-if="invoice.client_ic || invoice.client_dic" class="text-xs font-mono text-neutral-500 text-right whitespace-nowrap">
        <span v-if="invoice.client_ic">{{ t('common.ic') }} {{ invoice.client_ic }}</span>
        <span v-if="invoice.client_ic && invoice.client_dic">, </span>
        <span v-if="invoice.client_dic">{{ t('common.dic') }} {{ invoice.client_dic }}</span>
      </div>
    </div>

    <div
      v-if="clientAddressMissing"
      class="rounded-md bg-warning-50 border border-warning-500/30 px-4 py-3 text-sm text-warning-800"
    >
      {{ t('invoice.client_missing_address') }}
    </div>

    <!-- Mark paid modal -->
    <div v-if="markPaidOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-sm w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('invoice.modals.mark_paid_title') }}</h3>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.modals.mark_paid_date') }}</label>
        <input v-model="paidAtInput" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md mb-4" />
        <label v-if="thanksEnabled" class="flex items-start gap-2 text-sm text-neutral-700 mb-4 cursor-pointer">
          <input v-model="sendThanks" type="checkbox" :disabled="!thanksHasRecipient" class="mt-0.5 rounded border-neutral-300 text-primary-600 disabled:opacity-50" />
          <span>
            {{ t('invoice.send_payment_thanks') }}
            <span v-if="!thanksHasRecipient" class="block text-xs text-warning-600">{{ t('invoice.send_payment_thanks_no_recipient') }}</span>
          </span>
        </label>
        <div class="flex justify-end gap-2">
          <button @click="markPaidOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="markPaid" :disabled="busy !== null"
            class="cursor-pointer px-4 h-9 text-sm bg-success-500 hover:bg-success-600 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ busy === 'paid' ? '…' : t('common.confirm') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Cancel modal -->
    <div v-if="cancelOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ isCreditNoteSource ? t('invoice.modals.cancel_title_cn') : t('invoice.modals.cancel_title') }}</h3>
        <p class="text-sm text-neutral-600 mb-3">{{ t('invoice.modals.cancel_choose') }}</p>
        <div class="space-y-2 mb-4">
          <label v-if="!isCreditNoteSource" class="flex items-start gap-2 p-3 border rounded-md cursor-pointer"
            :class="cancelMode === 'credit_note' ? 'border-primary-500 bg-primary-50' : 'border-neutral-200'">
            <input type="radio" v-model="cancelMode" value="credit_note" class="mt-1" />
            <div>
              <div class="font-medium text-sm">{{ t('invoice.modals.cancel_credit_note') }}</div>
              <div class="text-xs text-neutral-500">{{ t('invoice.modals.cancel_credit_desc') }}</div>
            </div>
          </label>
          <label class="flex items-start gap-2 p-3 border rounded-md cursor-pointer"
            :class="cancelMode === 'internal' ? 'border-primary-500 bg-primary-50' : 'border-neutral-200'">
            <input type="radio" v-model="cancelMode" value="internal" class="mt-1" />
            <div>
              <div class="font-medium text-sm">{{ t('invoice.modals.cancel_internal') }}</div>
              <div class="text-xs text-neutral-500">{{ isCreditNoteSource ? t('invoice.modals.cancel_internal_desc_cn') : t('invoice.modals.cancel_internal_desc') }}</div>
            </div>
          </label>
          <!-- 3. možnost: force-delete účetního dokladu — admin only.
               Po výběru a potvrzení modalky se otevře window.confirm s plným per-status warningem. -->
          <label v-if="isAdmin" class="flex items-start gap-2 p-3 border rounded-md cursor-pointer"
            :class="cancelMode === 'delete' ? 'border-danger-500 bg-danger-50' : 'border-neutral-200'">
            <input type="radio" v-model="cancelMode" value="delete" class="mt-1" />
            <div>
              <div class="font-medium text-sm text-danger-600">⚠ {{ isCreditNoteSource ? t('invoice.modals.cancel_delete_cn') : t('invoice.modals.cancel_delete') }}</div>
              <div class="text-xs text-neutral-500 mt-0.5">{{ isCreditNoteSource ? t('invoice.modals.cancel_delete_desc_cn') : t('invoice.modals.cancel_delete_desc') }}</div>
            </div>
          </label>
        </div>
        <template v-if="cancelMode !== 'delete'">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.modals.cancel_reason') }}</label>
          <textarea v-model="cancelReason" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm mb-4"></textarea>
        </template>
        <div class="flex justify-end gap-2">
          <button @click="cancelOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="cancel" :disabled="busy !== null"
            :class="[
              'cursor-pointer px-4 h-9 text-sm disabled:bg-neutral-300 text-white font-medium rounded-md',
              cancelMode === 'delete'
                ? 'bg-danger-500 hover:bg-danger-600'
                : 'bg-warning-500 hover:bg-warning-600',
            ]">
            {{ busy === 'cancel' || busy === 'delete' ? '…' : t('common.confirm') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Send modal -->
    <div v-if="sendOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('invoice.modals.send_title') }}</h3>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.modals.send_recipients') }}</label>
        <input v-model="sendTo" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md mb-2 text-sm" />
        <p v-if="sendHasNoRecipient" class="text-xs text-warning-600 mb-2">{{ t('invoice.send_no_recipient_warning') }}</p>
        <p class="text-xs text-neutral-500 mb-4">{{ t('invoice.modals.send_default_hint') }}</p>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.modals.send_note_label') }}</label>
        <textarea v-model="sendNote" rows="4" maxlength="5000"
          :placeholder="t('invoice.modals.send_note_placeholder')"
          class="w-full px-3 py-2 border border-neutral-300 rounded-md mb-2 text-sm font-sans resize-y"></textarea>
        <p class="text-xs text-neutral-500 mb-4">{{ t('invoice.modals.send_note_hint') }}</p>
        <div class="flex justify-end gap-2">
          <button @click="sendOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="send" :disabled="busy !== null"
            class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ busy === 'send' ? '…' : t('common.send') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Reminder modal -->
    <div v-if="reminderOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('invoice.modals.reminder_title') }}</h3>
        <p class="text-sm text-warning-600 font-medium mb-3">{{ t('invoice.modals.reminder_overdue', { days: daysOverdue }) }}</p>
        <p class="text-sm text-neutral-600 mb-3">{{ t('invoice.modals.reminder_body') }}</p>
        <div v-if="invoice && (invoice.client_main_email || invoice.project_billing_emails?.length)" class="bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2 mb-4 text-xs">
          <div class="text-neutral-500 mb-0.5">{{ t('invoice.modals.reminder_recipients') }}</div>
          <div v-if="invoice.client_main_email" class="font-mono">✉ {{ invoice.client_main_email }}</div>
          <div v-for="b in (invoice.project_billing_emails || []).filter(b => b.email !== invoice!.client_main_email)" :key="b.email" class="font-mono">
            ✉ {{ b.email }}<span v-if="b.label" class="text-neutral-400"> ({{ b.label }})</span>
          </div>
        </div>
        <div v-if="invoice && invoice.reminder_count > 0" class="text-xs text-neutral-500 mb-4">
          {{ t('invoice.reminder_at', { count: invoice.reminder_count, date: formatDate(invoice.last_reminder_at) }) }}
        </div>
        <div class="flex justify-end gap-2">
          <button @click="reminderOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="sendReminder" :disabled="busy !== null"
            class="cursor-pointer px-4 h-9 text-sm bg-warning-500 hover:bg-warning-600 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ busy === 'reminder' ? '…' : t('invoice.send_reminder') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Cross-link na související doklad: proforma → vystavený daňový doklad; doklad → rodič -->
    <!-- Proforma propojená s daňovým dokladem → odkaz + zrušení propojení -->
    <div v-if="isProforma && invoice.final_invoice"
      class="flex items-center justify-between gap-3 bg-primary-50 border border-primary-200 rounded-lg px-4 py-2.5 text-sm mb-4">
      <span class="text-primary-700 min-w-0">
        {{ t('invoice.linked.final_invoice') }}
        <RouterLink :to="`/tri/invoices/${invoice.final_invoice.id}`" class="font-mono font-medium hover:underline">
          {{ invoice.final_invoice.varsymbol || `#${invoice.final_invoice.id}` }}
        </RouterLink>
      </span>
      <button v-if="auth.canWrite" type="button" @click="unlinkAdvance(invoice.final_invoice.id)" :disabled="linkingAdvance"
        class="cursor-pointer text-xs px-2 py-1 border border-neutral-300 rounded text-neutral-600 hover:bg-neutral-50 disabled:opacity-50 shrink-0 bg-surface">
        {{ t('invoice.advance_link.unlink') }}
      </button>
    </div>
    <!-- Nepropojená proforma → nabídka spárovat s daňovým dokladem (opačný směr) -->
    <div v-else-if="canPairFinal"
      class="flex items-center justify-between gap-3 bg-neutral-50 border border-neutral-200 rounded-lg px-4 py-2.5 text-sm mb-4">
      <span class="text-neutral-500">{{ t('invoice.advance_link.none_final') }}</span>
      <button type="button" @click="openPairModal('final')"
        class="cursor-pointer text-xs px-3 py-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md shrink-0 bg-surface">
        {{ t('invoice.advance_link.pair_final') }}
      </button>
    </div>
    <!-- Rodič storna/dobropisu (původní faktura) → prostý odkaz -->
    <RouterLink v-if="invoice.parent_invoice && !linkedProforma"
      :to="`/tri/invoices/${invoice.parent_invoice.id}`"
      class="flex items-center justify-between gap-3 bg-primary-50 border border-primary-200 rounded-lg px-4 py-2.5 text-sm hover:bg-primary-100 transition mb-4">
      <span class="text-primary-700">{{ t('invoice.linked.parent') }}</span>
      <span class="font-medium text-primary-700 font-mono">{{ invoice.parent_invoice.varsymbol || `#${invoice.parent_invoice.id}` }} →</span>
    </RouterLink>
    <!-- Propojeno se zálohou (proforma) → odkaz + zrušení propojení -->
    <div v-else-if="linkedProforma"
      class="flex items-center justify-between gap-3 bg-primary-50 border border-primary-200 rounded-lg px-4 py-2.5 text-sm mb-4">
      <span class="text-primary-700 min-w-0">
        {{ t('invoice.linked.proforma') }}
        <RouterLink :to="`/tri/invoices/${linkedProforma.id}`" class="font-mono font-medium hover:underline">
          {{ linkedProforma.varsymbol || `#${linkedProforma.id}` }}
        </RouterLink>
      </span>
      <button v-if="auth.canWrite" type="button" @click="unlinkAdvance()" :disabled="linkingAdvance"
        class="cursor-pointer text-xs px-2 py-1 border border-neutral-300 rounded text-neutral-600 hover:bg-neutral-50 disabled:opacity-50 shrink-0 bg-surface">
        {{ t('invoice.advance_link.unlink') }}
      </button>
    </div>
    <!-- Daňový doklad bez vazby → nabídka spárovat se zálohou -->
    <div v-else-if="canPairAdvance"
      class="flex items-center justify-between gap-3 bg-neutral-50 border border-neutral-200 rounded-lg px-4 py-2.5 text-sm mb-4">
      <span class="text-neutral-500">{{ t('invoice.advance_link.none') }}</span>
      <button type="button" @click="openPairModal('advance')"
        class="cursor-pointer text-xs px-3 py-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md shrink-0 bg-surface">
        {{ t('invoice.advance_link.pair') }}
      </button>
    </div>

    <!-- Modal výběru dokladu k propojení (záloha ⇄ daňový doklad dle pairMode) -->
    <div v-if="advanceModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="advanceModalOpen = false">
      <div class="bg-surface rounded-lg shadow-xl max-w-lg w-full max-h-[80vh] overflow-hidden flex flex-col">
        <div class="px-5 py-3 border-b border-neutral-100 flex items-center justify-between">
          <h3 class="font-medium">{{ pairMode === 'advance' ? t('invoice.advance_link.modal_title') : t('invoice.advance_link.modal_title_final') }}</h3>
          <button type="button" @click="advanceModalOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-600">✕</button>
        </div>
        <div class="p-4 overflow-y-auto">
          <div v-if="loadingCandidates" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
          <div v-else-if="advanceCandidates.length === 0" class="text-sm text-neutral-500">{{ pairMode === 'advance' ? t('invoice.advance_link.no_candidates') : t('invoice.advance_link.no_candidates_final') }}</div>
          <ul v-else class="space-y-2">
            <li v-for="cand in advanceCandidates" :key="cand.id">
              <button type="button" @click="pickCandidate(cand.id)" :disabled="linkingAdvance"
                class="cursor-pointer w-full text-left px-3 py-2 border border-neutral-200 rounded-md hover:border-primary-400 hover:bg-primary-50 disabled:opacity-50 flex justify-between items-center gap-3">
                <span class="font-mono text-sm">{{ cand.varsymbol || ('#' + cand.id) }}</span>
                <span class="text-sm text-neutral-500">{{ cand.issue_date ? formatDate(cand.issue_date) : '' }} · {{ formatMoney(cand.total_with_vat, cand.currency) }}</span>
              </button>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">
          {{ t('invoice.issue_date') }}
          <template v-if="!isProforma"> / {{ t('invoice.tax_date') }}</template>
          / {{ t('invoice.due_date') }}
        </h3>
        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('invoice.issue_date') }}</dt><dd>{{ formatDate(invoice.issue_date) }}</dd></div>
          <div v-if="invoice.tax_date && !isProforma" class="flex justify-between"><dt class="text-neutral-500">{{ t('invoice.tax_date') }}</dt><dd>{{ formatDate(invoice.tax_date) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('invoice.due_date') }}</dt><dd>{{ formatDate(invoice.due_date) }}</dd></div>
          <div v-if="invoice.paid_at" class="flex justify-between"><dt class="text-neutral-500">{{ t('status.paid') }}</dt><dd>{{ formatDate(invoice.paid_at) }}</dd></div>
        </dl>
      </div>

      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('common.currency') }} &amp; {{ t('invoice.totals.vat') }}</h3>
        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.currency') }}</dt><dd class="font-mono">{{ invoice.currency }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('invoice.language') }}</dt><dd>{{ invoice.language.toUpperCase() }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('invoice.reverse_charge') }}</dt><dd>{{ invoice.reverse_charge ? t('common.yes') : t('common.no') }}</dd></div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('payment_method.label') }}</dt>
            <dd>{{ t('payment_method.' + (invoice.payment_method ?? 'bank_transfer')) }}</dd>
          </div>
        </dl>
      </div>

      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('settings.account_cz') }}</h3>
        <dl v-if="(invoice.payment_method ?? 'bank_transfer') === 'bank_transfer'" class="space-y-1 text-sm">
          <div v-if="invoice.bank_account_number" class="font-mono text-xs">
            {{ invoice.bank_account_number }} / {{ invoice.bank_code }}
          </div>
          <div v-if="invoice.bank_iban" class="font-mono text-xs break-all">{{ invoice.bank_iban }}</div>
          <div v-if="invoice.bank_name" class="text-neutral-600">{{ invoice.bank_name }}</div>
          <div v-if="!invoice.bank_account_number && !invoice.bank_iban" class="text-neutral-400 text-xs">
            {{ t('invoice.bank_not_set', { currency: invoice.currency }) }}
          </div>
        </dl>
        <div v-else class="text-xs text-neutral-500">
          {{ t('payment_method.hint') }}
        </div>
      </div>
    </div>

    <!-- Položky -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-xs overflow-hidden">
      <div class="px-5 py-3 border-b border-neutral-200">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.items') }}</h3>
      </div>
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-4 py-2 text-left font-medium">{{ t('invoice.items_table.description') }}</th>
            <th class="px-4 py-2 text-right font-medium">{{ t('invoice.items_table.qty') }}</th>
            <th class="px-4 py-2 text-left font-medium">{{ t('invoice.items_table.unit') }}</th>
            <th class="px-4 py-2 text-right font-medium">{{ t('invoice.items_table.unit_price') }}</th>
            <th v-if="supplierIsVatPayer" class="px-4 py-2 text-center font-medium">{{ t('invoice.items_table.vat') }}</th>
            <th v-if="supplierIsVatPayer" class="px-4 py-2 text-right font-medium">{{ t('invoice.items_table.without_vat') }}</th>
            <th class="px-4 py-2 text-right font-medium">{{ supplierIsVatPayer ? t('invoice.items_table.with_vat') : t('invoice.totals.total') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="item in invoice.items" :key="item.id" :class="item.item_kind === 'discount' ? 'text-warning-700' : ''">
            <td class="px-4 py-2.5 whitespace-pre-wrap">{{ item.description }}</td>
            <td class="px-4 py-2.5 text-right font-mono">{{ item.item_kind === 'discount' ? '' : item.quantity }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ item.item_kind === 'discount' ? '' : item.unit }}</td>
            <td class="px-4 py-2.5 text-right font-mono">{{ item.item_kind === 'discount' ? '' : formatMoney(displayUnitPriceNet(item), invoice.currency) }}</td>
            <td v-if="supplierIsVatPayer" class="px-4 py-2.5 text-center text-xs">{{ formatPercent(item.vat_rate_snapshot ?? 0) }}</td>
            <td v-if="supplierIsVatPayer" class="px-4 py-2.5 text-right font-mono">{{ formatMoney(item.total_without_vat ?? 0, invoice.currency) }}</td>
            <td class="px-4 py-2.5 text-right font-mono font-medium">{{ formatMoney(supplierIsVatPayer ? (item.total_with_vat ?? 0) : (item.total_without_vat ?? 0), invoice.currency) }}</td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: stack karet -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="item in invoice.items" :key="`m-${item.id}`" class="p-3 space-y-1.5">
          <div class="text-sm whitespace-pre-wrap" :class="item.item_kind === 'discount' ? 'text-warning-700' : 'text-neutral-900'">{{ item.description }}</div>
          <div v-if="item.item_kind !== 'discount'" class="flex items-baseline justify-between text-xs text-neutral-500">
            <span>
              <span class="font-mono text-neutral-700">{{ item.quantity }}</span>
              <span class="ml-1">{{ item.unit }}</span>
              <span class="text-neutral-400 mx-1.5">·</span>
              <span class="font-mono">{{ formatMoney(displayUnitPriceNet(item), invoice.currency) }}</span>
              <template v-if="supplierIsVatPayer">
                <span class="text-neutral-400 mx-1.5">·</span>
                <span>{{ formatPercent(item.vat_rate_snapshot ?? 0) }}</span>
              </template>
            </span>
          </div>
          <div class="flex items-baseline justify-between pt-1 text-sm">
            <span class="text-xs text-neutral-500">{{ supplierIsVatPayer ? t('invoice.items_table.with_vat') : t('invoice.totals.total') }}</span>
            <span class="font-mono font-semibold">{{ formatMoney(supplierIsVatPayer ? (item.total_with_vat ?? 0) : (item.total_without_vat ?? 0), invoice.currency) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Sumace -->
    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.summary') }}</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <dl class="space-y-1 text-sm">
          <template v-if="supplierIsVatPayer">
            <div v-for="b in invoice.vat_breakdown" :key="b.rate" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.base, invoice.currency) }}</dd>
            </div>
            <div v-for="b in invoice.vat_breakdown" :key="'v'+b.rate" v-show="b.vat > 0" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.vat, invoice.currency) }}</dd>
            </div>
          </template>
        </dl>
        <dl class="space-y-1 text-sm">
          <div v-if="supplierIsVatPayer" class="flex justify-between font-semibold">
            <dt>{{ t('invoice.totals.without_vat') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.without_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="supplierIsVatPayer" class="flex justify-between font-semibold">
            <dt>{{ t('invoice.totals.vat_total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.vat, invoice.currency) }}</dd>
          </div>
          <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
            <dt>{{ t('invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.with_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-2">
            <dt>{{ t('invoice.totals.advance_deduction') }}</dt>
            <dd class="font-mono">−{{ formatMoney(invoice.advance_paid_amount, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-base font-semibold">
            <dt>{{ t('invoice.amount_to_pay') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.amount_to_pay, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.czk_recap" class="text-xs text-neutral-500 pt-2 border-t border-neutral-200 mt-2">
            {{ t('invoice.czk_recap.rate_info', {
              rate: formatRate(invoice.czk_recap.rate),
              currency: invoice.currency,
              date: formatDate(invoice.czk_recap.rate_date),
            }) }}
          </div>
        </dl>
      </div>
    </div>

    <!-- CZK přepočet pro faktury v cizí měně -->
    <div v-if="invoice.czk_recap" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">
        {{ t('invoice.czk_recap.title') }}
      </h3>
      <p class="text-xs text-neutral-500 mb-3">
        {{ t('invoice.czk_recap.rate_info', {
          rate: formatRate(invoice.czk_recap.rate),
          currency: invoice.currency,
          date: formatDate(invoice.czk_recap.rate_date),
        }) }}
        <span v-if="invoice.czk_recap.fallback_used" class="text-warning-600">
          ({{ t('invoice.czk_recap.fallback_note') }})
        </span>
      </p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <dl class="space-y-1 text-sm">
          <div v-for="b in invoice.czk_recap.breakdown" :key="'cb'+b.rate" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
            <dd class="font-mono">{{ formatMoney(b.base_czk, 'CZK') }}</dd>
          </div>
          <div v-for="b in invoice.czk_recap.breakdown" :key="'cv'+b.rate" v-show="b.vat_czk > 0" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
            <dd class="font-mono">{{ formatMoney(b.vat_czk, 'CZK') }}</dd>
          </div>
        </dl>
        <dl class="space-y-1 text-sm">
          <div class="flex justify-between font-semibold">
            <dt>{{ t('invoice.totals.without_vat') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.czk_recap.total_without_vat_czk, 'CZK') }}</dd>
          </div>
          <div class="flex justify-between font-semibold">
            <dt>{{ t('invoice.totals.vat_total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.czk_recap.total_vat_czk, 'CZK') }}</dd>
          </div>
          <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
            <dt>{{ t('invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.czk_recap.total_with_vat_czk, 'CZK') }}</dd>
          </div>
        </dl>
      </div>
    </div>

    <div v-if="invoice.note_below_items" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('invoice.note') }}</h3>
      <p class="text-sm text-neutral-700 whitespace-pre-wrap">{{ invoice.note_below_items }}</p>
    </div>

    <!-- Elektronický podpis dokumentu -->
    <div v-if="canManageSignatureSelection" class="bg-surface border border-neutral-200 rounded-lg shadow-xs overflow-hidden">
      <header class="px-5 py-3 border-b border-neutral-200">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.signing.title') }}</h3>
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('invoice.signing.hint') }}</p>
      </header>
      <div v-if="signatureSelectionLoading && signatureSelectionRows.some(row => !signatureSelection(row.entityType))"
        class="px-5 py-4 text-sm text-neutral-500">
        {{ t('common.loading') }}
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-5 py-2 text-left font-medium">{{ t('invoice.signing.output') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_selection_source') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_profile') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('invoice.signing.effective') }}</th>
              <th class="px-5 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in signatureSelectionRows" :key="row.entityType">
              <td class="px-5 py-2 font-medium text-neutral-800">{{ row.label }}</td>
              <td class="px-3 py-2">
                <select
                  :value="signatureSelection(row.entityType)?.selection_source || 'inherit'"
                  @change="setSignatureSelectionSource(row.entityType, ($event.target as HTMLSelectElement).value as PdfSignatureDocumentSelectionSource)"
                  class="h-8 w-48 px-2 border border-neutral-300 rounded-md text-xs">
                  <option value="inherit">{{ t('invoice.signing.source_inherit') }}</option>
                  <option value="logged_in_user">{{ t('settings.signing_output_source_logged_in_user') }}</option>
                  <option value="admin_profile_settings">{{ t('settings.signing_output_source_admin_profile_settings') }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <select
                  v-if="isAdmin && signatureSelection(row.entityType)?.selection_source === 'admin_profile_settings'"
                  :value="signatureSelection(row.entityType)?.admin_profile_id || ''"
                  @change="setSignatureAdminProfile(row.entityType, ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
                  class="h-8 w-48 px-2 border border-neutral-300 rounded-md text-xs">
                  <option value="">{{ t('invoice.signing.admin_profile_inherited') }}</option>
                  <option v-for="profile in adminSigningProfiles" :key="profile.id" :value="profile.id">
                    {{ profile.name }} ({{ profile.code }})
                  </option>
                </select>
                <span v-else class="text-neutral-500">
                  {{ signatureSelection(row.entityType)?.selection_source === 'admin_profile_settings'
                    ? t('invoice.signing.admin_profile_inherited')
                    : '—' }}
                </span>
              </td>
              <td class="px-3 py-2 text-neutral-600">
                <template v-if="signatureSelection(row.entityType)">
                  {{ signatureSelectionSourceLabel(signatureSelection(row.entityType)!.effective_selection_source) }}
                  <span v-if="signatureSelection(row.entityType)!.effective_selection_source === 'admin_profile_settings'"
                    class="text-neutral-400">
                    · {{ signatureProfileName(signatureSelection(row.entityType)!.effective_admin_profile_id) }}
                  </span>
                </template>
              </td>
              <td class="px-5 py-2 text-right">
                <button @click="saveSignatureSelection(row.entityType)" type="button"
                  :disabled="signatureSelectionSaving === row.entityType || !signatureSelection(row.entityType)"
                  class="cursor-pointer text-primary-600 hover:text-primary-700 disabled:opacity-50">
                  {{ signatureSelectionSaving === row.entityType ? t('common.loading') : t('common.save') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Přílohy emailu (PDF/Office/obrázky se přibalí při odeslání faktury) -->
    <div v-if="invoice && attachmentsAvailable(invoice)"
         class="bg-surface border border-neutral-200 rounded-lg shadow-xs overflow-hidden">
      <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <div>
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('invoice.attachments.title') }}
          </h3>
          <p class="text-xs text-neutral-500 mt-0.5">{{ t('invoice.attachments.hint') }}</p>
        </div>
        <span class="text-xs text-neutral-400">{{ attachments.length }}</span>
      </header>

      <ul v-if="attachments.length > 0" class="divide-y divide-neutral-100">
        <li v-for="a in attachments" :key="a.id" class="px-5 py-2.5 text-sm flex items-center gap-3">
          <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M15.172 7l-6.586 6.586a2 2 0 1 0 2.828 2.828l6.414-6.414a4 4 0 1 0-5.656-5.656L5.05 11.05a6 6 0 1 0 8.486 8.486L20 13"/>
          </svg>
          <span class="text-neutral-700 text-xs flex-1 truncate" :title="a.original_name">{{ a.original_name }}</span>
          <span class="text-neutral-400 text-xs whitespace-nowrap">{{ formatBytes(a.size_bytes) }}</span>
          <span class="text-neutral-400 text-xs whitespace-nowrap hidden md:inline">
            {{ a.uploaded_at.replace('T', ' ').slice(0, 16) }}
          </span>
          <a :href="invoicesApi.attachmentUrl(invoice!.id, a.id, false)" target="_blank"
             class="text-xs text-primary-600 hover:text-primary-700 font-medium inline-flex items-center gap-1">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            {{ t('common.view') }}
          </a>
          <a :href="invoicesApi.attachmentUrl(invoice!.id, a.id, true)"
             class="text-xs text-primary-600 hover:text-primary-700 font-medium inline-flex items-center gap-1">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            {{ t('common.download') }}
          </a>
          <button @click="deleteAttachment(a)" type="button"
                  class="text-xs text-danger-500 hover:text-danger-600 cursor-pointer inline-flex items-center gap-1">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M10 11v6m4-6v6m1 5H9a2 2 0 0 1-2-2V7h10v13a2 2 0 0 1-2 2zM5 7h14l-1-3H6L5 7z"/>
            </svg>
            {{ t('common.delete') }}
          </button>
        </li>
      </ul>

      <div class="px-5 py-3"
           :class="attachmentsDragOver ? 'bg-primary-50' : 'bg-neutral-50/50'"
           @dragover.prevent="attachmentsDragOver = true"
           @dragleave.prevent="attachmentsDragOver = false"
           @drop="onAttachmentDrop">
        <label class="flex flex-col md:flex-row items-stretch md:items-center gap-2 md:gap-3 cursor-pointer">
          <input ref="attachmentInput" type="file" multiple
                 class="hidden"
                 @change="onAttachmentInputChange" />
          <span class="inline-flex items-center justify-center px-3 h-9 text-sm border border-primary-300 rounded-md text-primary-600 hover:bg-primary-50">
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ attachmentsBusy ? t('invoice.attachments.uploading') : t('invoice.attachments.add') }}
          </span>
          <span class="text-xs text-neutral-500">{{ t('invoice.attachments.drop_here') }}</span>
        </label>
      </div>
    </div>

    <!-- Historie PDF -->
    <div v-if="pdfHistory.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-xs overflow-hidden">
      <button type="button" @click="pdfHistoryOpen = !pdfHistoryOpen"
        class="w-full px-5 py-3 flex items-center justify-between text-left hover:bg-neutral-50 cursor-pointer"
        :class="pdfHistoryOpen ? 'border-b border-neutral-200' : ''">
        <span class="flex items-center gap-2">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.pdf_history.title') }}</h3>
          <span class="text-xs text-neutral-400">{{ pdfHistory.length }}</span>
        </span>
        <svg class="w-4 h-4 text-neutral-400 transition-transform" :class="pdfHistoryOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>
      <ul v-show="pdfHistoryOpen" class="divide-y divide-neutral-100">
        <li v-for="p in pdfHistory" :key="p.id"
            class="px-4 sm:px-5 py-2.5 text-sm flex flex-col gap-1.5 md:flex-row md:items-center md:gap-3">
          <!-- Badge + název souboru -->
          <div class="flex items-center gap-2 min-w-0 md:flex-1">
            <span v-if="p.was_sent" class="shrink-0 text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600 inline-flex items-center gap-1">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
              {{ t('invoice.pdf_history.sent') }}
            </span>
            <span v-else class="shrink-0 text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-600">{{ pdfReasonLabel(p.reason) }}</span>
            <span class="text-neutral-700 text-xs min-w-0 break-all md:truncate" :title="p.filename">{{ p.filename }}</span>
          </div>
          <!-- Meta: velikost · datum · příjemci -->
          <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-neutral-400 md:flex-nowrap md:whitespace-nowrap">
            <span>{{ formatBytes(p.size_bytes) }}</span>
            <span>{{ p.archived_at.replace('T', ' ').slice(0, 19) }}</span>
            <span v-if="p.was_sent && p.sent_to && p.sent_to.length" class="text-neutral-500 break-all md:truncate md:max-w-xs" :title="p.sent_to.join(', ')">→ {{ p.sent_to.join(', ') }}</span>
          </div>
          <!-- Akce -->
          <div class="flex items-center gap-4">
            <a :href="invoicesApi.archivedPdfUrl(invoice!.id, p.id, false)" target="_blank"
               class="text-xs text-primary-600 hover:text-primary-700 font-medium inline-flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              {{ t('common.view') }}
            </a>
            <a :href="invoicesApi.archivedPdfUrl(invoice!.id, p.id, true)"
               class="text-xs text-primary-600 hover:text-primary-700 font-medium inline-flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              {{ t('common.download') }}
            </a>
          </div>
        </li>
      </ul>
    </div>

    <!-- Aktivita -->
    <div v-if="activity.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-xs overflow-hidden">
      <button type="button" @click="activityOpen = !activityOpen"
        class="w-full px-5 py-3 flex items-center justify-between text-left hover:bg-neutral-50 cursor-pointer"
        :class="activityOpen ? 'border-b border-neutral-200' : ''">
        <span class="flex items-center gap-2">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.activity') }}</h3>
          <span class="text-xs text-neutral-400">{{ activity.length }}</span>
        </span>
        <svg class="w-4 h-4 text-neutral-400 transition-transform" :class="activityOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>
      <div v-show="activityOpen">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in activity" :key="a.id" class="hover:bg-neutral-50 align-top">
                <td class="px-5 py-2 whitespace-nowrap">
                  <span class="text-xs px-2 py-0.5 rounded font-medium" :class="actionColor(a.action)">{{ actionLabel(a.action) }}</span>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">{{ a.user_name || a.user_email || '—' }}</td>
                <td class="px-3 py-2 font-mono text-xs text-neutral-400 whitespace-nowrap">{{ a.created_at.replace('T', ' ').slice(0, 19) }}</td>
                <td class="px-3 py-2 text-xs text-neutral-600 break-all whitespace-pre-wrap leading-snug">{{ payloadText(a.payload) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Mobil: karty (payload na plnou šířku, jinak se zmáčkne do úzkého sloupce) -->
        <ul class="md:hidden divide-y divide-neutral-100">
          <li v-for="a in activity" :key="`m-${a.id}`" class="px-4 py-3 space-y-1.5">
            <div class="flex items-center justify-between gap-2">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="actionColor(a.action)">{{ actionLabel(a.action) }}</span>
              <span class="font-mono text-xs text-neutral-400 whitespace-nowrap">{{ a.created_at.replace('T', ' ').slice(0, 19) }}</span>
            </div>
            <div class="text-xs text-neutral-500">{{ a.user_name || a.user_email || '—' }}</div>
            <div v-if="a.payload" class="text-xs text-neutral-600 break-all whitespace-pre-wrap leading-snug">{{ payloadText(a.payload) }}</div>
          </li>
        </ul>
      </div>
    </div>

    <!-- Sekundární akce — pod fakturou (Test odeslání + admin/destrukční).
         Pro draft zobrazujeme kvůli „Test odeslání" + odkazu na klienta;
         vnitřní tlačítka mají vlastní v-if podmínky. -->
    <div v-if="invoice" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
      <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.more_actions') }}</h3>
      <div class="flex flex-wrap gap-2">
        <RouterLink :to="`/tri/contacts/${invoice.client_id}`"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z"/></svg>
          {{ t('invoice.client_detail') }}
        </RouterLink>

        <button v-if="canSendTest" @click="sendTest" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-primary-300 rounded-md text-primary-600 hover:bg-primary-50 disabled:opacity-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 1 1 7.072 0l-.548.547A3.374 3.374 0 0 0 14 18.469V19a2 2 0 1 1-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
          {{ busy === 'send-test' ? '…' : t('invoice.send_test') }}
        </button>

        <button v-if="requiresApproval" @click="requestApprovalTest" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-primary-300 rounded-md text-primary-600 hover:bg-primary-50 disabled:opacity-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 1 1 7.072 0l-.548.547A3.374 3.374 0 0 0 14 18.469V19a2 2 0 1 1-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
          {{ busy === 'approval-test' ? '…' : t('invoice.approval.test_send') }}
        </button>

        <button v-if="canSendTestReminder" @click="sendTestReminder" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-warning-500/40 rounded-md text-warning-600 hover:bg-warning-50 disabled:opacity-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
          {{ busy === 'send-test-reminder' ? '…' : t('invoice.send_test_reminder') }}
        </button>

        <button v-if="canAdminEdit" @click="editIssued" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-warning-500/50 text-warning-600 hover:bg-warning-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
          {{ t('invoice.edit_admin') }}
        </button>

        <button v-if="isAdmin && invoice.status === 'paid'" @click="unmarkPaid" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-warning-500/50 text-warning-600 hover:bg-warning-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 6h18M3 14h18M3 18h18"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 4l8 16"/></svg>
          {{ busy === 'unmark-paid' ? '…' : t('invoice.unmark_paid') }}
        </button>

        <button v-if="canCancel" @click="openCancelModal" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 11v6m4-6v6m1 5H9a2 2 0 0 1-2-2V7h10v13a2 2 0 0 1-2 2zM5 7h14l-1-3H6L5 7z"/></svg>
          {{ isCreditNoteSource ? t('invoice.cancel_credit_note') : t('invoice.cancel_or_credit') }}
        </button>

        <button v-if="isAdmin && (
            invoice.status === 'cancelled'
            || (invoice.invoice_type === 'cancellation' && invoice.parent_invoice_id)
          )"
          @click="deleteInvoice" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 11v6m4-6v6m1 5H9a2 2 0 0 1-2-2V7h10v13a2 2 0 0 1-2 2zM5 7h14l-1-3H6L5 7z"/></svg>
          {{ busy === 'delete' ? '…' : t('invoice.delete_cancelled') }}
        </button>

      </div>
    </div>

    <!-- Work report modal (jen pro draft + workflow projekty) -->
    <WorkReportModal v-if="invoice"
      v-model="wrModalOpen"
      :invoice-id="invoice.id"
      @saved="load" />

    <LinkedDocumentsPanel v-if="invoice" class="mt-4 block" entity-type="invoice" :entity-id="invoice.id" />
  </div>
</template>
