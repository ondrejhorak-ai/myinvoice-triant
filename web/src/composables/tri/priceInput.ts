/** Invert a monotone backend-compatible preview without changing the discount hierarchy. */
export function unitPriceForTotal(
  target: number,
  quantity: number,
  evaluate: (unit: number) => number,
): number | null {
  if (
    !Number.isFinite(target) ||
    target < 0 ||
    !Number.isFinite(quantity) ||
    quantity <= 0
  )
    return null
  let lo = 0
  let hi = Math.max(1, (target / quantity) * 2)
  if (evaluate(0) > target + 0.01) return null
  for (let i = 0; i < 40 && evaluate(hi) < target; i++) hi *= 2
  if (evaluate(hi) < target || evaluate(hi) === evaluate(0)) return null
  for (let i = 0; i < 70; i++) {
    const mid = (lo + hi) / 2
    if (evaluate(mid) < target) lo = mid
    else hi = mid
  }
  const unit = Math.round(((lo + hi) / 2 + Number.EPSILON) * 100) / 100
  return Math.abs(evaluate(unit) - target) <=
    Math.max(0.01, quantity * 0.005 + 0.005)
    ? unit
    : null
}
