import assert from 'node:assert/strict'
import test from 'node:test'
import { formatIsoDateCs, parseCzechDate, parseCzechTime } from '../src/composables/czechDate.ts'

test('české datum má mezery a vedoucí nuly', () => {
  assert.equal(formatIsoDateCs('2026-09-21'), '21. 09. 2026')
  assert.equal(formatIsoDateCs('2026-10-10T00:00:00'), '10. 10. 2026')
  assert.equal(formatIsoDateCs(''), '')
})

test('české datum se vrací na ISO a odmítne neexistující den', () => {
  assert.equal(parseCzechDate('21. 09. 2026'), '2026-09-21')
  assert.equal(parseCzechDate('21.9.2026'), '2026-09-21')
  assert.equal(parseCzechDate('31. 02. 2026'), null)
  assert.equal(parseCzechDate('10/10/2026'), null)
})

test('čas zůstává ve 24hodinovém tvaru', () => {
  assert.equal(parseCzechTime('9:05'), '09:05')
  assert.equal(parseCzechTime('18.30'), '18:30')
  assert.equal(parseCzechTime('9:60'), null)
})
