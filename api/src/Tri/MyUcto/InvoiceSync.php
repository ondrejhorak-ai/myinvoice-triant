<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

final class InvoiceSync
{
    public const HOT_DAYS = 120;

    public function __construct(
        private readonly MyUctoClient $api,
        private readonly PDO $pdo,
        private readonly MirrorWriter $writer,
    ) {}

    public function run(bool $full = false, ?SyncState $state = null): int
    {
        $n = 0;
        $hotFrom = (new \DateTimeImmutable('today'))->modify('-' . self::HOT_DAYS . ' days')->format('Y-m-d');
        $hotIds = [];

        $n += $this->pull(['filter' => ['date_from' => $hotFrom]], $hotIds);
        $n += $this->pull(['filter' => ['status' => 'draft']], $hotIds);

        if ($full || $this->shouldRunCold($state)) {
            $year = (int) date('Y');
            $n += $this->pull(['filter' => ['year' => $year]], $hotIds);
            $n += $this->pull(['filter' => ['year' => $year - 1]], $hotIds);
            $state?->markOk('invoices_cold');
        }

        $this->tombstoneMissing($hotFrom, array_keys($hotIds));

        return $n;
    }

    /**
     * On-demand sync for a single project (JobDetail).
     *
     * @return int
     */
    public function pullForProject(int $projectId): int
    {
        $ids = [];

        return $this->pull(['filter' => ['project_id' => $projectId]], $ids);
    }

    /**
     * MyÚčto list faktur vrací měsíční skupiny `{month, count, invoices: [...]}`;
     * rozbal je na jednotlivé faktury. Ploché řádky (s `id`) projdou beze změny.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function flattenGroups(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!isset($item['id']) && isset($item['invoices']) && is_array($item['invoices'])) {
                foreach ($item['invoices'] as $inv) {
                    if (is_array($inv)) {
                        $out[] = $inv;
                    }
                }
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @param array<int, true> $seenIds */
    private function pull(array $query, array &$seenIds): int
    {
        $n = 0;
        $page = 1;
        do {
            $q = array_merge(['per_page' => 200, 'page' => $page], $query);
            $payload = $this->api->listInvoices($q);
            $items = self::flattenGroups(ApiPage::items($payload));
            foreach ($items as $remote) {
                $id = (int) ($remote['id'] ?? 0);
                if ($id > 0) {
                    $seenIds[$id] = true;
                }
                $this->upsert($remote);
                $n++;
            }
            $last = ApiPage::lastPage($payload);
            $page++;
        } while ($page <= $last && $items !== []);

        return $n;
    }

    /** @param array<string, mixed> $remote */
    public function upsert(array $remote): void
    {
        $row = self::toRow($remote, $this->writer->now());
        if ($row === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT updated_at, status, payment_status, paid_at, paid_total FROM mu_invoices WHERE id = ?'
        );
        $stmt->execute([$row['id']]);
        $local = $stmt->fetch(PDO::FETCH_ASSOC);
        $localUpdatedStr = is_array($local) && $local['updated_at'] !== null ? (string) $local['updated_at'] : null;

        // List endpoint často vrací issued/unpaid i u zaplacené proformy.
        // Bez detailu by sync smazal paid_at a konečná faktura neodečetla zálohu.
        if (is_array($local) && self::isPaidMirrorRow($local) && !self::isPaidMirrorRow($row)) {
            try {
                $detail = ContactGateway::unwrap($this->api->getInvoice((int) $row['id']));
                if (is_array($detail) && (int) ($detail['id'] ?? 0) === (int) $row['id']) {
                    $row = self::toRow($detail, $this->writer->now()) ?? $row;
                }
            } catch (MyUctoApiException) {
                $row['status'] = (string) ($local['status'] ?? $row['status']);
                $row['payment_status'] = $local['payment_status'] ?? $row['payment_status'];
                $row['paid_at'] = $local['paid_at'] ?? $row['paid_at'];
                $row['paid_total'] = $local['paid_total'] ?? $row['paid_total'];
            }
        }

        if (!ApiPage::shouldWrite($localUpdatedStr, is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null)) {
            $this->pdo->prepare('UPDATE mu_invoices SET mu_synced_at = ?, deleted_at = NULL WHERE id = ?')
                ->execute([$row['mu_synced_at'], $row['id']]);
            return;
        }

        $this->writer->upsert('mu_invoices', $row, array_values(array_diff(array_keys($row), ['id'])));
    }

    /** @param array<string, mixed> $row */
    public static function isPaidMirrorRow(array $row): bool
    {
        $status = strtolower((string) ($row['status'] ?? ''));
        $payment = strtolower((string) ($row['payment_status'] ?? ''));
        if ($status === 'paid' || in_array($payment, ['paid', 'overpaid'], true)) {
            return true;
        }
        $paidAt = $row['paid_at'] ?? null;
        $paidTotal = (float) ($row['paid_total'] ?? 0);

        return $paidAt !== null && $paidAt !== '' && $paidTotal > 0;
    }

