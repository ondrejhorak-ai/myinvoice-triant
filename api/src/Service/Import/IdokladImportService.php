<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use Psr\Log\LoggerInterface;

/**
 * iDoklad import orchestrátor.
 *
 * Volaný background workerem (api/bin/import-worker.php). Stahuje:
 *   1. Contacts          → clients (dedup přes idoklad_id)
 *   2. IssuedInvoices    → invoices (dedup přes idoklad_id) — vč. dobropisů (InvoiceType=3)
 *   3. ReceivedInvoices  → purchase_invoices (dedup přes idoklad_id)
 *
 * Pro každý záznam:
 *   - Check existence (supplier_id, idoklad_id) → skip pokud existuje
 *   - Insert nový + nastavit idoklad_id
 *   - Update progress každých 10 items + appendLog
 *
 * Cancellation: každých 10 items check cancel_requested → graceful exit.
 *
 * Date parsing fallback: ReceivedInvoices.DateOfIssue je často NULL, pak
 * DateOfAccountingEvent (per fork bug fix `Fix ReceivedInvoices date parsing`).
 */
final class IdokladImportService
{
    private const PROGRESS_FLUSH_EVERY = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly IdokladClient $idoklad,
        private readonly ImportJobRepository $jobs,
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly InvoiceCalculator $invCalc,
        private readonly PurchaseInvoiceCalculator $purCalc,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Spustí job. Volá worker, ne přímo UI (UI vytvoří job a vrátí, worker pak picknul).
     *
     * @param array<string,mixed> $params  z import_jobs.params:
     *   - include_clients: bool (default true)
     *   - include_issued: bool (default true)
     *   - include_received: bool (default true)
     *   - dry_run: bool (default false)
     */
    public function run(int $jobId): void
    {
        // Reload job uvnitř transakce — race-safe markRunning
        $job = $this->loadJob($jobId);
        if (!$this->jobs->markRunning($jobId)) {
            // Někdo jiný už picknul nebo byl cancelled
            return;
        }
        try {
            $params = $job['params'] ?? [];
            $supplierId = (int) $job['supplier_id'];
            $userId = (int) $job['created_by'];
            $dryRun = !empty($params['dry_run']);

            $this->jobs->appendLog($jobId, 'Import zahájen' . ($dryRun ? ' (dry-run)' : '') . '.');

            if (!empty($params['include_clients']) || ($params['include_clients'] ?? null) === null) {
                $this->importClients($jobId, $supplierId, $userId, $dryRun);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_issued']) || ($params['include_issued'] ?? null) === null) {
                $this->importIssued($jobId, $supplierId, $userId, $dryRun);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_received']) || ($params['include_received'] ?? null) === null) {
                $this->importReceived($jobId, $supplierId, $userId, $dryRun);
            }

            // Mark completed + bookmark
            $this->jobs->appendLog($jobId, 'Import dokončen.');
            $this->jobs->markCompleted($jobId);
            $this->db->pdo()->prepare(
                'UPDATE supplier SET idoklad_last_imported_at = NOW() WHERE id = ?'
            )->execute([$supplierId]);
        } catch (CancelledException $e) {
            $this->jobs->appendLog($jobId, 'Import zrušen uživatelem.');
            $this->jobs->markCancelled($jobId);
        } catch (\Throwable $e) {
            $this->logger->error('iDoklad import failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            $this->jobs->appendLog($jobId, 'FAIL: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    private function loadJob(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Import job #{$jobId} nenalezen.");
        }
        if (!empty($row['params'])) {
            $row['params'] = json_decode((string) $row['params'], true);
        }
        return $row;
    }

    private function checkCancel(int $jobId): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            throw new CancelledException();
        }
    }

    /**
     * Import Contacts → clients. Dedup přes (supplier_id, idoklad_id).
     */
    private function importClients(int $jobId, int $supplierId, int $userId, bool $dryRun): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing contacts…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji kontakty z iDoklad…');

