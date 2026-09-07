<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\MyUcto;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tri\MyUcto\ApiPage;
use MyInvoice\Tri\MyUcto\ClientSync;
use MyInvoice\Tri\MyUcto\InvoiceSync;
use MyInvoice\Tri\MyUcto\MirrorWriter;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\ProjectSync;
use PDO;
use PHPUnit\Framework\TestCase;

final class MirrorSyncTest extends TestCase
{
    public function testShouldWriteNewChangedUnchanged(): void
    {
        $this->assertTrue(ApiPage::shouldWrite(null, '2026-09-07 10:00:00'));
        $this->assertTrue(ApiPage::shouldWrite('2026-09-01 00:00:00', '2026-09-07 10:00:00'));
        $this->assertFalse(ApiPage::shouldWrite('2026-09-07 10:00:00', '2026-09-07 10:00:00'));
        $this->assertFalse(ApiPage::shouldWrite('2026-09-07 10:00:00', '2026-09-06 10:00:00'));
    }

    public function testInvoiceToRowMapsTotals(): void
    {
        $row = InvoiceSync::toRow([
            'id' => 42,
            'client_id' => 7,
            'project_id' => 9,
            'status' => 'draft',
            'invoice_type' => 'proforma',
            'currency' => 'czk',
            'totals' => ['without_vat' => 100, 'vat' => 21, 'with_vat' => 121],
            'updated_at' => '2026-09-07T12:00:00+02:00',
            'items' => [['description' => 'TEST']],
        ], '2026-09-07 12:00:00');

        $this->assertNotNull($row);
        $this->assertSame(42, $row['id']);
        $this->assertSame(7, $row['client_myucto_id']);
        $this->assertSame(9, $row['project_id']);
        $this->assertSame('100.00', $row['total_without_vat']);
        $this->assertSame('21.00', $row['total_vat']);
        $this->assertSame('121.00', $row['total_with_vat']);
        $this->assertSame('2026-09-07 12:00:00', $row['updated_at']);
        $this->assertStringContainsString('TEST', (string) $row['items_json']);
    }

    public function testClientUpsertAndSkipUnchanged(): void
    {
        $pdo = $this->sqlite();
        $sync = new ClientSync($this->unusedApi(), $pdo, 1);

        $remote = [
            'id' => 101,
            'company_name' => 'TEST s.r.o.',
            'street' => 'Ulice 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country_iso2' => 'CZ',
            'main_email' => 'test@example.com',
            'updated_at' => '2026-09-07 10:00:00',
        ];
        $sync->upsert($remote);
        $sync->upsert($remote);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM clients WHERE myucto_id = 101')->fetchColumn();
        $this->assertSame(1, $count);

        $remote['company_name'] = 'TEST změna';
        $remote['updated_at'] = '2026-09-07 11:00:00';
        $sync->upsert($remote);
        $name = (string) $pdo->query('SELECT company_name FROM clients WHERE myucto_id = 101')->fetchColumn();
        $this->assertSame('TEST změna', $name);
    }

