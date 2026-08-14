<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/**
 * Součty a normalizace hodin na průvodkách. Hodiny se přepisují z papíru.
 */
final class TravelerHours
{
    /**
     * @param list<array<string, mixed>> $operations
     * @return array{total: float, by_station: list<array{station: string, hours: float}>}
     */
    public static function summarize(array $operations): array
    {
        $cents = array_fill_keys(TravelerGenerator::STATIONS, 0);
        foreach ($operations as $op) {
            if (!is_array($op)) {
                continue;
            }
            $station = (string) ($op['station'] ?? '');
            if (!isset($cents[$station])) {
                continue;
            }
            if (!array_key_exists('hours', $op) || $op['hours'] === null || $op['hours'] === '') {
                continue;
            }
            try {
                $hours = self::parseHours($op['hours']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($hours === null) {
                continue;
            }
            $cents[$station] += (int) round($hours * 100);
        }

        $byStation = [];
        $totalCents = 0;
        foreach (TravelerGenerator::STATIONS as $station) {
            if ($cents[$station] <= 0) {
                continue;
            }
            $hours = round($cents[$station] / 100, 2);
            $byStation[] = ['station' => $station, 'hours' => $hours];
            $totalCents += $cents[$station];
        }

        return [
            'total'      => round($totalCents / 100, 2),
            'by_station' => $byStation,
        ];
    }

    /**
     * @param list<array<string, mixed>> $travelers
     * @return array{total: float, by_station: list<array{station: string, hours: float}>}
     */
    public static function summarizeTravelers(array $travelers): array
    {
        $ops = [];
        foreach ($travelers as $traveler) {
            if (!is_array($traveler)) {
                continue;
            }
            foreach ($traveler['operations'] ?? [] as $op) {
                if (is_array($op)) {
                    $ops[] = $op;
                }
            }
        }

        return self::summarize($ops);
    }

    /** @param list<array<string, mixed>> $operations */
    public static function travelerTotal(array $operations): float
    {
        return self::summarize($operations)['total'];
    }

    public static function parseHours(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(["\u{00A0}", ' '], '', trim($value));
            if ($value === '') {
                return null;
            }
            $value = str_replace(',', '.', $value);
        }
        if (is_bool($value) || $value === [] || is_array($value)) {
            throw new \InvalidArgumentException('INVALID_HOURS');
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('INVALID_HOURS');
        }
        $hours = round((float) $value, 2);
        if ($hours < 0 || $hours > 9999.99) {
            throw new \InvalidArgumentException('INVALID_HOURS');
        }

        return $hours;
    }

    /**
     * @param mixed $raw
     * @return list<array{station: string, hours: ?float, note?: ?string}>
     */
    public static function normalizeOperations(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('INVALID_OPERATIONS');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('INVALID_OPERATIONS');
            }
            $station = (string) ($row['station'] ?? '');
            if (!in_array($station, TravelerGenerator::STATIONS, true)) {
                throw new \InvalidArgumentException('INVALID_STATION');
            }
            if (isset($seen[$station])) {
                throw new \InvalidArgumentException('DUPLICATE_STATION');
            }
            $seen[$station] = true;
            $normalized = [
                'station' => $station,
                'hours'   => self::parseHours($row['hours'] ?? null),
            ];
            if (array_key_exists('note', $row)) {
                $note = trim((string) $row['note']);
                if (strlen($note) > 255) {
                    throw new \InvalidArgumentException('NOTE_TOO_LONG');
                }
                $normalized['note'] = $note !== '' ? $note : null;
            }
            $out[] = $normalized;
        }

        return $out;
    }
}