    /**
     * @param array<string, mixed> $inv
     * @return array<string, mixed>|null
     */
    public static function toRow(array $inv, string $syncedAt): ?array
    {
        $id = (int) ($inv['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $totals = is_array($inv['totals'] ?? null) ? $inv['totals'] : [];
        $client = is_array($inv['client'] ?? null) ? $inv['client'] : [];
        $items = $inv['items'] ?? null;

        return [
            'id' => $id,
            'client_myucto_id' => self::intOrNull($inv['client_id'] ?? ($client['id'] ?? null)),
            'project_id' => self::intOrNull($inv['project_id'] ?? null),
            'varsymbol' => self::strOrNull($inv['varsymbol'] ?? null),
            'invoice_type' => (string) ($inv['invoice_type'] ?? 'invoice'),
            'status' => (string) ($inv['status'] ?? 'draft'),
            'payment_status' => self::strOrNull($inv['payment_status'] ?? null),
            'issue_date' => self::dateOrNull($inv['issue_date'] ?? null),
            'due_date' => self::dateOrNull($inv['due_date'] ?? null),
            'tax_date' => self::dateOrNull($inv['tax_date'] ?? null),
            'currency' => strtoupper(substr((string) ($inv['currency'] ?? 'CZK'), 0, 3)) ?: 'CZK',
            'total_without_vat' => self::money($totals['without_vat'] ?? ($inv['total_without_vat'] ?? 0)),
            'total_vat' => self::money($totals['vat'] ?? ($inv['total_vat'] ?? 0)),
            'total_with_vat' => self::money($totals['with_vat'] ?? ($inv['total_with_vat'] ?? 0)),
            'amount_to_pay' => self::money($inv['amount_to_pay'] ?? ($totals['with_vat'] ?? 0)),
            'paid_total' => self::money($inv['paid_total'] ?? 0),
            'paid_at' => self::dtOrNull($inv['paid_at'] ?? null),
            'supplier_order_number' => self::strOrNull($inv['supplier_order_number'] ?? null),
            'sent_at' => self::dtOrNull($inv['sent_at'] ?? null),
            'reminder_count' => (int) ($inv['reminder_count'] ?? 0),
            'last_reminder_at' => self::dtOrNull($inv['last_reminder_at'] ?? null),
            'public_token' => self::strOrNull($inv['public_token'] ?? null),
            'client_main_email' => self::strOrNull($inv['client_main_email'] ?? ($client['main_email'] ?? null)),
            'branding_profile_id' => self::intOrNull($inv['branding_profile_id'] ?? null),
            'items_json' => is_array($items) ? json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'raw_json' => json_encode($inv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => self::dtOrNull($inv['updated_at'] ?? null),
            'mu_synced_at' => $syncedAt,
            'deleted_at' => null,
        ];
    }

    /** @param list<int> $seenIds */
    private function tombstoneMissing(string $hotFrom, array $seenIds): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM mu_invoices
              WHERE deleted_at IS NULL
                AND (status = 'draft' OR (issue_date IS NOT NULL AND issue_date >= ?))"
        );
        $stmt->execute([$hotFrom]);
        $local = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $seen = array_fill_keys($seenIds, true);
        $now = $this->writer->now();
        $upd = $this->pdo->prepare('UPDATE mu_invoices SET deleted_at = ?, mu_synced_at = ? WHERE id = ?');
        foreach ($local as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            // Bezpečnostní síť: fakturu, kterou list pull neviděl, tombstonuj
            // až po ověření GET detailu (jen 404/410 = opravdu smazaná v MyÚčtu).
            try {
                $detail = $this->api->getInvoice($id);
                $inv = ContactGateway::unwrap(is_array($detail) ? $detail : []);
                if (is_array($inv) && (int) ($inv['id'] ?? 0) === $id) {
                    $this->upsert($inv);
                    continue;
                }
            } catch (MyUctoApiException $e) {
                if ($e->httpStatus !== 404 && $e->httpStatus !== 410) {
                    continue; // síť/5xx → nemazat, zkusí se příště
                }
            }
            $upd->execute([$now, $now, $id]);
        }
    }

    private function shouldRunCold(?SyncState $state): bool
    {
        if ($state === null) {
            $stmt = $this->pdo->prepare("SELECT last_ok_at FROM mu_sync_state WHERE `key` = 'invoices_cold'");
            $stmt->execute();
            $last = $stmt->fetchColumn();
            $lastStr = $last !== false && $last !== null && $last !== '' ? (string) $last : null;
        } else {
            $lastStr = $state->lastOkAt('invoices_cold');
        }
        if ($lastStr === null) {
            return true;
        }
        $ts = strtotime($lastStr);

        return $ts === false || $ts < time() - 20 * 3600;
    }

    private static function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $i = (int) $v;

        return $i > 0 ? $i : null;
    }

    private static function strOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private static function dateOrNull(mixed $v): ?string
    {
        $s = self::strOrNull($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m) === 1) {
            return $m[1];
        }

        return $s;
    }

    private static function dtOrNull(mixed $v): ?string
    {
        $s = self::strOrNull($v);
        if ($s === null) {
            return null;
        }

        return ClientSync::normalizeDt($s);
    }
}
