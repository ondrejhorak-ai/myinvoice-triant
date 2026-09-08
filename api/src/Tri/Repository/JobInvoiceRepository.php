<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class JobInvoiceRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string, mixed>> */
    public function listForJob(int $jobId, int $supplierId): array
    {
        $job = $this->jobRow($jobId, $supplierId);
        if ($job === null) {
            return [];
        }
        $projectId = (int) ($job['myucto_project_id'] ?? 0);
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date, i.due_date,
                       i.total_without_vat, i.total_vat, i.total_with_vat,
                       i.amount_to_pay, i.paid_at, i.client_myucto_id,
                       COALESCE(c.company_name, i.client_main_email, '') AS client_name,
                       COALESCE(i.mu_synced_at, i.updated_at) AS linked_at,
                       0 AS advance_paid_amount
                  FROM mu_invoices i
             LEFT JOIN clients c ON c.myucto_id = i.client_myucto_id
                  WHERE i.deleted_at IS NULL AND (";
        $params = [];
        $parts = [];
        if ($projectId > 0) {
            $parts[] = 'i.project_id = ?';
            $params[] = $projectId;
        }
        $parts[] = 'i.id IN (SELECT invoice_id FROM tri_job_invoice_overrides WHERE job_id = ?)';
        $params[] = $jobId;
        $sql .= implode(' OR ', $parts) . ') ORDER BY i.issue_date DESC, i.id DESC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $r) => $this->castRow($r), $rows);
    }

    /** @return array<string, mixed> */
    public function summaryForJob(int $jobId, int $supplierId): array
    {
        $jobStmt = $this->db->pdo()->prepare(
            'SELECT j.approved_variant_id, j.myucto_project_id,
                    v.total_with_vat AS variant_total_with_vat,
                    v.subtotal AS variant_subtotal
               FROM tri_jobs j
          LEFT JOIN tri_quote_variants v ON v.id = j.approved_variant_id
              WHERE j.id = ? AND j.supplier_id = ?'
        );
        $jobStmt->execute([$jobId, $supplierId]);
        $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
        if ($job === false) {
            return [
                'variant_total_with_vat' => 0.0,
                'variant_subtotal' => 0.0,
                'invoiced_total' => 0.0,
                'paid_advances_total' => 0.0,
                'remaining_to_invoice' => 0.0,
            ];
        }

        $variantTotal = $job['variant_total_with_vat'] !== null ? (float) $job['variant_total_with_vat'] : 0.0;
        $variantSubtotal = $job['variant_subtotal'] !== null ? (float) $job['variant_subtotal'] : 0.0;
        $projectId = (int) ($job['myucto_project_id'] ?? 0);

        $invoicedTotal = 0.0;
        if ($projectId > 0) {
            $invStmt = $this->db->pdo()->prepare(
                "SELECT COALESCE(SUM(total_with_vat), 0)
                   FROM mu_invoices
                  WHERE project_id = ?
                    AND deleted_at IS NULL
                    AND invoice_type = 'invoice'
                    AND status NOT IN ('cancelled', 'draft')"
            );
            $invStmt->execute([$projectId]);
            $invoicedTotal = (float) $invStmt->fetchColumn();
        }

        $paidAdvances = $this->paidAdvancesTotal($jobId, $supplierId);

        return [
            'variant_total_with_vat' => round($variantTotal, 2),
            'variant_subtotal' => round($variantSubtotal, 2),
            'invoiced_total' => round($invoicedTotal, 2),
            'paid_advances_total' => round($paidAdvances, 2),
            'remaining_to_invoice' => round(max(0, $variantTotal - $invoicedTotal - $paidAdvances), 2),
        ];
    }

    public function paidAdvancesTotal(int $jobId, int $supplierId): float
    {
        $job = $this->jobRow($jobId, $supplierId);
        $projectId = (int) ($job['myucto_project_id'] ?? 0);
        if ($projectId <= 0) {
            return 0.0;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(total_with_vat), 0)
               FROM mu_invoices
              WHERE project_id = ?
                AND deleted_at IS NULL
                AND invoice_type = 'proforma'
                AND (
                    status = 'paid'
                    OR payment_status IN ('paid', 'overpaid')
                    OR (paid_at IS NOT NULL AND paid_total > 0)
                )"
        );
        $stmt->execute([$projectId]);

        return (float) $stmt->fetchColumn();
    }

    public function link(int $invoiceId, int $jobId, int $supplierId): void
    {
        $this->assertJobOwned($jobId, $supplierId);
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM tri_job_invoice_overrides WHERE invoice_id = ?')->execute([$invoiceId]);
        $pdo->prepare(
            'INSERT INTO tri_job_invoice_overrides (invoice_id, job_id) VALUES (?, ?)'
        )->execute([$invoiceId, $jobId]);
    }

    public function unlink(int $invoiceId, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM tri_job_invoice_overrides
              WHERE invoice_id = ?
                AND job_id IN (SELECT id FROM tri_jobs WHERE supplier_id = ?)'
        )->execute([$invoiceId, $supplierId]);
    }

    /** Zůstává kvůli core PaymentTaxDocumentCreator — po H4 no-op. */
    public function inheritJobLink(int $targetInvoiceId, int $sourceInvoiceId, int $supplierId): void
    {
        $job = $this->jobForInvoiceScoped($sourceInvoiceId, $supplierId);
        if ($job === null) {
            return;
        }
        $this->link($targetInvoiceId, (int) $job['id'], $supplierId);
    }

    /** @return array{id:int, number:string, title:string}|null */
    public function jobForInvoice(int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT j.id, j.number, j.title
               FROM tri_job_invoice_overrides o
               JOIN tri_jobs j ON j.id = o.job_id
              WHERE o.invoice_id = ?
              LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $this->castJob($row);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT j.id, j.number, j.title
               FROM mu_invoices i
               JOIN tri_jobs j ON j.myucto_project_id = i.project_id
              WHERE i.id = ?
              LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castJob($row) : null;
    }

    public function jobForInvoiceScoped(int $invoiceId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT j.id, j.number, j.title
               FROM tri_job_invoice_overrides o
               JOIN tri_jobs j ON j.id = o.job_id
              WHERE o.invoice_id = ? AND j.supplier_id = ?
              LIMIT 1'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $this->castJob($row);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT j.id, j.number, j.title
               FROM mu_invoices i
               JOIN tri_jobs j ON j.myucto_project_id = i.project_id AND j.supplier_id = ?
              WHERE i.id = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castJob($row) : null;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listGroupedByMonth(array $filters, int $page, int $perPage): array
    {
        $where = ['i.deleted_at IS NULL'];
        $params = [];
        $join = ' LEFT JOIN clients c ON c.myucto_id = i.client_myucto_id
                  LEFT JOIN tri_jobs tj ON tj.myucto_project_id = i.project_id
                  LEFT JOIN tri_job_invoice_overrides o ON o.invoice_id = i.id
                  LEFT JOIN tri_jobs tj2 ON tj2.id = o.job_id';

        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
            $place = implode(',', array_fill(0, count($types), '?'));
            $where[] = "i.invoice_type IN ($place)";
            foreach ($types as $t) {
                $params[] = $t;
            }
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "i.status IN ($place)";
            foreach ($statuses as $s) {
                $params[] = $s;
            }
        }
        if (!empty($filters['client_id'])) {
            $where[] = '(c.id = ? OR i.client_myucto_id = ?)';
            $params[] = (int) $filters['client_id'];
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['tri_job_id'])) {
            $where[] = '(tj.id = ? OR tj2.id = ?)';
            $params[] = (int) $filters['tri_job_id'];
            $params[] = (int) $filters['tri_job_id'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'substr(COALESCE(i.tax_date, i.issue_date), 1, 4) = ?';
            $params[] = sprintf('%04d', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $where[] = 'substr(COALESCE(i.tax_date, i.issue_date), 6, 2) = ?';
            $params[] = sprintf('%02d', (int) $filters['month']);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'COALESCE(i.tax_date, i.issue_date) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'COALESCE(i.tax_date, i.issue_date) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'i.currency = ?';
            $params[] = strtoupper((string) $filters['currency']);
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "i.status IN ('issued','sent','reminded')";
            $where[] = '(i.amount_to_pay - i.paid_total) > 0';
        }
        if (!empty($filters['overdue'])) {
            $where[] = "i.status IN ('issued','sent','reminded') AND i.due_date <= ?";
            $params[] = date('Y-m-d');
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(i.varsymbol LIKE ? OR c.company_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(DISTINCT i.id) FROM mu_invoices i $join WHERE $whereSql";
        $cnt = $this->db->pdo()->prepare($countSql);
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_myucto_id, i.project_id,
                       i.issue_date, i.tax_date, i.due_date, i.currency,
                       i.total_without_vat, i.total_vat, i.total_with_vat,
                       i.amount_to_pay, i.paid_total, i.status, i.payment_status,
                       i.sent_at, i.last_reminder_at, i.reminder_count, i.paid_at,
                       COALESCE(c.company_name, i.client_main_email, '') AS client_company_name,
                       COALESCE(c.id, i.client_myucto_id) AS client_id,
                       COALESCE(tj.id, tj2.id) AS tri_job_id,
                       COALESCE(tj.number, tj2.number) AS tri_job_number,
                       COALESCE(tj.title, tj2.title) AS tri_job_title,
                       substr(COALESCE(i.tax_date, i.issue_date), 1, 7) AS month_bucket
                  FROM mu_invoices i
                  $join
                 WHERE $whereSql
                 ORDER BY COALESCE(i.tax_date, i.issue_date) DESC, i.id DESC";

        $offset = max(0, ($page - 1) * $perPage);
        $sql .= ' LIMIT ? OFFSET ?';
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $groups = [];
        foreach ($rows as $row) {
            $month = (string) ($row['month_bucket'] ?? '');
            if ($month === '') {
                $month = '0000-00';
            }
            if (!isset($groups[$month])) {
                $groups[$month] = [
                    'month' => $month,
                    'count' => 0,
                    'totals_per_currency' => [],
                    'invoices' => [],
                ];
            }
            $item = $this->castListItem($row);
            $groups[$month]['invoices'][] = $item;
            $groups[$month]['count']++;
            $cur = $item['currency'];
            $found = false;
            foreach ($groups[$month]['totals_per_currency'] as &$t) {
                if ($t['currency'] === $cur) {
                    $t['without_vat'] += $item['total_without_vat'];
                    $t['vat'] += $item['total_vat'];
                    $t['with_vat'] += $item['total_with_vat'];
                    if ($item['status'] === 'draft') {
                        $t['draft_without_vat'] += $item['total_without_vat'];
                        $t['draft_vat'] += $item['total_vat'];
                        $t['draft_with_vat'] += $item['total_with_vat'];
                    }
                    $found = true;
                    break;
                }
            }
            unset($t);
            if (!$found) {
                $isDraft = $item['status'] === 'draft';
                $groups[$month]['totals_per_currency'][] = [
                    'currency' => $cur,
                    'without_vat' => $item['total_without_vat'],
                    'vat' => $item['total_vat'],
                    'with_vat' => $item['total_with_vat'],
                    'draft_without_vat' => $isDraft ? $item['total_without_vat'] : 0.0,
                    'draft_vat' => $isDraft ? $item['total_vat'] : 0.0,
                    'draft_with_vat' => $isDraft ? $item['total_with_vat'] : 0.0,
                ];
            }
        }

        return [
            'data' => array_values($groups),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) max(1, ceil($total / max(1, $perPage))),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function jobRow(int $jobId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, myucto_project_id FROM tri_jobs WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$jobId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function assertJobOwned(int $jobId, int $supplierId): void
    {
        if ($this->jobRow($jobId, $supplierId) === null) {
            throw new \RuntimeException('Zakázka nenalezena.');
        }
    }

    /** @param array<string, mixed> $row */
    private function castJob(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'title' => (string) $row['title'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function castRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'varsymbol' => $row['varsymbol'] !== null ? (string) $row['varsymbol'] : null,
            'invoice_type' => (string) $row['invoice_type'],
            'status' => (string) $row['status'],
            'issue_date' => (string) ($row['issue_date'] ?? ''),
            'due_date' => (string) ($row['due_date'] ?? ''),
            'total_without_vat' => (float) $row['total_without_vat'],
            'total_vat' => (float) $row['total_vat'],
            'total_with_vat' => (float) $row['total_with_vat'],
            'advance_paid_amount' => (float) ($row['advance_paid_amount'] ?? 0),
            'amount_to_pay' => (float) $row['amount_to_pay'],
            'paid_at' => $row['paid_at'],
            'client_id' => (int) ($row['client_myucto_id'] ?? 0),
            'client_name' => (string) $row['client_name'],
            'linked_at' => (string) ($row['linked_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function castListItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'varsymbol' => $row['varsymbol'] !== null ? (string) $row['varsymbol'] : null,
            'invoice_type' => (string) $row['invoice_type'],
            'parent_invoice_id' => null,
            'client_id' => (int) ($row['client_id'] ?? 0),
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'issue_date' => (string) ($row['issue_date'] ?? ''),
            'tax_date' => $row['tax_date'],
            'due_date' => (string) ($row['due_date'] ?? ''),
            'currency' => (string) ($row['currency'] ?? 'CZK'),
            'total_without_vat' => (float) $row['total_without_vat'],
            'total_vat' => (float) $row['total_vat'],
            'total_with_vat' => (float) $row['total_with_vat'],
            'advance_paid_amount' => 0.0,
            'amount_to_pay' => (float) $row['amount_to_pay'],
            'paid_total' => (float) ($row['paid_total'] ?? 0),
            'payment_status' => $row['payment_status'] ?? null,
            'status' => (string) $row['status'],
            'payment_method' => 'bank_transfer',
            'sent_at' => $row['sent_at'],
            'last_reminder_at' => $row['last_reminder_at'],
            'reminder_count' => (int) ($row['reminder_count'] ?? 0),
            'paid_at' => $row['paid_at'],
            'cancelled_at' => null,
            'client_company_name' => (string) ($row['client_company_name'] ?? ''),
            'project_name' => null,
            'month_bucket' => (string) ($row['month_bucket'] ?? ''),
            'tri_job_id' => $row['tri_job_id'] !== null ? (int) $row['tri_job_id'] : null,
            'tri_job_number' => $row['tri_job_number'] !== null ? (string) $row['tri_job_number'] : null,
            'tri_job_title' => $row['tri_job_title'] !== null ? (string) $row['tri_job_title'] : null,
        ];
    }
}
