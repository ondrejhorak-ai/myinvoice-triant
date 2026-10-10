import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { ref } from 'vue'
import ts from 'typescript'
const source = await readFile(new URL('../src/composables/tri/useAppliedFilters.ts', import.meta.url), 'utf8')
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText.replace("from 'vue'", `from '${import.meta.resolve('vue')}'`)
const { useAppliedFilters } = await import('data:text/javascript;base64,' + Buffer.from(js).toString('base64'))

test('counts applied fields including defaults; search, sorting and draft edits are independent', () => {
  const archive = ref(false), year = ref(2026), search = ref('synthetic'), sort = ref('revenue'), draftYear = ref(2025)
  const { conditions, clearFilters } = useAppliedFilters([
    { id: 'archive', active: () => !archive.value, label: () => 'Not archived', clear: () => { archive.value = true } },
    { id: 'year', active: () => !!year.value, label: () => `Year: ${year.value}`, clear: () => { year.value = 0 } },
  ])
  assert.equal(conditions.value.length, 2)
  draftYear.value = 2024
  assert.equal(conditions.value[1].label, 'Year: 2026')
  year.value = draftYear.value
  assert.equal(conditions.value[1].label, 'Year: 2024')
  clearFilters()
  assert.deepEqual(conditions.value, [])
  assert.equal(search.value, 'synthetic')
  assert.equal(sort.value, 'revenue')
})

test('multiple values and an interval count once each; removing one field preserves others', () => {
  const statuses = ref(['sent', 'paid']), range = ref({ from: '2026-01-01', to: '2026-12-31' })
  const { conditions, removeFilter } = useAppliedFilters([
    { id: 'status', active: () => statuses.value.length > 0, label: () => statuses.value.join(', '), clear: () => { statuses.value = [] } },
    { id: 'range', active: () => !!range.value.from || !!range.value.to, label: () => `${range.value.from} – ${range.value.to}`, clear: () => { range.value = { from: '', to: '' } } },
  ])
  assert.equal(conditions.value.length, 2)
  removeFilter('range')
  assert.equal(conditions.value.length, 1)
  assert.deepEqual(statuses.value, ['sent', 'paid'])
  assert.deepEqual(range.value, { from: '', to: '' })
  removeFilter('status')
  assert.deepEqual(conditions.value, [])
})
