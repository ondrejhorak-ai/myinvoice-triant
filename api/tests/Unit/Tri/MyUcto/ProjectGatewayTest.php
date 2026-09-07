<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\MyUcto;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\ProjectGateway;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectGatewayTest extends TestCase
{
    public function testEnsureCreatesProjectAndStoresId(): void
    {
        $pdo = $this->sqlite();
        $created = [
            'id' => 42,
            'client_id' => 101,
            'name' => '260099 Kuchyň TRI-SCEN',
            'project_number' => '260099',
            'status' => 'active',
            'updated_at' => '2026-09-07 12:00:00',
        ];
        $gw = new ProjectGateway($this->api([
            new Response(201, ['Content-Type' => 'application/json'], json_encode($created)),
        ]), $pdo, 1);

        $out = $gw->ensureProjectForJob([
            'id' => 9,
            'customer_client_id' => 10,
            'number' => '260099',
            'title' => 'Kuchyň TRI-SCEN',
        ]);

        $this->assertSame(42, $out['myucto_project_id']);
        $stored = (int) $pdo->query('SELECT myucto_project_id FROM tri_jobs WHERE id = 9')->fetchColumn();
        $this->assertSame(42, $stored);
        $mirrored = (int) $pdo->query('SELECT COUNT(*) FROM mu_projects WHERE id = 42')->fetchColumn();
        $this->assertSame(1, $mirrored);
    }

    public function testEnsureIsIdempotentWhenAlreadyLinked(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('UPDATE tri_jobs SET myucto_project_id = 7 WHERE id = 9');
        $gw = new ProjectGateway($this->api([]), $pdo, 1);

        $out = $gw->ensureProjectForJob([
            'id' => 9,
            'customer_client_id' => 10,
            'myucto_project_id' => 7,
            'number' => '260099',
            'title' => 'Kuchyň TRI-SCEN',
        ]);

        $this->assertSame(7, $out['myucto_project_id']);
    }

    public function testTryEnsureSwallowsApiError(): void
    {
        $pdo = $this->sqlite();
        $gw = new ProjectGateway($this->api([
            new Response(503, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 'unavailable', 'message' => 'down'],
            ])),
        ]), $pdo, 1);

        $job = [
            'id' => 9,
            'customer_client_id' => 10,
            'number' => '260099',
            'title' => 'Kuchyň TRI-SCEN',
        ];
        $out = $gw->tryEnsureProjectForJob($job);
        $this->assertNull($out['myucto_project_id'] ?? null);
        $stored = $pdo->query('SELECT myucto_project_id FROM tri_jobs WHERE id = 9')->fetchColumn();
        $this->assertTrue($stored === null || $stored === false || (int) $stored === 0);
    }

    public function testEnsurePropagatesApiError(): void
    {
        $pdo = $this->sqlite();
        $gw = new ProjectGateway($this->api([
            new Response(503, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 'unavailable', 'message' => 'down'],
            ])),
        ]), $pdo, 1);

        $this->expectException(MyUctoApiException::class);
        $gw->ensureProjectForJob([
            'id' => 9,
            'customer_client_id' => 10,
            'number' => '260099',
            'title' => 'Kuchyň TRI-SCEN',
        ]);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE clients (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            company_name TEXT,
            myucto_id INTEGER UNIQUE,
            payment_due_default INTEGER
        )');
        $pdo->exec("INSERT INTO clients (id, supplier_id, company_name, myucto_id, payment_due_default)
            VALUES (10, 1, 'TEST s.r.o.', 101, 14)");
        $pdo->exec('CREATE TABLE tri_jobs (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            number TEXT,
            title TEXT,
            myucto_project_id INTEGER,
            archived_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec("INSERT INTO tri_jobs (id, supplier_id, number, title, myucto_project_id)
            VALUES (9, 1, '260099', 'Kuchyň TRI-SCEN', NULL)");
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
