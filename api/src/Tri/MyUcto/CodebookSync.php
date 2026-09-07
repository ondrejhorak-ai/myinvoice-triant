<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

final class CodebookSync
{
    public function __construct(
        private readonly MyUctoClient $api,
        private readonly MirrorWriter $writer,
    ) {}

    public function run(): int
    {
        $n = 0;
        $n += $this->syncVatRates();
        $n += $this->syncCurrencies();
        $n += $this->syncUnits();
        $n += $this->syncBranding();

        return $n;
    }

    private function syncVatRates(): int
    {
        $payload = $this->tryFetch(fn () => $this->api->vatRates(), fn () => $this->api->codebook('vat-rates'));
        $n = 0;
        $now = $this->writer->now();
        foreach (ApiPage::items($payload) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $this->writer->upsert('mu_vat_rates', [
                'id' => $id,
                'code' => self::str($row['code'] ?? $row['name'] ?? null),
                'name' => self::vatName($row),
                'rate' => number_format(self::vatPercent($row), 3, '.', ''),
                'is_active' => empty($row['archived_at']) && ($row['is_active'] ?? true) ? 1 : 0,
                'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mu_synced_at' => $now,
            ], ['code', 'name', 'rate', 'is_active', 'raw_json', 'mu_synced_at']);
            $n++;
        }

        return $n;
    }

    private function syncCurrencies(): int
    {
        $payload = $this->tryFetch(fn () => $this->api->currencies(), fn () => $this->api->codebook('currencies'));
        $n = 0;
        $now = $this->writer->now();
        foreach (ApiPage::items($payload) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $code = strtoupper((string) ($row['code'] ?? ''));
            if ($id <= 0 && $code === '') {
                continue;
            }
            if ($id <= 0) {
                $id = crc32($code) & 0x7fffffff;
            }
            $this->writer->upsert('mu_currencies', [
                'id' => $id,
                'code' => substr($code !== '' ? $code : 'XXX', 0, 3),
                'name' => self::str($row['name'] ?? $row['label'] ?? $code),
                'is_active' => empty($row['archived_at']) && ($row['is_active'] ?? true) ? 1 : 0,
                'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mu_synced_at' => $now,
            ], ['code', 'name', 'is_active', 'raw_json', 'mu_synced_at']);
            $n++;
        }

        return $n;
    }

    private function syncUnits(): int
    {
        $payload = $this->api->codebook('units');
        $n = 0;
        $now = $this->writer->now();
        foreach (ApiPage::items($payload) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $code = (string) ($row['code'] ?? $row['name'] ?? '');
            if ($id <= 0 && $code === '') {
                continue;
            }
            if ($id <= 0) {
                $id = crc32($code) & 0x7fffffff;
            }
            $this->writer->upsert('mu_units', [
                'id' => $id,
                'code' => self::str($row['code'] ?? $code),
                'name' => self::str($row['name'] ?? $row['label'] ?? $code),
                'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mu_synced_at' => $now,
            ], ['code', 'name', 'raw_json', 'mu_synced_at']);
            $n++;
        }

        return $n;
    }

    private function syncBranding(): int
    {
        $payload = $this->api->brandingProfiles();
        $n = 0;
        $now = $this->writer->now();
        foreach (ApiPage::items($payload) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $this->writer->upsert('mu_branding_profiles', [
                'id' => $id,
                'name' => self::str($row['name'] ?? $row['label'] ?? null),
                'is_default' => !empty($row['is_default']) ? 1 : 0,
                'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mu_synced_at' => $now,
            ], ['name', 'is_default', 'raw_json', 'mu_synced_at']);
            $n++;
        }

        return $n;
    }

    /**
     * @param callable(): array<string, mixed> $primary
     * @param callable(): array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private function tryFetch(callable $primary, callable $fallback): array
    {
        try {
            return $primary();
        } catch (MyUctoApiException) {
            return $fallback();
        }
    }

    /**
     * MyÚčto settings/codebooks vrací `rate_percent` (a `label_cs`), ne `rate`/`name`.
     *
     * @param array<string, mixed> $row remote nebo řádek zrcadla (vč. raw_json)
     */
    public static function vatPercent(array $row): float
    {
        $fields = self::vatFields($row);
        foreach (['rate_percent', 'rate', 'value'] as $key) {
            if (!isset($fields[$key]) || $fields[$key] === '' || $fields[$key] === null) {
                continue;
            }
            $n = (float) $fields[$key];
            if ($key === 'rate' && abs($n) < 0.001 && isset($fields['rate_percent'])) {
                continue;
            }
            return $n;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function vatName(array $row): ?string
    {
        $fields = self::vatFields($row);

        return self::str($fields['name'] ?? $fields['label_cs'] ?? $fields['label'] ?? $fields['label_en'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function vatIsReverseCharge(array $row): bool
    {
        $fields = self::vatFields($row);

        return !empty($fields['is_reverse_charge']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function vatFields(array $row): array
    {
        $raw = [];
        if (isset($row['raw_json']) && is_string($row['raw_json']) && $row['raw_json'] !== '') {
            $decoded = json_decode($row['raw_json'], true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $merged = $raw;
        foreach ($row as $key => $value) {
            if ($key === 'raw_json' || $value === null || $value === '') {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
