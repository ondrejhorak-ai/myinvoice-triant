export interface ClientAddressFields {
  street?: string | null
  city?: string | null
  zip?: string | null
}

export interface ClientEmailFields {
  main_email?: string | null
}

export type ClientCompletenessFields = ClientAddressFields & ClientEmailFields & {
  party_kind?: string | null
}

function isBlank(value: string | null | undefined): boolean {
  return (value ?? '').trim() === ''
}

export function clientMissingEmail(c: ClientEmailFields): boolean {
  return isBlank(c.main_email)
}

export function clientMissingAddress(c: ClientAddressFields): boolean {
  // List API dříve neposílal adresu — bez polí nehodnotíme (ne false positive).
  if (c.street === undefined && c.city === undefined && c.zip === undefined) {
    return false
  }
  return isBlank(c.street) || isBlank(c.city) || isBlank(c.zip)
}

export function clientIsIncomplete(c: ClientCompletenessFields): boolean {
  if (c.party_kind === 'person') return clientMissingEmail(c)
  return false
}

export type ClientIncompleteKind = 'email' | 'address' | 'both'

export function clientIncompleteKind(c: ClientCompletenessFields): ClientIncompleteKind | null {
  if (c.party_kind === 'person' && clientMissingEmail(c)) return 'email'
  return null
}
