<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tri;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Při vytvoření daňového dokladu ze zálohové faktury se musí zkopírovat vazba
 * tri_job_invoices z proformy na nový doklad.
 */
#[Group('integration')]
final class InvoiceJobLinkPropagationTest extends TestCase
{
    private Connection $db;
    private JobInvoiceRepository $jobInvoices;
    private InvoicePaymentService $payments;
    private PaymentTaxDocumentCreator $taxDocCreator;
    private FinalFromProformaCreator $finalCreator;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    /** @var int[] */
    private array $invoiceIds = [];
    private int $jobId = 0;
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        $this->markTestSkipped('tri_job_invoices byla dropnuta ve fázi H4 — vazba jde přes MyÚčto project_id.');
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db            = $c->get(Connection::class);
            $this->jobInvoices   = $c->get(JobInvoiceRepository::class);
            $this->payments      = $c->get(InvoicePaymentService::class);
            $this->taxDocCreator = $c->get(PaymentTaxDocumentCreator::class);
            $this->finalCreator  = $c->get(FinalFromProformaCreator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user).');
        }

        $this->origVatFlags = $pdo->query(
            "SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$this->supplierId]);

        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer)
             VALUES (?, "TRI job link test", "Test 1", "Praha", "11000", ?, "CZ11111119",
                     "tri-job-link@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $jobNumber = 'TJL' . substr((string) microtime(true), -8);
        $pdo->prepare(
            'INSERT INTO tri_jobs (supplier_id, number, title, owner_user_id, status, customer_client_id)
             VALUES (?, ?, "Test zakázka TRI", ?, "active", ?)'
        )->execute([$this->supplierId, $jobNumber, $this->userId, $this->clientId]);
        $this->jobId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->supplierId,
                ]);
        }
        foreach (array_reverse($this->invoiceIds) as $id) {
            $pdo->prepare('DELETE FROM tri_job_invoices WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->jobId > 0) {
            $pdo->prepare('DELETE FROM tri_jobs WHERE id = ?')->execute([$this->jobId]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testPaymentTaxDocumentInheritsTriJobLink(): void
    {
        $proformaId = $this->seedLinkedProforma('2099010101');
        $rec = $this->payments->recordPayment($proformaId, 1210.00, '2099-01-15', ['source' => 'manual']);

        $taxDocId = $this->taxDocCreator->createForPayment($rec['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;

        $this->assertJobLinked($taxDocId, $this->jobId);

        // Idempotentní volání doplní vazbu i na existující doklad.
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM tri_job_invoices WHERE invoice_id = ?')->execute([$taxDocId]);
        self::assertNull($this->jobInvoices->jobForInvoice($taxDocId));

        $againId = $this->taxDocCreator->createForPayment($rec['payment_id'], $this->userId);
        self::assertSame($taxDocId, $againId);
        $this->assertJobLinked($taxDocId, $this->jobId);
    }

    public function testFinalInvoiceInheritsTriJobLink(): void
    {
        $proformaId = $this->seedLinkedProforma('2099010201');
        $this->payments->recordPayment($proformaId, 1210.00, '2099-01-20', ['source' => 'manual']);

        $finalId = $this->finalCreator->create($proformaId, $this->userId);
        $this->invoiceIds[] = $finalId;

        $this->assertJobLinked($finalId, $this->jobId);

        // Idempotentní volání doplní vazbu i na existující finální doklad.
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM tri_job_invoices WHERE invoice_id = ?')->execute([$finalId]);
        self::assertNull($this->jobInvoices->jobForInvoice($finalId));

        $againId = $this->finalCreator->create($proformaId, $this->userId);
        self::assertSame($finalId, $againId);
        $this->assertJobLinked($finalId, $this->jobId);
    }

    private function seedLinkedProforma(string $varsymbol): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, created_by)
             VALUES (?, ?, "proforma", ?, "2099-01-01", NULL, "2099-01-31",
                     ?, 0, 1000.00, 210.00, 1210.00, "issued", ?)'
        )->execute([$this->supplierId, $varsymbol, $this->clientId, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Záloha na objednávku", 1, "ks", 1000.00, ?, 21.0, 1000.00, 210.00, 1210.00, 0)'
        )->execute([$id, $this->vatRateId]);

        $this->jobInvoices->link($id, $this->jobId, $this->supplierId);

        return $id;
    }

    private function assertJobLinked(int $invoiceId, int $expectedJobId): void
    {
        $job = $this->jobInvoices->jobForInvoice($invoiceId);
        self::assertNotNull($job, 'Faktura musí mít vazbu na zakázku TRI.');
        self::assertSame($expectedJobId, $job['id']);
    }
}
