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
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date, i.due_date,
                    i.total_without_vat, i.total_vat, i.total_with_vat,
                    i.advance_paid_amount, i.amount_to_pay, i.paid_at,
                    i.client_id, c.company_name AS client_name,
                    ji.created_at AS linked_at
               FROM tri_job_invoices ji
               JOIN invoices i ON i.id = ji.invoice_id
               JOIN tri_jobs j ON j.id = ji.job_id
               JOIN clients c ON c.id = i.client_id
              WHERE ji.job_id = ? AND j.supplier_id = ?
              ORDER BY i.issue_date DESC, i.id DESC'
        );
        $stmt->execute([$jobId, $supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $r) => $this->castRow($r), $rows);
    }

    /** @return array<string, mixed> */
    public function summaryForJob(int $jobId, int $supplierId): array
    {
        $jobStmt = $this->db->pdo()->prepare(
            'SELECT j.approved_variant_id, v.total_with_vat AS variant_total_with_vat,
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
                'variant_subtotal'       => 0.0,
                'invoiced_total'         => 0.0,
                'paid_advances_total'    => 0.0,
                'remaining_to_invoice'   => 0.0,
            ];
        }

        $variantTotal = $job['variant_total_with_vat'] !== null ? (float) $job['variant_total_with_vat'] : 0.0;
        $variantSubtotal = $job['variant_subtotal'] !== null ? (float) $job['variant_subtotal'] : 0.0;

        $invStmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(i.total_with_vat), 0)
               FROM tri_job_invoices ji
               JOIN invoices i ON i.id = ji.invoice_id
              WHERE ji.job_id = ?
                AND i.invoice_type = 'invoice'
                AND i.status NOT IN ('cancelled', 'draft')"
        );
        $invStmt->execute([$jobId]);
        $invoicedTotal = (float) $invStmt->fetchColumn();

        $paidAdvances = $this->paidAdvancesTotal($jobId, $supplierId);

        return [
            'variant_total_with_vat' => round($variantTotal, 2),
            'variant_subtotal'       => round($variantSubtotal, 2),
            'invoiced_total'         => round($invoicedTotal, 2),
            'paid_advances_total'    => round($paidAdvances, 2),
            'remaining_to_invoice'   => round(max(0, $variantTotal - $invoicedTotal), 2),
        ];
    }

    public function paidAdvancesTotal(int $jobId, int $supplierId): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(i.total_with_vat), 0)
               FROM tri_job_invoices ji
               JOIN invoices i ON i.id = ji.invoice_id
               JOIN tri_jobs j ON j.id = ji.job_id
              WHERE ji.job_id = ? AND j.supplier_id = ?
                AND i.invoice_type = 'proforma'
                AND i.status = 'paid'"
        );
        $stmt->execute([$jobId, $supplierId]);

        return (float) $stmt->fetchColumn();
    }

    public function link(int $invoiceId, int $jobId, int $supplierId): void
    {
        $this->assertJobOwned($jobId, $supplierId);
        $this->assertInvoiceOwned($invoiceId, $supplierId);

        $existing = $this->jobForInvoice($invoiceId);
        if ($existing !== null && (int) $existing['id'] !== $jobId) {
            throw new \RuntimeException('Faktura je již napojená na jinou zakázku.');
        }

        $this->db->pdo()->prepare(
            'INSERT INTO tri_job_invoices (invoice_id, job_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE job_id = VALUES(job_id)'
        )->execute([$invoiceId, $jobId]);
    }

    public function unlink(int $invoiceId, int $supplierId): void
    {
        $this->assertInvoiceOwned($invoiceId, $supplierId);
        $this->db->pdo()->prepare('DELETE FROM tri_job_invoices WHERE invoice_id = ?')->execute([$invoiceId]);
    }

    /** Zkopíruje vazbu na zakázku TRI ze zdrojové faktury (typicky proforma) na cílovou. */
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
               FROM tri_job_invoices ji
               JOIN tri_jobs j ON j.id = ji.job_id
              WHERE ji.invoice_id = ?
              LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id'     => (int) $row['id'],
            'number' => (string) $row['number'],
            'title'  => (string) $row['title'],
        ];
    }

    public function jobForInvoiceScoped(int $invoiceId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT j.id, j.number, j.title
               FROM tri_job_invoices ji
               JOIN tri_jobs j ON j.id = ji.job_id
              WHERE ji.invoice_id = ? AND j.supplier_id = ?
              LIMIT 1'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id'     => (int) $row['id'],
            'number' => (string) $row['number'],
            'title'  => (string) $row['title'],
        ];
    }

    private function assertJobOwned(int $jobId, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM tri_jobs WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$jobId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Zakázka nenalezena.');
        }
    }

    private function assertInvoiceOwned(int $invoiceId, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Faktura nenalezena.');
        }
    }

    /** @param array<string, mixed> $row */
    private function castRow(array $row): array
    {
        return [
            'id'                  => (int) $row['id'],
            'varsymbol'           => $row['varsymbol'] !== null ? (string) $row['varsymbol'] : null,
            'invoice_type'        => (string) $row['invoice_type'],
            'status'              => (string) $row['status'],
            'issue_date'          => (string) $row['issue_date'],
            'due_date'            => (string) $row['due_date'],
            'total_without_vat'   => (float) $row['total_without_vat'],
            'total_vat'           => (float) $row['total_vat'],
            'total_with_vat'      => (float) $row['total_with_vat'],
            'advance_paid_amount' => (float) $row['advance_paid_amount'],
            'amount_to_pay'       => (float) $row['amount_to_pay'],
            'paid_at'             => $row['paid_at'],
            'client_id'           => (int) $row['client_id'],
            'client_name'         => (string) $row['client_name'],
            'linked_at'           => (string) $row['linked_at'],
        ];
    }
}
