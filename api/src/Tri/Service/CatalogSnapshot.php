<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/**
 * Snapshot položky ceníku do řádku nabídky nebo faktury. Doklad se později
 * od ceníku oddělí — hodnoty jsou zkopírované a volně přepsatelné.
 */
final class CatalogSnapshot
{
    /**
     * @param array<string, mixed> $item položka ceníku (cast z PriceListRepository)
     * @return array<string, mixed>
     */
    public static function toQuoteLine(array $item): array
    {
        $image = is_array($item['image'] ?? null) ? $item['image'] : null;

        return [
            'catalog_item_id'  => isset($item['id']) ? (int) $item['id'] : null,
            'image_id'         => !empty($item['image_id']) ? (int) $item['image_id'] : ($image['id'] ?? null),
            'image'            => $image,
            'designation'      => (string) ($item['designation'] ?? ''),
            'title'            => (string) ($item['title'] ?? ''),
            'description'      => isset($item['description']) && $item['description'] !== ''
                ? (string) $item['description']
                : null,
            'quantity'         => self::quantity($item),
            'unit'             => self::unit($item),
            'base_unit_price'  => (float) ($item['base_unit_price'] ?? 0),
            'vat_rate'         => (int) ($item['vat_rate'] ?? 21),
            'is_manufactured'  => true,
        ];
    }

    /**
     * Snapshot do běžného `invoice_items` řádku (bez fotky a bez FK na ceník).
     * `vat_rate` je procento; ID sazby se namapuje přes {@see mapVatRateId()}.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function toInvoiceLine(array $item): array
    {
        return [
            'description'            => self::invoiceDescription($item),
            'quantity'               => self::quantity($item),
            'unit'                   => self::unit($item),
            'unit_price_without_vat' => (float) ($item['base_unit_price'] ?? 0),
            'vat_rate'               => (int) ($item['vat_rate'] ?? 21),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rates číselník sazeb (id, rate_percent, is_reverse_charge)
     */
    public static function mapVatRateId(int $percent, array $rates, int $fallbackId): int
    {
        foreach ($rates as $rate) {
            if (!empty($rate['is_reverse_charge'])) {
                continue;
            }
            if ((int) round((float) ($rate['rate_percent'] ?? -1)) === $percent) {
                return (int) $rate['id'];
            }
        }

        return $fallbackId;
    }

    /** @param array<string, mixed> $item */
    private static function quantity(array $item): float
    {
        $qty = (float) ($item['default_quantity'] ?? 1);
        return $qty > 0 ? $qty : 1.0;
    }

    /** @param array<string, mixed> $item */
    private static function unit(array $item): string
    {
        $unit = trim((string) ($item['unit'] ?? 'ks'));

        return $unit !== '' ? $unit : 'ks';
    }

    /** @param array<string, mixed> $item */
    private static function invoiceDescription(array $item): string
    {
        $head = trim(trim((string) ($item['designation'] ?? '')) . ' ' . trim((string) ($item['title'] ?? '')));
        $extra = trim((string) ($item['description'] ?? ''));
        if ($head !== '' && $extra !== '') {
            return $head . "\n" . $extra;
        }

        return $head !== '' ? $head : $extra;
    }
}
