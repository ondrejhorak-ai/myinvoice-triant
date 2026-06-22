<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/**
 * Port of super-kancelar @triant/domain pricing (commission grossup, cascade discounts, VAT).
 */
final class QuoteCalculator
{
    public static function round2(float $n): float
    {
        return round($n, 2);
    }

    /**
     * Full variant pricing: commission override (quote > section > line), cascade discounts, VAT.
     *
     * @param list<array<string, mixed>> $sections
     * @param list<array<string, mixed>> $lines
     * @param array{
     *   quote_discount_type?: ?string,
     *   quote_discount_value?: float|string|null,
     *   quote_commission_type?: ?string,
     *   quote_commission_value?: float|string|null,
     * } $variant
     * @return array{
     *   lines: list<array<string, mixed>>,
     *   subtotal: float,
     *   vat_base_21: float,
     *   vat_amount_21: float,
     *   vat_base_12: float,
     *   vat_amount_12: float,
     *   vat_base_0: float,
     *   total_with_vat: float,
     *   commission_total: float,
     *   discount_total: float,
     * }
     */
    public function calculateVariant(array $sections, array $lines, array $variant): array
    {
        $sectionByKey = $this->buildSectionMap($sections);
        $quoteCommission = $this->parseAdjustment(
            $variant['quote_commission_type'] ?? null,
            isset($variant['quote_commission_value']) ? (float) $variant['quote_commission_value'] : null,
        );
        $quoteDiscount = $this->parseAdjustment(
            $variant['quote_discount_type'] ?? null,
            isset($variant['quote_discount_value']) ? (float) $variant['quote_discount_value'] : null,
        );

        $indexed = [];
        foreach ($lines as $idx => $line) {
            if (!is_array($line)) {
                continue;
            }
            $indexed[$idx] = [
                'input'      => $line,
                'line_base'  => self::round2((float) ($line['quantity'] ?? 0) * (float) ($line['base_unit_price'] ?? 0)),
                'section'    => $this->resolveSection($line, $sectionByKey),
            ];
        }

        $this->applyCommissions($indexed, $quoteCommission);

        $offerSum = 0.0;
        foreach ($indexed as &$row) {
            $row['after_line_discount'] = $this->applyAdjustment(
                $row['offer'],
                $this->parseAdjustment(
                    $row['input']['line_discount_type'] ?? null,
                    isset($row['input']['line_discount_value']) ? (float) $row['input']['line_discount_value'] : null,
                ),
            );
            $offerSum += $row['offer'];
        }
        unset($row);

        $this->applySectionDiscounts($indexed);
        $this->applyQuoteDiscountToLines($indexed, $quoteDiscount);

        $linesSubtotal = 0.0;
        $commissionTotal = 0.0;
        $pricedLines = [];
        foreach ($indexed as $idx => $row) {
            $lineTotalBeforeQuote = $row['line_total_before_quote'];
            $linesSubtotal += $lineTotalBeforeQuote;
            $commissionTotal += $row['markup_amount'];
            $pricedLines[$idx] = [
                'line_base'     => $row['line_base'],
                'markup_amount' => $row['markup_amount'],
                'line_total'    => $lineTotalBeforeQuote,
                'vat_rate'      => (int) ($row['input']['vat_rate'] ?? 21),
            ];
        }
        $linesSubtotal = self::round2($linesSubtotal);
        $commissionTotal = self::round2($commissionTotal);

        $subtotal = $this->applyAdjustment($linesSubtotal, $quoteDiscount);
        $discountTotal = self::round2(max(0, $offerSum - $subtotal));

        $ratio = $linesSubtotal > 0 ? $subtotal / $linesSubtotal : 1.0;

        $vatBase21 = 0.0;
        $vatBase12 = 0.0;
        $vatBase0 = 0.0;

        foreach ($pricedLines as $priced) {
            $adjusted = self::round2((float) $priced['line_total'] * $ratio);
            $rate = (int) ($priced['vat_rate'] ?? 21);
            if ($rate === 21) {
                $vatBase21 += $adjusted;
            } elseif ($rate === 12) {
                $vatBase12 += $adjusted;
            } else {
                $vatBase0 += $adjusted;
            }
        }

        $vatBase21 = self::round2($vatBase21);
        $vatBase12 = self::round2($vatBase12);
        $vatBase0 = self::round2($vatBase0);
        $vatAmount21 = self::round2($vatBase21 * 0.21);
        $vatAmount12 = self::round2($vatBase12 * 0.12);
        $totalWithVat = self::round2($vatBase21 + $vatAmount21 + $vatBase12 + $vatAmount12 + $vatBase0);

        return [
            'lines'            => array_values($pricedLines),
            'subtotal'         => $subtotal,
            'vat_base_21'      => $vatBase21,
            'vat_amount_21'    => $vatAmount21,
            'vat_base_12'      => $vatBase12,
            'vat_amount_12'    => $vatAmount12,
            'vat_base_0'       => $vatBase0,
            'total_with_vat'   => $totalWithVat,
            'commission_total' => $commissionTotal,
            'discount_total'   => $discountTotal,
        ];
    }

