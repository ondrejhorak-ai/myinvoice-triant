<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

final class ClientSync
{
    public function __construct(
        private readonly MyUctoClient $api,
        private readonly PDO $pdo,
        private readonly int $supplierId,
    ) {}

    /** @return int počet zpracovaných vzdálených záznamů */
    public function run(): int
    {
        $seen = [];
        $n = 0;
        foreach ([false, true] as $archived) {
            $page = 1;
            do {
                $query = ['per_page' => 200, 'page' => $page];
                if ($archived) {
                    $query['filter'] = ['archived' => 'true'];
                }
                $payload = $this->api->listClients($query);
                $items = ApiPage::items($payload);
                foreach ($items as $remote) {
                    $id = (int) ($remote['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $seen[$id] = true;
                    $this->upsert($remote);
                    $n++;
                }
                $last = ApiPage::lastPage($payload);
                $page++;
            } while ($page <= $last && $items !== []);
        }

        $this->archiveMissing(array_keys($seen));

        return $n;
    }

    /** @param array<string, mixed> $remote */
    public function upsert(array $remote): void
    {
        $myuctoId = (int) ($remote['id'] ?? 0);
        if ($myuctoId <= 0) {
            return;
        }
        $remoteUpdated = self::remoteUpdatedAt($remote);
        $existing = $this->findByMyuctoId($myuctoId);
        $localUpdated = is_array($existing) ? ($existing['myucto_updated_at'] ?? null) : null;
        if ($existing !== null && !ApiPage::shouldWrite(
            is_string($localUpdated) ? $localUpdated : null,
            $remoteUpdated,
        )) {
            $this->touchSynced($myuctoId);
            return;
        }

        $countryId = $this->countryIdFromIso2((string) ($remote['country_iso2'] ?? $remote['country'] ?? 'CZ'));
        $currencyId = $this->currencyId();
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $archivedAt = !empty($remote['archived_at']) ? self::normalizeDt((string) $remote['archived_at']) : null;
        $isCustomer = array_key_exists('is_customer', $remote) ? (int) (bool) $remote['is_customer'] : 1;
        $isVendor = array_key_exists('is_vendor', $remote) ? (int) (bool) $remote['is_vendor'] : 0;
        if ($isCustomer === 0 && $isVendor === 0) {
            $isCustomer = 1;
        }

        $fields = [
            'company_name' => (string) ($remote['company_name'] ?? ''),
            'first_name' => self::nullableStr($remote['first_name'] ?? null),
            'last_name' => self::nullableStr($remote['last_name'] ?? null),
            'ic' => self::nullableStr($remote['ic'] ?? null),
            'dic' => self::nullableStr($remote['dic'] ?? null),
            'street' => (string) ($remote['street'] ?? ''),
            'city' => (string) ($remote['city'] ?? ''),
            'zip' => (string) ($remote['zip'] ?? ''),
            'country_id' => $countryId,
            'main_email' => self::nullableStr($remote['main_email'] ?? null),
            'phone' => self::nullableStr($remote['phone'] ?? null),
            'is_customer' => $isCustomer,
            'is_vendor' => $isVendor,
            'payment_due_default' => isset($remote['payment_due_default']) ? (int) $remote['payment_due_default'] : null,
            'archived_at' => $archivedAt,
            'myucto_id' => $myuctoId,
            'myucto_updated_at' => $remoteUpdated,
            'mu_synced_at' => $now,
        ];

        if ($existing === null) {
            $sql = 'INSERT INTO clients
                (supplier_id, company_name, first_name, last_name, ic, dic, street, city, zip, country_id,
                 main_email, phone, language, currency_default_id, is_customer, is_vendor, payment_due_default,
                 archived_at, myucto_id, myucto_updated_at, mu_synced_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $this->pdo->prepare($sql)->execute([
                $this->supplierId,
                $fields['company_name'],
                $fields['first_name'],
                $fields['last_name'],
                $fields['ic'],
                $fields['dic'],
                $fields['street'],
                $fields['city'],
                $fields['zip'],
                $fields['country_id'],
                $fields['main_email'],
                $fields['phone'],
                'cs',
                $currencyId,
                $fields['is_customer'],
                $fields['is_vendor'],
                $fields['payment_due_default'],
                $fields['archived_at'],
                $fields['myucto_id'],
                $fields['myucto_updated_at'],
                $fields['mu_synced_at'],
            ]);
            return;
        }

        $sql = 'UPDATE clients SET
            company_name = ?, first_name = ?, last_name = ?, ic = ?, dic = ?,
            street = ?, city = ?, zip = ?, country_id = ?, main_email = ?, phone = ?,
            is_customer = ?, is_vendor = ?, payment_due_default = ?, archived_at = ?,
            myucto_updated_at = ?, mu_synced_at = ?
            WHERE myucto_id = ?';
        $this->pdo->prepare($sql)->execute([
            $fields['company_name'],
            $fields['first_name'],
            $fields['last_name'],
            $fields['ic'],
            $fields['dic'],
            $fields['street'],
            $fields['city'],
            $fields['zip'],
            $fields['country_id'],
            $fields['main_email'],
            $fields['phone'],
            $fields['is_customer'],
            $fields['is_vendor'],
            $fields['payment_due_default'],
            $fields['archived_at'],
            $fields['myucto_updated_at'],
            $fields['mu_synced_at'],
            $myuctoId,
        ]);
    }

    /** @param list<int> $seenIds */
    private function archiveMissing(array $seenIds): void
    {
        $local = $this->pdo->query('SELECT myucto_id FROM clients WHERE myucto_id IS NOT NULL AND archived_at IS NULL')
            ->fetchAll(PDO::FETCH_COLUMN);
        $seen = array_fill_keys($seenIds, true);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE clients SET archived_at = ?, mu_synced_at = ? WHERE myucto_id = ? AND archived_at IS NULL');
        foreach ($local as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($seen[$id])) {
                $stmt->execute([$now, $now, $id]);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function findByMyuctoId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, myucto_id, myucto_updated_at FROM clients WHERE myucto_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function touchSynced(int $myuctoId): void
    {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE clients SET mu_synced_at = ? WHERE myucto_id = ?')->execute([$now, $myuctoId]);
    }

    private function countryIdFromIso2(string $iso2): int
    {
        $iso2 = strtoupper(substr($iso2, 0, 2));
        if ($iso2 === '') {
            $iso2 = 'CZ';
        }
        $stmt = $this->pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmt->execute([$iso2]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $fallback = $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();

        return $fallback !== false ? (int) $fallback : 1;
    }

    private function currencyId(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM currencies WHERE code = 'CZK' AND supplier_id = ? LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $any = $this->pdo->query('SELECT id FROM currencies ORDER BY id ASC LIMIT 1')->fetchColumn();

        return $any !== false ? (int) $any : 1;
    }

    /** @param array<string, mixed> $remote */
    public static function remoteUpdatedAt(array $remote): ?string
    {
        $raw = $remote['updated_at'] ?? null;
        return is_string($raw) && $raw !== '' ? self::normalizeDt($raw) : null;
    }

    public static function normalizeDt(string $value): string
    {
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $value, $m) === 1) {
            return $m[1] . ' 00:00:00';
        }

        return $value;
    }

    private static function nullableStr(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
