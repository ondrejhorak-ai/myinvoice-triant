<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\MyUcto;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tri\MyUcto\InvoiceGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\Service\JobInvoiceBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceGatewayTest extends TestCase
{
    public function testToInvoiceInputMapsLocalClientToMyuctoId(): void
    {
        $pdo = $this->sqlite();
        $gw = new InvoiceGateway($this->api([new Response(200, [], '{}')]), $pdo, 1);
        $input = $gw->toInvoiceInput([
            'client_id' => 10,
            'invoice_type' => 'proforma',
            'currency' => 'czk',
            'items' => [[
                'description' => 'TEST položka',
                'quantity' => 1,
                'unit_price_without_vat' => 100,
                'vat_rate_id' => 3,
                'unit' => 'ks',
            ]],
        ]);

        $this->assertSame(101, $input['client_id']);
        $this->assertSame('proforma', $input['invoice_type']);
        $this->assertSame('CZK', $input['currency']);
        $this->assertArrayNotHasKey('id', $input);
    }

    public function testToInvoiceInputPassesManualVarsymbol(): void
    {
        $pdo = $this->sqlite();
        $gw = new InvoiceGateway($this->api([new Response(200, [], '{}')]), $pdo, 1);
        $input = $gw->toInvoiceInput([
            'client_id' => 10,
            'invoice_type' => 'proforma',
            'varsymbol' => '92609001',
            'items' => [[
                'description' => 'TEST položka',
                'quantity' => 1,
                'unit_price_without_vat' => 100,
                'vat_rate_id' => 3,
                'unit' => 'ks',
            ]],
        ]);

        $this->assertSame('92609001', $input['varsymbol']);
    }

    public function testCreateDraftRecordsCommandDone(): void
    {
        $pdo = $this->sqlite();
        $created = [
            'id' => 55,
            'client_id' => 101,
            'status' => 'draft',
            'invoice_type' => 'proforma',
            'currency' => 'CZK',
            'supplier_order_number' => 'Z-1',
            'items' => [['description' => 'TEST', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 3]],
            'totals' => ['without_vat' => 100, 'vat' => 21, 'with_vat' => 121],
            'updated_at' => '2026-09-07 12:00:00',
        ];
        $gw = new InvoiceGateway($this->api([
            new Response(201, ['Content-Type' => 'application/json'], json_encode($created)),
        ]), $pdo, 1);

        $out = $gw->createDraft([
            'client_id' => 10,
            'invoice_type' => 'proforma',
            'supplier_order_number' => 'Z-1',
            'items' => [['description' => 'TEST', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 3]],
        ], 'test-key-1');

        $this->assertSame(55, $out['id']);
        $status = (string) $pdo->query("SELECT status FROM mu_commands WHERE kind = 'invoice.create'")->fetchColumn();
        $this->assertSame('done', $status);
        $myucto = (int) $pdo->query("SELECT myucto_id FROM mu_commands WHERE kind = 'invoice.create'")->fetchColumn();
        $this->assertSame(55, $myucto);
        $mirrored = (int) $pdo->query('SELECT COUNT(*) FROM mu_invoices WHERE id = 55')->fetchColumn();
        $this->assertSame(1, $mirrored);
    }

    public function testCreateDraftTimeoutReconcilesExistingDraft(): void
    {
        $pdo = $this->sqlite();
        $listed = [
            'data' => [[
                'id' => 77,
                'client_id' => 101,
                'status' => 'draft',
                'invoice_type' => 'proforma',
                'supplier_order_number' => 'Z-9',
                'project_id' => 5,
                'items' => [],
                'totals' => ['without_vat' => 1, 'vat' => 0, 'with_vat' => 1],
            ]],
        ];
        $detail = $listed['data'][0];
        $detail['updated_at'] = '2026-09-07 12:00:00';
        $req = new Request('POST', 'http://myucto-app/api/v1/invoices');
        $gw = new InvoiceGateway($this->api([
            new ConnectException('timed out', $req),
            new ConnectException('timed out', $req),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($listed)),
        ]), $pdo, 1);

        $out = $gw->createDraft([
            'client_id' => 10,
            'invoice_type' => 'proforma',
            'project_id' => 5,
            'supplier_order_number' => 'Z-9',
            'items' => [['description' => 'TEST', 'quantity' => 1, 'unit_price_without_vat' => 1, 'vat_rate_id' => 3]],
        ], 'recon-key');

        $this->assertSame(77, $out['id']);
        $status = (string) $pdo->query("SELECT status FROM mu_commands ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame('done', $status);
    }

    public function testCreateDraftValidationFailedMarksCommandFailed(): void
    {
        $pdo = $this->sqlite();
        $gw = new InvoiceGateway($this->api([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":{"code":"validation_failed","message":"Položky jsou povinné."}}'),
        ]), $pdo, 1);

        try {
            $gw->createDraft([
                'client_id' => 10,
                'items' => [['description' => 'TEST', 'quantity' => 1, 'unit_price_without_vat' => 1, 'vat_rate_id' => 3]],
            ]);
            $this->fail('expected exception');
        } catch (MyUctoApiException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }
        $status = (string) $pdo->query("SELECT status FROM mu_commands ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame('failed', $status);
    }

    public function testBuilderAdvanceAndFinalInputs(): void
    {
        $advance = JobInvoiceBuilder::advanceInput(10, 5, 'Z-100', '2026-09-21', '2026-09-07', 'Záloha TEST', 100.0, 3);
        $this->assertSame('proforma', $advance['invoice_type']);
        $this->assertSame(10, $advance['client_id']);
        $this->assertSame(5, $advance['project_id']);
        $this->assertSame('Z-100', $advance['supplier_order_number']);
        $this->assertSame(100.0, $advance['items'][0]['unit_price_without_vat']);

        $items = JobInvoiceBuilder::finalItems([
            ['title' => 'Stůl', 'quantity' => 2, 'unit' => 'ks', 'line_total' => 200, 'vat_rate' => 21],
            ['title' => 'Židle', 'quantity' => 4, 'unit' => 'ks', 'line_total' => 400, 'vat_rate' => 12],
        ], static fn (int $p) => $p === 21 ? 3 : 2);
        $this->assertCount(2, $items);
        $this->assertSame(3, $items[0]['vat_rate_id']);
        $this->assertSame(2, $items[1]['vat_rate_id']);
        $this->assertSame(100.0, $items[0]['unit_price_without_vat']);

        $discounted = JobInvoiceBuilder::finalItems([
            ['title' => 'Stůl', 'quantity' => 2, 'unit' => 'ks', 'line_total' => 200, 'vat_rate' => 21],
            ['title' => 'Židle', 'quantity' => 4, 'unit' => 'ks', 'line_total' => 400, 'vat_rate' => 12],
        ], static fn (int $p) => $p === 21 ? 3 : 2, 540.0);
        $this->assertSame(90.0, $discounted[0]['unit_price_without_vat']);
        $this->assertSame(90.0, $discounted[1]['unit_price_without_vat']);

        $this->assertSame(21, JobInvoiceBuilder::dominantVatRate([
            ['vat_rate' => 21, 'line_total' => 100],
            ['vat_rate' => 12, 'line_total' => 10],
        ]));
    }

    public function testIssueMapsLockedPeriod(): void
    {
        $pdo = $this->sqlite();
        $gw = new InvoiceGateway($this->api([
            new Response(409, ['Content-Type' => 'application/json'], '{"error":{"code":"period_locked","message":"Accounting period is closed."}}'),
        ]), $pdo, 1);
        try {
            $gw->issue(55);
            $this->fail('expected exception');
        } catch (MyUctoApiException $e) {
            $this->assertSame('period_locked', $e->errorCode);
            $this->assertStringContainsString('účetní', $e->getMessage());
        }
    }

    public function testIssueAlreadyIssuedReturnsDetail(): void
    {
        $pdo = $this->sqlite();
        $issued = [
            'id' => 55,
            'client_id' => 101,
            'status' => 'issued',
            'invoice_type' => 'invoice',
            'currency' => 'CZK',
            'items' => [],
            'totals' => ['without_vat' => 100, 'vat' => 21, 'with_vat' => 121],
            'updated_at' => '2026-09-07 12:00:00',
        ];
        $gw = new InvoiceGateway($this->api([
            new Response(409, ['Content-Type' => 'application/json'], '{"error":{"code":"already_issued","message":"Already issued."}}'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($issued)),
        ]), $pdo, 1);
        $out = $gw->issue(55);
        $this->assertSame('issued', $out['status']);
        $this->assertSame(55, $out['id']);
    }

    public function testIssuePropagatesMyuctoApplication500(): void
    {
        $pdo = $this->sqlite();
        $gw = new InvoiceGateway($this->api([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":{"code":"varsymbol_failed","message":"Chybí template pro proforma."}}'),
        ]), $pdo, 1);
        try {
            $gw->issue(55);
            $this->fail('expected exception');
        } catch (MyUctoApiException $e) {
            $this->assertSame('varsymbol_failed', $e->errorCode);
            $this->assertSame(500, $e->httpStatus);
            $this->assertStringContainsString('template pro proforma', $e->getMessage());
            $this->assertFalse($e->isUnavailable());
        }
    }

    public function testIssueFinalUnwrapsNestedInvoiceEnvelope(): void
    {
        $pdo = $this->sqlite();
        $created = [
            'final_invoice_id' => 136,
            'edit_url' => '/invoices/136/edit',
            'invoice' => [
                'id' => 136,
                'client_id' => 101,
                'status' => 'draft',
                'invoice_type' => 'invoice',
                'parent_invoice_id' => 134,
                'currency' => 'CZK',
                'note_above_items' => 'Daňový doklad k zálohové faktuře 92609001',
                'items' => [[
                    'description' => 'TRI-SCEN TEST',
                    'quantity' => 1,
                    'unit_price_without_vat' => 20520,
                    'vat_rate_id' => 3,
                    'vat_rate_snapshot' => 21,
                ]],
                'totals' => ['without_vat' => 20520, 'vat' => 4309.2, 'with_vat' => 24829.2],
                'updated_at' => '2026-09-07 12:00:00',
            ],
        ];
        $gw = new InvoiceGateway($this->api([
            new Response(201, ['Content-Type' => 'application/json'], json_encode($created)),
        ]), $pdo, 1);

        $out = $gw->issueFinal(134);

        $this->assertSame(136, $out['id']);
        $this->assertSame(134, $out['parent_invoice_id']);
        $this->assertSame(134, (int) ($out['parent_invoice']['id'] ?? 0));
        $this->assertSame('draft', $out['status']);
        $this->assertSame('invoice', $out['invoice_type']);
        $this->assertSame(21.0, $out['items'][0]['vat_rate_snapshot']);
        $mirrored = (int) $pdo->query('SELECT COUNT(*) FROM mu_invoices WHERE id = 136')->fetchColumn();
        $this->assertSame(1, $mirrored);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE clients (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            company_name TEXT,
            myucto_id INTEGER UNIQUE
        )');
        $pdo->exec("INSERT INTO clients (id, supplier_id, company_name, myucto_id) VALUES (10, 1, 'TEST s.r.o.', 101)");
        $pdo->exec('CREATE TABLE mu_invoices (
            id INTEGER PRIMARY KEY,
            client_myucto_id INTEGER,
            project_id INTEGER,
            varsymbol TEXT,
            invoice_type TEXT,
            status TEXT,
            payment_status TEXT,
            issue_date TEXT,
            due_date TEXT,
            tax_date TEXT,
            currency TEXT,
            total_without_vat TEXT,
            total_vat TEXT,
            total_with_vat TEXT,
            amount_to_pay TEXT,
            paid_total TEXT,
            paid_at TEXT,
            supplier_order_number TEXT,
            sent_at TEXT,
            reminder_count INTEGER,
            last_reminder_at TEXT,
            public_token TEXT,
            client_main_email TEXT,
            branding_profile_id INTEGER,
            items_json TEXT,
            raw_json TEXT,
            updated_at TEXT,
            mu_synced_at TEXT,
            deleted_at TEXT
        )');
        $pdo->exec('CREATE TABLE mu_commands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT,
            payload_json TEXT,
            status TEXT,
            myucto_id INTEGER,
            error TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec('CREATE TABLE mu_vat_rates (id INTEGER PRIMARY KEY, code TEXT, name TEXT, rate TEXT, is_active INTEGER)');
        $pdo->exec("INSERT INTO mu_vat_rates (id, code, name, rate, is_active) VALUES (3, '21', '21 %', '21.000', 1)");
        $pdo->exec('CREATE TABLE mu_currencies (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)');
        $pdo->exec("INSERT INTO mu_currencies (id, code, name, is_active) VALUES (1, 'CZK', 'Koruna', 1)");
        $pdo->exec('CREATE TABLE mu_units (id INTEGER PRIMARY KEY, code TEXT, name TEXT)');
        $pdo->exec('CREATE TABLE mu_branding_profiles (id INTEGER PRIMARY KEY, name TEXT, is_default INTEGER)');
        $pdo->exec('CREATE TABLE tri_jobs (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            number TEXT,
            title TEXT,
            myucto_project_id INTEGER,
            archived_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec('CREATE TABLE tri_job_invoice_overrides (
            invoice_id INTEGER PRIMARY KEY,
            job_id INTEGER,
            created_at TEXT
        )');

        return $pdo;
    }

    /** @param list<Response|\Throwable> $queue */
    private function api(array $queue): MyUctoClient
    {
        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'http_errors' => false,
        ]);

        return new MyUctoClient(new Config([
            'tri' => [
                'myucto' => [
                    'enabled' => true,
                    'base_url' => 'http://myucto-app',
                    'public_url' => 'https://ucto.triant.cz',
                    'token' => 'mi_pat_test',
                ],
            ],
        ]), $http);
    }
}
