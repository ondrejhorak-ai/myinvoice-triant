import test from 'node:test'
import assert from 'node:assert/strict'
import {readFile} from 'node:fs/promises'
import ts from 'typescript'
async function loadTs(path){const source=await readFile(new URL(path,import.meta.url),'utf8');const js=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;return import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'))}
const {unitPriceForTotal}=await loadTs('../src/composables/tri/priceInput.ts')
const pricing=await loadTs('../src/composables/useTriQuotePricing.ts')
test('inverse total respects quantity and successive discounts',()=>{const evaluate=unit=>Math.round(unit*3*.9*.8*100)/100;const unit=unitPriceForTotal(216,3,evaluate);assert.equal(unit,100);assert.equal(evaluate(unit),216)})
test('invalid, negative and non-invertible totals are rejected',()=>{assert.equal(unitPriceForTotal(100,0,u=>u),null);assert.equal(unitPriceForTotal(Infinity,1,u=>u),null);assert.equal(unitPriceForTotal(-1,1,u=>u),null);assert.equal(unitPriceForTotal(0,1,()=>0),null)})
test('zero target is preserved when the row is invertible',()=>assert.equal(unitPriceForTotal(0,2,u=>2*u),0))
test('zero VAT stays zero in the quote preview',()=>{const result=pricing.calculateVariantPricing([],[{quantity:2,base_unit_price:100,vat_rate:0}],{});assert.equal(result.total,200);assert.equal(result.base0,200);assert.equal(result.vat21,0)})
