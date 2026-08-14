<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

final class PriceListPricing
{
    public static function normalizeMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * `price_updated_at` se razítkuje jen při vzniku položky nebo při změně
     * `base_unit_price` — ne při každém uložení ostatních polí.
     */
    public static function shouldStampPriceUpdatedAt(bool $isNew, mixed $oldPrice, mixed $newPrice): bool
    {
        if ($isNew) {
            return true;
        }

        return self::normalizeMoney($oldPrice) !== self::normalizeMoney($newPrice);
    }
}
