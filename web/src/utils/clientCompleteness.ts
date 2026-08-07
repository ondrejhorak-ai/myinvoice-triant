export interface ClientAddressFields {
  street?: string | null
  city?: string | null
  zip?: string | null
}

export interface ClientEmailFields {
  main_email?: string | null
}

export type ClientCompletenessFields = ClientAddressFields & ClientEmailFields

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
  return clientMissingEmail(c) || clientMissingAddress(c)
}

export type ClientIncompleteKind = 'email' | 'address' | 'both'

export function clientIncompleteKind(c: ClientCompletenessFields): ClientIncompleteKind | null {
  const missingEmail = clientMissingEmail(c)
  const missingAddress = clientMissingAddress(c)
  if (!missingEmail && !missingAddress) return null
  if (missingEmail && missingAddress) return 'both'
  return missingEmail ? 'email' : 'address'
}
