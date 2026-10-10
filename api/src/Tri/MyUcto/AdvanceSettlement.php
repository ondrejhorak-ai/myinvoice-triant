<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

/**
 * Ruční vyúčtování zálohy (tlačítko „Vystavit fakturu k záloze“).
 * Úhrada sama daňový doklad nezakládá.
 */
final class AdvanceSettlement
{
    public const MIN_DUE_DAYS = 14;

    /**
     * @param array<string, mixed> $proforma
     * @return array{tax_date: string, due_date: string}
     */
    public static function dates(array $proforma): array
    {
        $paid = self::day($proforma['paid_at'] ?? null) ?? date('Y-m-d');

        return [
            'tax_date' => $paid,
            'due_date' => self::dueDate(
                $paid,
                self::day($proforma['issue_date'] ?? null),
                self::day($proforma['due_date'] ?? null),
            ),
        ];
    }

    public static function dueDate(string $paidOn, ?string $issueDate, ?string $proformaDue): string
    {
        $span = self::MIN_DUE_DAYS;
        if ($issueDate !== null && $proformaDue !== null) {
            $from = new \DateTimeImmutable($issueDate);
            $to = new \DateTimeImmutable($proformaDue);
            $diff = $from->diff($to);
            $days = (int) $diff->days;
            if ($diff->invert === 0 && $days > 0) {
                $span = $days;
            }
        }

        return (new \DateTimeImmutable($paidOn))->modify('+' . $span . ' days')->format('Y-m-d');
    }

    /**
     * Řádky finálního dokladu z položek zálohy. Cena je bez DPH, jak ji nese proforma.
     *
     * @param array<string, mixed> $proforma
     * @return list<array<string, mixed>>
     */
    public static function linesFromProforma(array $proforma): array
    {
        $items = $proforma['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = (float) ($item['unit_price_without_vat'] ?? 0);
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc === '' && abs($price) < 0.00001) {
                continue;
            }
            $out[] = [
                'description' => $desc !== '' ? $desc : 'Položka',
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price_without_vat' => $price,
                'vat_rate_id' => (int) ($item['vat_rate_id'] ?? 0),
                'unit' => (string) ($item['unit'] ?? 'ks') ?: 'ks',
                'order_index' => (int) ($item['order_index'] ?? $i),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $invoice */
    public static function needsAmountRepair(array $invoice): bool
    {
        $items = $invoice['items'] ?? [];
        $totals = is_array($invoice['totals'] ?? null) ? $invoice['totals'] : [];
        $with = (float) ($invoice['total_with_vat'] ?? ($totals['with_vat'] ?? 0));
        $empty = !is_array($items) || $items === [];

        return $empty || $with < 0.005;
    }

    private static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = (string) $value;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
