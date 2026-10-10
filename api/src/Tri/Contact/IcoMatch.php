<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

/**
 * Automatické spojení s MyÚčtem jen při jediném přesném IČO.
 * Stejné jméno nebo e-mail se nespojuje.
 */
final class IcoMatch
{
    public static function digits(string $ic): string
    {
        return preg_replace('/\D/', '', $ic) ?? '';
    }

    /**
     * @param list<array<string, mixed>> $remote
     * @return list<int> vzdálená id s přesně stejným IČO
     */
    public static function exactIds(string $ic, array $remote): array
    {
        $ic = self::digits($ic);
        if (strlen($ic) !== 8) {
            return [];
        }
        $ids = [];
        foreach ($remote as $row) {
            $rowIc = self::digits((string) ($row['ic'] ?? ''));
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && $rowIc === $ic) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
