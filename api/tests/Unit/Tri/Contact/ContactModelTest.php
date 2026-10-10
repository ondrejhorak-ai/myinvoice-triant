<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Contact;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tri\Contact\BillingContactPublisher;
use MyInvoice\Tri\Contact\ContactParty;
use MyInvoice\Tri\Contact\IcoMatch;
use MyInvoice\Tri\Contact\JobContacts;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContactModelTest extends TestCase
{
    public function testPersonRequiresEmailAndCompanyDoesNotRequireAddress(): void
    {
        $person = ContactParty::errors([
            'party_kind' => 'person',
            'company_name' => 'Jana Nováková',
        ]);
        $this->assertArrayHasKey('main_email', $person);

        $company = ContactParty::errors([
            'party_kind' => 'company',
            'company_name' => 'TEST s.r.o.',
            'main_email' => 'a@b.cz',
            'ic' => '12345678',
        ]);
        $this->assertSame([], $company);
        $missingIc = ContactParty::errors([
            'party_kind' => 'company',
            'company_name' => 'TEST s.r.o.',
        ]);
        $this->assertArrayHasKey('ic', $missingIc);
        $this->assertSame('person', ContactParty::kind(['party_kind' => 'private']));
        $this->assertFalse(ContactParty::billingReady([
            'party_kind' => 'company',
            'company_name' => 'TEST s.r.o.',
            'street' => '',
            'city' => 'Praha',
            'zip' => '11000',
        ]));
        $this->assertTrue(ContactParty::billingReady([
            'party_kind' => 'person',
            'company_name' => 'Jana',
            'street' => 'Ulice 1',
            'city' => 'Praha',
            'zip' => '11000',
        ]));
        $this->assertFalse(ContactParty::billingReady([
            'party_kind' => 'person',
            'company_client_id' => 4,
            'company_name' => 'Jana',
            'street' => 'Ulice 1',
            'city' => 'Praha',
            'zip' => '11000',
        ]));
    }

    public function testJobRolesIgnoreUnknownAndDuplicates(): void
    {
        $rows = JobContacts::normalize([
            ['client_id' => 4, 'role' => 'architect'],
            ['client_id' => 4, 'role' => 'customer'],
            ['client_id' => 5, 'role' => 'boss'],
        ], [9]);
        $this->assertSame([
            ['client_id' => 4, 'role' => 'architect'],
            ['client_id' => 5, 'role' => 'contact'],
        ], $rows);
        $this->assertSame(
            [['client_id' => 9, 'role' => 'contact']],
            JobContacts::normalize([], [9]),
        );
    }

    public function testIcoMatchOnlyExactDigits(): void
    {
        $remote = [
            ['id' => 1, 'ic' => '12345678', 'company_name' => 'A'],
            ['id' => 2, 'ic' => '1234 5678', 'company_name' => 'A znovu'],
            ['id' => 3, 'ic' => '87654321', 'company_name' => '12345678 v názvu'],
        ];
        $this->assertSame([1, 2], IcoMatch::exactIds('12345678', $remote));
        $this->assertSame([], IcoMatch::exactIds('', $remote));
    }

    public function testPushSkipsApiWhenAlreadyLinked(): void
    {
        $pdo = $this->sqlite();
        $pub = new BillingContactPublisher($this->api([]), $pdo, 1);
        $this->assertSame(101, $pub->ensure(10));
    }

    public function testPersonIsNeverPushed(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec("INSERT INTO clients (id, supplier_id, party_kind, company_name, main_email, company_client_id)
            VALUES (11, 1, 'person', 'Jana Nováková', 'jana@example.cz', 10)");
        $pub = new BillingContactPublisher($this->api([]), $pdo, 1);
        try {
            $pub->ensure(11);
            $this->fail('Osoba se neměla poslat.');
        } catch (MyUctoApiException $e) {
            $this->assertSame('person_not_billable', $e->errorCode);
        }
        $this->assertNull($pdo->query('SELECT myucto_id FROM clients WHERE id = 11')->fetchColumn());
    }

    public function testPersonWithoutCompanyIsPushedWhenAddressIsComplete(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec("INSERT INTO clients (id, supplier_id, party_kind, company_name, main_email, street, city, zip)
            VALUES (17, 1, 'person', 'Jana Nováková', 'jana@example.cz', 'Ulice 1', 'Praha', '11000')");
        $pub = new BillingContactPublisher($this->api([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'id' => 91,
                'company_name' => 'Jana Nováková',
                'street' => 'Ulice 1',
                'city' => 'Praha',
                'zip' => '11000',
                'updated_at' => '2026-10-10 12:00:00',
            ])),
        ]), $pdo, 1);

        $this->assertSame(91, $pub->ensure(17));
        $this->assertSame(91, (int) $pdo->query('SELECT myucto_id FROM clients WHERE id = 17')->fetchColumn());
    }

    public function testIncompleteAddressDoesNotCallMyucto(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec("INSERT INTO clients (id, supplier_id, party_kind, company_name, main_email, street, city, zip)
            VALUES (12, 1, 'company', 'Bez adresy', 'a@b.cz', NULL, NULL, NULL)");
        $pub = new BillingContactPublisher($this->api([]), $pdo, 1);
        try {
            $pub->ensure(12);
            $this->fail('Neúplný odběratel se neměl poslat.');
        } catch (MyUctoApiException $e) {
            $this->assertSame('billing_incomplete', $e->errorCode);
        }
        $linked = $pdo->query('SELECT myucto_id FROM clients WHERE id = 12')->fetchColumn();
        $this->assertTrue($linked === null || $linked === false);
    }

    public function testSingleExactIcoLinksWithoutCreate(): void
    {
        $pdo = $this->sqlite();
        $this->seedBillable($pdo, 13, '12345678', 'Ulice 1', 'Praha', '11000');
        $pub = new BillingContactPublisher($this->api([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [[
                    'id' => 77,
                    'ic' => '12345678',
                    'company_name' => 'TEST s.r.o.',
                    'street' => 'Ulice 1',
                    'city' => 'Praha',
                    'zip' => '11000',
                    'updated_at' => '2026-10-10 12:00:00',
                ]],
                'meta' => ['last_page' => 1],
            ])),
        ]), $pdo, 1);

        $this->assertSame(77, $pub->ensure(13));
        $this->assertSame(77, (int) $pdo->query('SELECT myucto_id FROM clients WHERE id = 13')->fetchColumn());
    }

    public function testTwoIcoMatchesDoNotMerge(): void
    {
        $pdo = $this->sqlite();
        $this->seedBillable($pdo, 14, '12345678', 'Ulice 1', 'Praha', '11000');
        $pub = new BillingContactPublisher($this->api([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    ['id' => 1, 'ic' => '12345678', 'company_name' => 'A'],
                    ['id' => 2, 'ic' => '12 345 678', 'company_name' => 'B'],
                ],
                'meta' => ['last_page' => 1],
            ])),
        ]), $pdo, 1);
        try {
            $pub->ensure(14);
            $this->fail('Nejasné IČO se nemělo spojit.');
        } catch (MyUctoApiException $e) {
            $this->assertSame('ambiguous_ic', $e->errorCode);
        }
        $linked = $pdo->query('SELECT myucto_id FROM clients WHERE id = 14')->fetchColumn();
        $this->assertTrue($linked === null || $linked === false);
    }

    public function testSameNameDoesNotAutoMergeAndCreates(): void
    {
        $pdo = $this->sqlite();
        $this->seedBillable($pdo, 15, '', 'Ulice 1', 'Praha', '11000');
        $pub = new BillingContactPublisher($this->api([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'id' => 88,
                'company_name' => 'Stejné jméno',
                'street' => 'Ulice 1',
                'city' => 'Praha',
                'zip' => '11000',
                'updated_at' => '2026-10-10 12:00:00',
            ])),
        ]), $pdo, 1);

        $this->assertSame(88, $pub->ensure(15));
        $this->assertSame(88, (int) $pdo->query('SELECT myucto_id FROM clients WHERE id = 15')->fetchColumn());
    }

    public function testMyuctoDownLeavesOfficeContact(): void
    {
        $pdo = $this->sqlite();
        $this->seedBillable($pdo, 16, '12345678', 'Ulice 1', 'Praha', '11000');
        $pub = new BillingContactPublisher($this->api([
            new Response(503, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 'unavailable', 'message' => 'down'],
            ])),
        ]), $pdo, 1);
        try {
            $pub->ensure(16);
            $this->fail('Výpadek neměl vystavit doklad.');
        } catch (MyUctoApiException $e) {
            $this->assertSame('network', $e->errorCode);
        }
        $linked = $pdo->query('SELECT myucto_id FROM clients WHERE id = 16')->fetchColumn();
        $this->assertTrue($linked === null || $linked === false);
    }

    private function seedBillable(PDO $pdo, int $id, string $ic, string $street, string $city, string $zip): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO clients (id, supplier_id, party_kind, company_name, ic, street, city, zip, main_email, is_customer)
             VALUES (?, 1, \'company\', \'Stejné jméno\', ?, ?, ?, ?, \'a@b.cz\', 1)'
        );
        $stmt->execute([$id, $ic !== '' ? $ic : null, $street, $city, $zip]);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE clients (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            party_kind TEXT,
            company_client_id INTEGER,
            company_name TEXT,
            first_name TEXT,
            last_name TEXT,
            ic TEXT,
            dic TEXT,
            street TEXT,
            city TEXT,
            zip TEXT,
            country_id INTEGER,
            main_email TEXT,
            phone TEXT,
            is_customer INTEGER,
            is_vendor INTEGER,
            payment_due_default INTEGER,
            archived_at TEXT,
            myucto_id INTEGER UNIQUE,
            myucto_updated_at TEXT,
            mu_synced_at TEXT
        )');
        $pdo->exec("INSERT INTO clients (id, supplier_id, party_kind, company_name, myucto_id)
            VALUES (10, 1, 'company', 'Už napojený', 101)");
        $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT)');
        $pdo->exec("INSERT INTO countries (id, iso2) VALUES (1, 'CZ')");
        $pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT, supplier_id INTEGER)');
        $pdo->exec("INSERT INTO currencies (id, code, supplier_id) VALUES (1, 'CZK', 1)");

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
