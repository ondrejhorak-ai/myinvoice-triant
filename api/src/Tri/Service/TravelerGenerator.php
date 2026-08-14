<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/**
 * Plán generování průvodek ze schválené varianty. Bez cen.
 * Idempotence: existující průvodku nepřepisuje (ani snapshot), jen doplní chybějící stanoviště.
 */
final class TravelerGenerator
{
    public const STATIONS = [
        'konstrukce',
        'narezove_centrum',
        'cnc',
        'olepovacka',
        'dyhovani_brouseni',
        'montaz',
        'lakovna',
        'brouseni',
        'baleni',
    ];

    /**
     * @param list<array<string, mixed>> $lines položky schválené varianty
     * @param list<array<string, mixed>> $existingTravelers s `quote_line_item_id`, `id`, `operations`
     * @return array{create: list<array<string, mixed>>, missing_operations: list<array{traveler_id: int, station: string}>}
     */
    public static function plan(array $lines, array $existingTravelers): array
    {
        $byLine = [];
        foreach ($existingTravelers as $traveler) {
            $lineId = $traveler['quote_line_item_id'] ?? null;
            if ($lineId === null || $lineId === '') {
                continue;
            }
            $byLine[(int) $lineId] = $traveler;
        }

        $create = [];
        $missingOperations = [];

        foreach ($lines as $line) {
            if (!is_array($line) || !self::isManufactured($line) || empty($line['id'])) {
                continue;
            }
            $lineId = (int) $line['id'];
            if (!isset($byLine[$lineId])) {
                $row = self::snapshotFromLine($line);
                $row['stations'] = self::STATIONS;
                $create[] = $row;
                continue;
            }

            $existing = $byLine[$lineId];
            $have = [];
            foreach ($existing['operations'] ?? [] as $op) {
                if (!is_array($op)) {
                    continue;
                }
                $station = (string) ($op['station'] ?? '');
                if ($station !== '') {
                    $have[$station] = true;
                }
            }
            foreach (self::STATIONS as $station) {
                if (!isset($have[$station])) {
                    $missingOperations[] = [
                        'traveler_id' => (int) $existing['id'],
                        'station'     => $station,
                    ];
                }
            }
        }

        return [
            'create'              => $create,
            'missing_operations'  => $missingOperations,
        ];
    }

    /**
     * Snapshot textů a množství — bez cen a bez fotky (fotka zůstane na řádku nabídky).
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public static function snapshotFromLine(array $line): array
    {
        $title = trim((string) ($line['title'] ?? ''));
        $designation = (string) ($line['designation'] ?? '');
        if ($title === '') {
            $title = trim($designation) !== '' ? trim($designation) : 'Položka';
        }
        $qty = (float) ($line['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = trim((string) ($line['unit'] ?? 'ks'));

        return [
            'quote_line_item_id' => (int) $line['id'],
            'designation'        => $designation,
            'title'              => $title,
            'description'        => isset($line['description']) && $line['description'] !== ''
                ? (string) $line['description']
                : null,
            'quantity'           => $qty,
            'unit'               => $unit !== '' ? $unit : 'ks',
        ];
    }

    /** @param array<string, mixed> $line */
    public static function isManufactured(array $line): bool
    {
        if (!array_key_exists('is_manufactured', $line)) {
            return true;
        }
        $value = $line['is_manufactured'];
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return (bool) $value;
    }
}