        $created = 0; $skipped = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'Contacts') as $contact) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($contact['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $created++;
                continue;
            }

            // Create — map iDoklad Contact → clients schema
            try {
                $clientId = $this->createClientFromIdoklad($contact, $supplierId);
                $this->db->pdo()->prepare(
                    'UPDATE clients SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $clientId]);
                $created++;
            } catch (\Throwable $e) {
                $this->jobs->appendLog($jobId, "Kontakt {$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
        $this->jobs->appendLog($jobId, "Kontakty: vytvořeno {$created}, přeskočeno {$skipped} (z {$processed}).");
    }

    /**
     * Map iDoklad Contact → clients row + create.
     */
    private function createClientFromIdoklad(array $c, int $supplierId): int
    {
        $countryIso2 = strtoupper((string) ($c['Country']['Code'] ?? 'CZ'));
        $data = [
            'company_name' => (string) ($c['CompanyName'] ?: ($c['FirstName'] . ' ' . $c['Surname'] ?: 'iDoklad import')),
            'ic'           => (string) ($c['IdentificationNumber'] ?? '') ?: null,
            'dic'          => (string) ($c['VatIdentificationNumber'] ?? '') ?: null,
            'street'       => (string) ($c['Street'] ?? '—'),
            'city'         => (string) ($c['City'] ?? '—'),
            'zip'          => (string) ($c['PostalCode'] ?? '00000'),
            'country_iso2' => $countryIso2,
            'main_email'   => (string) ($c['Email'] ?? '') ?: 'unknown@import.local',
            'phone'        => (string) ($c['Phone'] ?? '') ?: null,
            'language'     => 'cs',
            'is_customer'  => true,
            'is_vendor'    => false,
        ];
        return $this->clients->create($data, $supplierId);
    }

    /**
     * Import IssuedInvoices → invoices. MVP mapping: header + items, status='draft'.
     * Dobropisy (IssuedInvoiceCorrections) jsou separátní endpoint a dělají se ve fázi 3.
     *
     * Note: faktury z iDoklad nemají project_id (oni nemají koncept projektů jako my)
     * — project_id = NULL. Uživatel může později ručně přiřadit.
     */
    private function importIssued(int $jobId, int $supplierId, int $userId, bool $dryRun): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing issued invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji vydané faktury z iDoklad…');

        $created = 0; $skipped = 0; $failed = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'IssuedInvoices') as $idoklad) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($idoklad['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $invoiceId = $this->createIssuedFromIdoklad($idoklad, $supplierId, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $invoiceId]);
                $this->invCalc->recompute($invoiceId);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Vydaná #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Vydané faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    /**
     * Vytvoří jednu vydanou fakturu z iDoklad payloadu.
     */
    private function createIssuedFromIdoklad(array $i, int $supplierId, int $userId): int
    {
        // Resolve client by PartnerId → idoklad_id v clients
        $partnerId = (int) ($i['PartnerId'] ?? 0);
        $clientId = $this->resolveClientByIdoklad($partnerId, $supplierId);
        if ($clientId === null) {
            throw new \RuntimeException("Klient s iDoklad ID {$partnerId} nenalezen — nejdřív naimportuj kontakty.");
        }

        $invoiceType = $this->mapIssuedDocumentType((int) ($i['DocumentType'] ?? 0));

        $payload = [
            'invoice_type'    => $invoiceType,
            'client_id'       => $clientId,
            'issue_date'      => (string) ($i['DateOfIssue'] ?? date('Y-m-d')),
            'tax_date'        => $invoiceType === 'proforma' ? null : (string) ($i['DateOfTaxing'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
            'due_date'        => (string) ($i['DateOfMaturity'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
            'currency_id'     => $this->resolveCurrencyId((string) ($i['CurrencyCode'] ?? 'CZK'), $supplierId, isActive: true),
            'reverse_charge'  => false,
            'language'        => 'cs',
            'varsymbol'       => $this->sanitizeVarsymbol((string) ($i['VariableSymbol'] ?? $i['DocumentNumber'] ?? '')),
            'payment_method'  => 'bank_transfer',
        ];

        $invoiceId = $this->invoices->createDraft($payload, $userId);

        // Items
        $vatRates = $this->loadVatRateMap();
        $items = [];
        foreach (($i['Items'] ?? []) as $idx => $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            $vatRateId = $this->matchVatRateId($vatRates, $rate);
            $items[] = [
                'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                'quantity'               => (float) ($line['Amount'] ?? 1),
                'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['UnitPrice'] ?? 0),
                'vat_rate_id'            => $vatRateId,
                'order_index'            => $idx,
            ];
        }
        if (!empty($items)) {
            $this->invoices->replaceItems($invoiceId, $items);
        }
        return $invoiceId;
    }

    /**
     * Import ReceivedInvoices → purchase_invoices.
     *
     * Per fork bug fix: DateOfIssue často NULL pro přijaté, fallback DateOfAccountingEvent.
     */
    private function importReceived(int $jobId, int $supplierId, int $userId, bool $dryRun): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing received invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji přijaté faktury z iDoklad…');

        $created = 0; $skipped = 0; $failed = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'ReceivedInvoices') as $idoklad) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($idoklad['Id'] ?? 0);
            if ($idokladId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $purchaseId = $this->createReceivedFromIdoklad($idoklad, $supplierId, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE purchase_invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $purchaseId]);
                $this->purCalc->recompute($purchaseId);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Přijatá #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Přijaté faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    /**
     * Vytvoří jednu přijatou fakturu z iDoklad payloadu.
     */
    private function createReceivedFromIdoklad(array $i, int $supplierId, int $userId): int
    {
        // Resolve vendor by PartnerId → idoklad_id v clients (with is_vendor flag)
        $partnerId = (int) ($i['PartnerId'] ?? 0);
        $vendorId = $this->resolveClientByIdoklad($partnerId, $supplierId);
        if ($vendorId === null) {
            throw new \RuntimeException("Dodavatel s iDoklad ID {$partnerId} nenalezen — nejdřív naimportuj kontakty.");
        }
        // Promote na vendor (might be already-imported customer)
        $this->clients->markAsVendor($vendorId);

        // Date fallback: DateOfIssue → DateOfAccountingEvent → today (per fork bug fix)
        $issueDate = (string) ($i['DateOfIssue'] ?? $i['DateOfAccountingEvent'] ?? '') ?: date('Y-m-d');
        $taxDate   = (string) ($i['DateOfTaxing'] ?? $issueDate);
        $dueDate   = (string) ($i['DateOfMaturity'] ?? $issueDate);

        $vatRates = $this->loadVatRateMap();
        $defaultVatRateId = $this->matchVatRateId($vatRates, 21.0) ?? $this->matchVatRateId($vatRates, 0.0) ?? 0;

        $items = [];
        foreach (($i['Items'] ?? []) as $idx => $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            $items[] = [
                'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                'quantity'               => (float) ($line['Amount'] ?? 1),
                'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['UnitPrice'] ?? 0),
                'vat_rate_id'            => $this->matchVatRateId($vatRates, $rate) ?? $defaultVatRateId,
                'order_index'            => $idx,
            ];
        }

        $payload = [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $this->sanitizeVendorNumber((string) ($i['DocumentNumber'] ?? '')),
            'document_kind'         => 'invoice',
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate,
            'due_date'              => $dueDate,
            'received_at'           => date('Y-m-d'),
            'currency_id'           => $this->resolveCurrencyId((string) ($i['CurrencyCode'] ?? 'CZK'), $supplierId, isActive: false),
            'exchange_rate'         => isset($i['ExchangeRate']) ? (float) $i['ExchangeRate'] : null,
            'exchange_rate_source'  => 'manual',
            'reverse_charge'        => false,
            'language'              => 'cs',
            'items'                 => $items,
        ];

        $id = $this->purchaseRepo->createDraft($payload, $userId, $supplierId);
        if (!empty($items)) {
            $this->purchaseRepo->replaceItems($id, $items);
        }
        return $id;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function resolveClientByIdoklad(int $partnerId, int $supplierId): ?int
    {
        if ($partnerId === 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $partnerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Lookup or auto-create currency. Pro vydané faktury (issued) je třeba is_active=1
     * (musíme mít bankovní účet); pro přijaté stačí is_active=0 (jen pro nákupní cyklus).
     */
    private function resolveCurrencyId(string $code, int $supplierId, bool $isActive): int
    {
        $code = strtoupper(trim($code)) ?: 'CZK';
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, ?, 0)'
        )->execute([$supplierId, $code, $code, $code, $code, $code, $isActive ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<int, float>  id → rate_percent
     */
    private function loadVatRateMap(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates WHERE is_active = 1')->fetchAll(\PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (float) $r['rate_percent'];
        }
        return $map;
    }

    private function matchVatRateId(array $vatRates, float $rate): ?int
    {
        foreach ($vatRates as $id => $r) {
            if (abs($r - $rate) < 0.01) return $id;
        }
        return null;
    }

    /**
     * iDoklad DocumentType pro vydané faktury:
     *   0 = Regular invoice
     *   1 = Proforma invoice
     *   2 = Tax document for advance payment
     *   3 = Final invoice (po proforma)
     *   5 = Credit note (= IssuedInvoiceCorrection separátní endpoint)
     */
    private function mapIssuedDocumentType(int $docType): string
    {
        return match ($docType) {
            1       => 'proforma',
            5       => 'credit_note',
            default => 'invoice',
        };
    }

    /**
     * Sanitize varsymbol pro DB column (varchar 20, [A-Za-z0-9_-]).
     */
    private function sanitizeVarsymbol(string $vs): string
    {
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '', $vs) ?? '';
        if ($vs === '') return 'IDOKLAD-' . substr((string) random_int(1000, 9999), 0, 4);
        return substr($vs, 0, 20);
    }

    private function sanitizeVendorNumber(string $vn): string
    {
        $vn = trim($vn);
        if ($vn === '') $vn = 'IDOKLAD-import';
        $vn = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vn);
        return strlen($vn) > 50 ? substr($vn, 0, 50) : $vn;
    }
}

/**
 * Marker exception pro graceful cancel — worker break loop a markCancelled.
 */
final class CancelledException extends \RuntimeException {}