    public function testInvoiceTombstoneWhenMissingFromHotWindow(): void
    {
        $pdo = $this->sqlite();
        $writer = new MirrorWriter($pdo);
        $now = $writer->now();
        $hotFrom = (new \DateTimeImmutable('today'))->modify('-120 days')->format('Y-m-d');
        $issue = (new \DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');

        $keep = InvoiceSync::toRow([
            'id' => 1,
            'status' => 'issued',
            'issue_date' => $issue,
            'updated_at' => $now,
            'totals' => ['without_vat' => 1, 'vat' => 0, 'with_vat' => 1],
        ], $now);
        $gone = InvoiceSync::toRow([
            'id' => 2,
            'status' => 'issued',
            'issue_date' => $issue,
            'updated_at' => $now,
            'totals' => ['without_vat' => 1, 'vat' => 0, 'with_vat' => 1],
        ], $now);
        $this->assertNotNull($keep);
        $this->assertNotNull($gone);
        $writer->upsert('mu_invoices', $keep, array_values(array_diff(array_keys($keep), ['id'])));
        $writer->upsert('mu_invoices', $gone, array_values(array_diff(array_keys($gone), ['id'])));

        $page = json_encode([
            'data' => [[
                'id' => 1,
                'status' => 'issued',
                'issue_date' => $issue,
                'updated_at' => $now,
                'totals' => ['without_vat' => 1, 'vat' => 0, 'with_vat' => 1],
            ]],
            'meta' => ['last_page' => 1],
        ], JSON_THROW_ON_ERROR);

        $api = $this->api([
            new Response(200, ['Content-Type' => 'application/json'], $page),
            new Response(200, ['Content-Type' => 'application/json'], '{"data":[],"meta":{"last_page":1}}'),
            // tombstone safety-net: GET /invoices/2 → 404 (opravdu smazaná)
            new Response(404, ['Content-Type' => 'application/json'], '{"error":{"code":"not_found","message":"Nenalezeno"}}'),
        ]);
        $n = (new InvoiceSync($api, $pdo, $writer))->run(false);
        $this->assertGreaterThanOrEqual(1, $n);

        $deleted = $pdo->query('SELECT deleted_at FROM mu_invoices WHERE id = 2')->fetchColumn();
        $this->assertNotFalse($deleted);
        $this->assertNotNull($deleted);
        $kept = $pdo->query('SELECT deleted_at FROM mu_invoices WHERE id = 1')->fetchColumn();
        $this->assertNull($kept);
        $this->assertSame($hotFrom, $hotFrom);
    }

    public function testDraftStillAliveIsNotTombstonedWhenListMissesIt(): void
    {
        $pdo = $this->sqlite();
        $writer = new MirrorWriter($pdo);
        $now = $writer->now();

        $draft = InvoiceSync::toRow([
            'id' => 133,
            'status' => 'draft',
            'updated_at' => $now,
            'totals' => ['without_vat' => 1000, 'vat' => 210, 'with_vat' => 1210],
        ], $now);
        $this->assertNotNull($draft);
        $writer->upsert('mu_invoices', $draft, array_values(array_diff(array_keys($draft), ['id'])));

        $api = $this->api([
            new Response(200, ['Content-Type' => 'application/json'], '{"data":[],"meta":{"last_page":1}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"data":[],"meta":{"last_page":1}}'),
            // safety-net GET: koncept v MyÚčtu pořád žije → nemazat
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'id' => 133,
                    'status' => 'draft',
                    'updated_at' => $now,
                    'totals' => ['without_vat' => 1000, 'vat' => 210, 'with_vat' => 1210],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        (new InvoiceSync($api, $pdo, $writer))->run(false);

        $deleted = $pdo->query('SELECT deleted_at FROM mu_invoices WHERE id = 133')->fetchColumn();
        $this->assertNull($deleted);
    }

    public function testPullFlattensMonthBucketedInvoiceList(): void
    {
        $pdo = $this->sqlite();
        $writer = new MirrorWriter($pdo);
        $issue = (new \DateTimeImmutable('today'))->modify('-5 days')->format('Y-m-d');

        // Reálný tvar MyÚčto listu: data = měsíční skupiny s vnořenými invoices
        $bucketPage = json_encode([
            'data' => [[
                'month' => substr($issue, 0, 7),
                'count' => 2,
                'invoices' => [
                    [
                        'id' => 133,
                        'status' => 'draft',
                        'invoice_type' => 'invoice',
                        'client_id' => 37,
                        'project_id' => 37,
                        'total_without_vat' => 1000,
                        'total_vat' => 210,
                        'total_with_vat' => 1210,
                    ],
                    [
                        'id' => 134,
                        'status' => 'issued',
                        'issue_date' => $issue,
                        'total_without_vat' => 50,
                        'total_vat' => 10.5,
                        'total_with_vat' => 60.5,
                    ],
                ],
            ]],
            'meta' => ['total' => 2, 'page' => 1, 'per_page' => 200, 'pages' => 1],
        ], JSON_THROW_ON_ERROR);

        $api = $this->api([
            new Response(200, ['Content-Type' => 'application/json'], $bucketPage),
            new Response(200, ['Content-Type' => 'application/json'], '{"data":[],"meta":{"last_page":1}}'),
        ]);
        (new InvoiceSync($api, $pdo, $writer))->run(false);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM mu_invoices WHERE deleted_at IS NULL')->fetchColumn();
        $this->assertSame(2, $count);
        $status = (string) $pdo->query('SELECT status FROM mu_invoices WHERE id = 133')->fetchColumn();
        $this->assertSame('draft', $status);
        $net = (string) $pdo->query('SELECT total_without_vat FROM mu_invoices WHERE id = 133')->fetchColumn();
        $this->assertSame('1000.00', $net);
    }

    public function testProjectLinksJobByNumber(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec("INSERT INTO tri_jobs (id, number, title, myucto_project_id) VALUES (1, 'T-001', 'Zakázka', NULL)");
        $api = $this->api([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [[
                    'id' => 55,
                    'client_id' => 9,
                    'name' => 'T-001 Zakázka',
                    'project_number' => 'T-001',
                    'status' => 'active',
                    'updated_at' => '2026-09-07 10:00:00',
                ]],
                'meta' => ['last_page' => 1],
            ], JSON_THROW_ON_ERROR)),
        ]);
        (new ProjectSync($api, $pdo, new MirrorWriter($pdo)))->run();
        $linked = (int) $pdo->query('SELECT myucto_project_id FROM tri_jobs WHERE id = 1')->fetchColumn();
        $this->assertSame(55, $linked);
    }