    /**
     * @deprecated Use calculateVariant(); kept for single-line preview compatibility.
     *
     * @param array<string, mixed> $line
     * @return array<string, float|int>
     */
    public function calculateLine(array $line): array
    {
        $result = $this->calculateVariant([], [$line], []);
        $priced = $result['lines'][0] ?? [
            'line_base'     => 0.0,
            'markup_amount' => 0.0,
            'line_total'    => 0.0,
            'vat_rate'      => (int) ($line['vat_rate'] ?? 21),
        ];

        return [
            'line_base'     => (float) $priced['line_base'],
            'markup_amount' => (float) $priced['markup_amount'],
            'line_total'    => (float) $priced['line_total'],
            'vat_rate'      => (int) $priced['vat_rate'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines priced lines with line_total + vat_rate
     * @param array{quote_discount_type?: ?string, quote_discount_value?: float|string|null}|null $quoteDiscount
     * @return array<string, float>
     * @deprecated Use calculateVariant()
     */
    public function calculateVariantTotals(array $lines, ?array $quoteDiscount = null): array
    {
        $variant = [
            'quote_discount_type'  => $quoteDiscount['quote_discount_type'] ?? null,
            'quote_discount_value' => $quoteDiscount['quote_discount_value'] ?? null,
        ];
        $inputLines = array_map(static fn (array $l) => [
            'quantity'        => 1,
            'base_unit_price' => (float) ($l['line_total'] ?? 0),
            'vat_rate'        => (int) ($l['vat_rate'] ?? 21),
        ], $lines);

        $result = $this->calculateVariant([], $inputLines, $variant);

        return [
            'subtotal'         => $result['subtotal'],
            'vat_base_21'      => $result['vat_base_21'],
            'vat_amount_21'    => $result['vat_amount_21'],
            'vat_base_12'      => $result['vat_base_12'],
            'vat_amount_12'    => $result['vat_amount_12'],
            'vat_base_0'       => $result['vat_base_0'],
            'total_with_vat'   => $result['total_with_vat'],
            'commission_total' => $result['commission_total'],
            'discount_total'   => $result['discount_total'],
        ];
    }

    /** @param list<array<string, mixed>> $sections */
    private function buildSectionMap(array $sections): array
    {
        $map = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $entry = [
                'discount'   => $this->parseAdjustment(
                    $section['discount_type'] ?? null,
                    isset($section['discount_value']) ? (float) $section['discount_value'] : null,
                ),
                'commission' => $this->parseAdjustment(
                    $section['commission_type'] ?? null,
                    isset($section['commission_value']) ? (float) $section['commission_value'] : null,
                ),
            ];
            if (isset($section['id'])) {
                $map[(string) $section['id']] = $entry;
            }
            if (isset($section['temp_id'])) {
                $map[(string) $section['temp_id']] = $entry;
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $line */
    private function resolveSection(array $line, array $sectionByKey): ?array
    {
        $ref = $line['quote_section_id'] ?? $line['section_temp_id'] ?? null;
        if ($ref === null) {
            return null;
        }

        return $sectionByKey[(string) $ref] ?? null;
    }

  /**
   * @param array<int, array<string, mixed>> $indexed
   * @param array{type: ?string, value: ?float}|null $quoteCommission
   */
    private function applyCommissions(array &$indexed, ?array $quoteCommission): void
    {
        $hasQuoteCommission = $quoteCommission !== null;
        $quoteBases = [];
        $sectionBases = [];

        foreach ($indexed as $idx => $row) {
            $base = $row['line_base'];
            if ($hasQuoteCommission && $quoteCommission['type'] === 'absolute') {
                $quoteBases[$idx] = $base;
            }
            $section = $row['section'];
            if (!$hasQuoteCommission && $section !== null && ($section['commission']['type'] ?? null) === 'absolute') {
                $sectionBases[$idx] = $base;
            }
        }

        $quoteBaseSum = array_sum($quoteBases);
        $sectionBaseSums = [];
        foreach ($sectionBases as $idx => $base) {
            $sectionRef = (string) ($indexed[$idx]['input']['quote_section_id'] ?? $indexed[$idx]['input']['section_temp_id'] ?? '');
            $sectionBaseSums[$sectionRef] = ($sectionBaseSums[$sectionRef] ?? 0) + $base;
        }

        foreach ($indexed as $idx => &$row) {
            $base = $row['line_base'];
            $section = $row['section'];
            $lineCommission = $this->parseAdjustment(
                $row['input']['markup_type'] ?? null,
                isset($row['input']['markup_value']) ? (float) $row['input']['markup_value'] : null,
            );

            if ($hasQuoteCommission) {
                $offer = $this->applyCommissionGrossup($base, $quoteCommission, $quoteBaseSum > 0 ? $base / $quoteBaseSum : 0);
            } elseif ($section !== null && ($section['commission']['type'] ?? null) !== null) {
                $sectionRef = (string) ($row['input']['quote_section_id'] ?? $row['input']['section_temp_id'] ?? '');
                $sectionSum = $sectionBaseSums[$sectionRef] ?? $base;
                $share = $sectionSum > 0 ? $base / $sectionSum : 0;
                $offer = $this->applyCommissionGrossup($base, $section['commission'], $share);
            } else {
                $offer = $this->applyCommissionGrossup($base, $lineCommission, 1.0);
            }

            $row['offer'] = $offer;
            $row['markup_amount'] = self::round2($offer - $base);
        }
        unset($row);
    }

    /** @param array<int, array<string, mixed>> $indexed */
    private function applySectionDiscounts(array &$indexed): void
    {
        $sectionGroups = [];
        foreach ($indexed as $idx => $row) {
            $ref = $row['input']['quote_section_id'] ?? $row['input']['section_temp_id'] ?? null;
            if ($ref === null || $row['section'] === null) {
                $indexed[$idx]['line_total_before_quote'] = $row['after_line_discount'];
                continue;
            }
            $sectionGroups[(string) $ref][] = $idx;
        }

        foreach ($sectionGroups as $ref => $indices) {
            $section = $indexed[$indices[0]]['section'];
            $discount = $section['discount'] ?? null;
            if ($discount === null) {
                foreach ($indices as $idx) {
                    $indexed[$idx]['line_total_before_quote'] = $indexed[$idx]['after_line_discount'];
                }
                continue;
            }

            $amounts = [];
            $sum = 0.0;
            foreach ($indices as $idx) {
                $amounts[$idx] = $indexed[$idx]['after_line_discount'];
                $sum += $amounts[$idx];
            }
            $sum = self::round2($sum);

            if ($discount['type'] === 'percent') {
                foreach ($indices as $idx) {
                    $indexed[$idx]['line_total_before_quote'] = $this->applyAdjustment(
                        $amounts[$idx],
                        $discount,
                    );
                }
            } else {
                $remaining = self::round2(min((float) $discount['value'], $sum));
                $lastIdx = $indices[array_key_last($indices)];
                foreach ($indices as $idx) {
                    if ($idx === $lastIdx) {
                        $indexed[$idx]['line_total_before_quote'] = self::round2(max(0, $amounts[$idx] - $remaining));
                        continue;
                    }
                    $share = $sum > 0 ? $amounts[$idx] / $sum : 0;
                    $cut = self::round2((float) $discount['value'] * $share);
                    $remaining -= $cut;
                    $indexed[$idx]['line_total_before_quote'] = self::round2(max(0, $amounts[$idx] - $cut));
                }
            }
        }
    }

    /**
     * Quote discount is applied only at aggregate level for VAT; line_total stays pre-quote discount.
     *
     * @param array<int, array<string, mixed>> $indexed
     * @param array{type: ?string, value: ?float}|null $quoteDiscount
     */
    private function applyQuoteDiscountToLines(array &$indexed, ?array $quoteDiscount): void
    {
        foreach ($indexed as &$row) {
            if (!isset($row['line_total_before_quote'])) {
                $row['line_total_before_quote'] = $row['after_line_discount'];
            }
        }
        unset($row);
    }

    /** @return array{type: string, value: float}|null */
    private function parseAdjustment(?string $type, ?float $value): ?array
    {
        if ($type === null || $value === null || $value <= 0) {
            return null;
        }
        if ($type !== 'percent' && $type !== 'absolute') {
            return null;
        }

        return ['type' => $type, 'value' => $value];
    }

    private function applyCommissionGrossup(float $base, ?array $commission, float $share): float
    {
        if ($commission === null) {
            return self::round2($base);
        }

        if ($commission['type'] === 'percent') {
            $pct = (float) $commission['value'];
            if ($pct >= 100) {
                return self::round2($base);
            }

            return self::round2($base / (1 - $pct / 100));
        }

        return self::round2($base + (float) $commission['value'] * $share);
    }

    /** @param array{type: string, value: float}|null $adjustment */
    private function applyAdjustment(float $amount, ?array $adjustment): float
    {
        if ($adjustment === null) {
            return self::round2($amount);
        }

        if ($adjustment['type'] === 'percent') {
            return self::round2($amount * (1 - (float) $adjustment['value'] / 100));
        }

        return self::round2(max(0, $amount - (float) $adjustment['value']));
    }
}
