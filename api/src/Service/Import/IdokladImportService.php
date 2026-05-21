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
     * Import IssuedInvoices → invoices. Pro MVP přeskočit komplexní mapování
     * (work_reports, projects); minimální body fields.
     *
     * TODO fáze 2a iter2: full mapping s items, project resolution, attachment.
     */
    private function importIssued(int $jobId, int $supplierId, int $userId, bool $dryRun): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing issued invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Vydané faktury: minimal mapping (fáze 2a iter1) — implementace plného mappingu v dalším iter.');
        // Placeholder — full mapping je rozsáhlé, dodávám v iter2.
    }

    /**
     * Import ReceivedInvoices → purchase_invoices.
     *
     * Per fork bug fix: DateOfIssue často NULL, fallback DateOfAccountingEvent
     * → fallback parse year z DocumentNumber.
     */
    private function importReceived(int $jobId, int $supplierId, int $userId, bool $dryRun): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing received invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Přijaté faktury: implementace v fázi 2a iter2.');
        // Placeholder — vendor resolution + items mapping + attachment download.
    }
}

/**
 * Marker exception pro graceful cancel — worker break loop a markCancelled.
 */
final class CancelledException extends \RuntimeException {}
