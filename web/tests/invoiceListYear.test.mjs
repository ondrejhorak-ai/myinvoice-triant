import assert from 'node:assert/strict'
import test from 'node:test'
import { resolveInvoiceListYear } from '../src/composables/invoiceListYear.ts'

test('current year wins when it has invoices', () => {
  assert.equal(resolveInvoiceListYear([2024, 2026, 2025], 2026), 2026)
})

test('latest year with invoices when the current year is empty', () => {
  assert.equal(resolveInvoiceListYear([2023, 2025], 2026), 2025)
})

test('all years when there are no invoices', () => {
  assert.equal(resolveInvoiceListYear([], 2026), '')
})