    public function testRepeatedInvoiceUpsertIsNoOpWhenUnchanged(): void
    {
        $pdo = $this->sqlite();
        $writer = new MirrorWriter($pdo);
        $api = $this->unusedApi();
        $sync = new InvoiceSync($api, $pdo, $writer);
        $remote = [
            'id' => 8,
            'status' => 'draft',
            'updated_at' => '2026-09-07 08:00:00',
            'totals' => ['without_vat' => 10, 'vat' => 2, 'with_vat' => 12],
        ];
        $sync->upsert($remote);
        $sync->upsert($remote);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM mu_invoices WHERE id = 8')->fetchColumn();
        $status = (string) $pdo->query("SELECT status FROM mu_invoices WHERE id = 8")->fetchColumn();
        $this->assertSame(1, $count);
        $this->assertSame('draft', $status);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT)');
        $pdo->exec("INSERT INTO countries (id, iso2) VALUES (1, 'CZ')");
        $pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT, supplier_id INTEGER)');
        $pdo->exec("INSERT INTO currencies (id, code, supplier_id) VALUES (1, 'CZK', 1)");
        $pdo->exec('CREATE TABLE clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NOT NULL,
            company_name TEXT NOT NULL,
            first_name TEXT,
            last_name TEXT,
            ic TEXT,
            dic TEXT,
            street TEXT NOT NULL,
            city TEXT NOT NULL,
            zip TEXT NOT NULL,
            country_id INTEGER NOT NULL,
            main_email TEXT,
            phone TEXT,
            language TEXT DEFAULT \'cs\',
            currency_default_id INTEGER NOT NULL,
            is_customer INTEGER DEFAULT 1,
            is_vendor INTEGER DEFAULT 0,
            payment_due_default INTEGER,
            archived_at TEXT,
            myucto_id INTEGER UNIQUE,
            myucto_updated_at TEXT,
            mu_synced_at TEXT
        )');
        $pdo->exec('CREATE TABLE tri_jobs (
            id INTEGER PRIMARY KEY,
            number TEXT,
            title TEXT,
            myucto_project_id INTEGER
        )');
        $pdo->exec('CREATE TABLE mu_projects (
            id INTEGER PRIMARY KEY,
            client_myucto_id INTEGER,
            name TEXT,
            project_number TEXT,
            status TEXT,
            updated_at TEXT,
            raw_json TEXT,
            mu_synced_at TEXT,
            deleted_at TEXT
        )');
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
        $pdo->exec('CREATE TABLE mu_sync_state (
            `key` TEXT PRIMARY KEY,
            last_run_at TEXT,
            last_ok_at TEXT,
            sync_cursor TEXT,
            last_error TEXT
        )');
        $pdo->exec("INSERT INTO mu_sync_state (`key`, last_ok_at) VALUES ('invoices_cold', '2099-01-01 00:00:00')");

        return $pdo;
    }

    /** @param list<Response> $queue */
    private function api(array $queue): MyUctoClient
    {
        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'http_errors' => false,
        ]);

        return new MyUctoClient(new Config([
            'tri' => ['myucto' => [
                'enabled' => true,
                'base_url' => 'http://myucto-app',
                'public_url' => 'https://ucto.triant.cz',
                'token' => 'mi_pat_test',
            ]],
        ]), $http);
    }

    private function unusedApi(): MyUctoClient
    {
        return $this->api([]);
    }
}
