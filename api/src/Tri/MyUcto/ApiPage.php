<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

/**
 * Normalizace stránkovaných odpovědí MyÚčto API v1.
 */
final class ApiPage
{
    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function items(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
            if ($data === [] || array_is_list($data)) {
                /** @var list<array<string, mixed>> $out */
                $out = [];
                foreach ($data as $row) {
                    if (is_array($row)) {
                        $out[] = $row;
                    }
                }
                return $out;
            }
        }
        if ($payload !== [] && array_is_list($payload)) {
            /** @var list<array<string, mixed>> $out */
            $out = [];
            foreach ($payload as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            return $out;
        }

        return [];
    }

    /** @param array<string, mixed> $payload */
    public static function lastPage(array $payload): int
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        foreach (['last_page', 'pages', 'total_pages'] as $key) {
            if (isset($meta[$key]) && (int) $meta[$key] > 0) {
                return (int) $meta[$key];
            }
        }
        $total = (int) ($meta['total'] ?? 0);
        $per = (int) ($meta['per_page'] ?? 0);
        if ($total > 0 && $per > 0) {
            return (int) ceil($total / $per);
        }

        return 1;
    }

    public static function shouldWrite(?string $localUpdatedAt, ?string $remoteUpdatedAt): bool
    {
        if ($remoteUpdatedAt === null || $remoteUpdatedAt === '') {
            return $localUpdatedAt === null || $localUpdatedAt === '';
        }
        if ($localUpdatedAt === null || $localUpdatedAt === '') {
            return true;
        }

        return strcmp($remoteUpdatedAt, $localUpdatedAt) > 0;
    }
}
