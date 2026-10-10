/** ISO `yyyy-mm-dd` ↔ české `dd. mm. yyyy`. Datum bez času se neposouvá o pásmo. */

export function formatIsoDateCs(value: string | number | null | undefined): string {
  if (value == null || value === '') return ''
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value))
  if (!match) return ''
  return `${match[3]}. ${match[2]}. ${match[1]}`
}

export function parseCzechDate(text: string): string | null {
  const match = /^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/.exec(text.trim())
  if (!match) return null
  const day = Number(match[1])
  const month = Number(match[2])
  const year = Number(match[3])
  if (month < 1 || month > 12 || day < 1 || day > 31) return null
  const probe = new Date(Date.UTC(year, month - 1, day))
  if (
    probe.getUTCFullYear() !== year
    || probe.getUTCMonth() + 1 !== month
    || probe.getUTCDate() !== day
  ) return null
  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

export function formatIsoTime(value: string | null | undefined): string {
  if (!value) return ''
  const match = /^(\d{1,2}):(\d{2})/.exec(value.trim())
  if (!match) return ''
  const hour = Number(match[1])
  const minute = Number(match[2])
  if (hour > 23 || minute > 59) return ''
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`
}

export function parseCzechTime(text: string): string | null {
  const match = /^(\d{1,2})[:.](\d{2})$/.exec(text.trim())
  if (!match) return null
  return formatIsoTime(`${match[1]}:${match[2]}`) || null
}
