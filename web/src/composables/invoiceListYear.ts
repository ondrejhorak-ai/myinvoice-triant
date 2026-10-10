/**
 * Výchozí rok seznamu faktur.
 * Aktuální rok, pokud v něm jsou doklady; jinak poslední rok s doklady; jinak vše.
 */
export function resolveInvoiceListYear(
  yearsWithInvoices: readonly number[],
  calendarYear: number,
): number | '' {
  if (yearsWithInvoices.includes(calendarYear)) return calendarYear
  if (yearsWithInvoices.length === 0) return ''
  return Math.max(...yearsWithInvoices)
}
