<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/**
 * Snapshot položky ceníku do řádku nabídky. Doklad se později od ceníku oddělí —
 * hodnoty jsou zkopírované a volně přepsatelné; `catalog_item_id` je jen odkaz.
 */
final class CatalogSnapshot
{
    /**
     * @param array<string, mixed> $item položka ceníku (cast z PriceListRepository)
     * @return array<string, mixed>
     */
    public static function toQuoteLine(array $item): array
    {
        $qty = (float) ($item['default_quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = trim((string) ($item['unit'] ?? 'ks'));
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
            'quantity'         => $qty,
            'unit'             => $unit !== '' ? $unit : 'ks',
            'base_unit_price'  => (float) ($item['base_unit_price'] ?? 0),
            'vat_rate'         => (int) ($item['vat_rate'] ?? 21),
            'is_manufactured'  => true,
        ];
    }
}
