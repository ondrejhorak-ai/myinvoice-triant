<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tri;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Repository\PricingRepository;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Service\JobNumberService;
use MyInvoice\Tri\Service\QuoteCalculator;
use PHPUnit\Framework\TestCase;

/** Run only against a migrated, disposable MariaDB database, never the application database. */
final class PricingRepositoryTest extends TestCase
{
    public function testReleaseLifecycleAndQuoteSnapshots(): void
    {
        $name = getenv('TRI_PRICING_TEST_DB');
        if (!$name || !str_ends_with($name, '_test')) {
            $this->markTestSkipped('Set TRI_PRICING_TEST_DB to a disposable migrated database ending in _test.');
        }
        $db = new Connection(new Config(['db' => ['host' => '127.0.0.1','port' => (int)(getenv('TRI_PRICING_TEST_PORT') ?: 13379),'name' => $name,'user' => getenv('TRI_PRICING_TEST_USER') ?: 'root','pass' => getenv('TRI_PRICING_TEST_PASS') ?: '']]));
        $pdo = $db->pdo();
        $supplier = (int)$pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if (!$supplier) {
            $this->markTestSkipped('Seed a synthetic supplier in the disposable database first.');
        }
        $repo = new PricingRepository($db);
        $listId = $repo->create($supplier, ['name' => 'Synthetic release lifecycle','currency' => 'CZK']);
        $product = $repo->product($supplier, 0, ['code' => 'TEST-'.bin2hex(random_bytes(6)),'title' => 'Synthetic product','unit' => 'ks','vat_rate' => 0]);
        $draft = $repo->draft($supplier, $listId);
        $rid = (int)$draft['release']['id'];
        self::assertSame($rid, (int)$repo->draft($supplier, $listId)['release']['id']);
        $body = ['lock_version' => 0,'valid_from' => date('Y-m-d'),'items' => [['product_id' => $product['id'],'unit_price' => 100]]];
        $saved = $repo->save($supplier, $listId, $rid, $body);
        try {
            $repo->save($supplier, $listId, $rid, $body);
            self::fail('Stale save accepted');
        } catch (\UnexpectedValueException) {
            self::assertTrue(true);
        }
        $repo->publish($supplier, $listId, $rid, (int)$saved['release']['lock_version'], 0);
        try {
            $body['lock_version'] = 2;
            $repo->save($supplier, $listId, $rid, $body);
            self::fail('Published release changed');
        } catch (\UnexpectedValueException) {
            self::assertTrue(true);
        }
        try {
            $repo->detail($supplier + 90000, $listId);
            self::fail('Supplier scope bypassed');
        } catch (\OutOfBoundsException) {
            self::assertTrue(true);
        }
        $next = $repo->draft($supplier, $listId);
        $nextId = (int)$next['release']['id'];
        self::assertSame($rid, (int)$repo->detail($supplier, $listId)['release']['id'], 'Detail opens the current release, not its draft');
        self::assertSame($saved['items'][0]['price_changed_at'], $next['items'][0]['price_changed_at']);
        $body['lock_version'] = 0;
        $body['valid_from'] = date('Y-m-d', strtotime('+1 day'));
        $body['items'][0]['unit_price'] = null;
        $next = $repo->save($supplier, $listId, $nextId, $body);
        try {
            $repo->publish($supplier, $listId, $nextId, 1, 0);
            self::fail('Blank price published');
        } catch (\DomainException) {
            self::assertTrue(true);
        }
        $body['lock_version'] = 1;
        $body['items'][0]['unit_price'] = 120;
        $next = $repo->save($supplier, $listId, $nextId, $body);
        $repo->publish($supplier, $listId, $nextId, 2, 0);
        self::assertSame('100.00', $repo->detail($supplier, $listId, $rid)['items'][0]['unit_price']);
        self::assertSame(date('Y-m-d'), $repo->detail($supplier, $listId, $rid)['release']['valid_until']);
        $pdo->prepare('INSERT INTO users(email,password_hash,name,role) VALUES (?,?,?,?)')->execute(['qa-'.bin2hex(random_bytes(5)).'@example.invalid',password_hash(random_bytes(24), PASSWORD_BCRYPT),'Synthetic author','admin']);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO tri_jobs(supplier_id,number,title,owner_user_id,status) VALUES(?,?,?,?,'active')")->execute([$supplier,'QA'.bin2hex(random_bytes(4)),'Synthetic quote test',$uid]);
        $job = (int)$pdo->lastInsertId();
        $quotes = new QuoteRepository($db, new JobNumberService($db), new TagRepository($db), new QuoteCalculator());
        $variant = $quotes->createVariant($job, $supplier);
        $payload = ['lock_version' => $variant['lock_version'],'job_date' => date('Y-m-d'),'price_release_id' => $rid,'price_effective_date' => date('Y-m-d'),'sections' => [],'line_items' => [['source_product_id' => $product['id'],'source_release_id' => $rid,'title' => 'Synthetic product','designation' => 'TEST','quantity' => 2,'unit' => 'ks','base_unit_price' => 100,'vat_rate' => 0,'is_manufactured' => true]]];
        $variant = $quotes->saveVariant((int)$variant['id'], $supplier, $payload);
        self::assertSame(200.0, $variant['total_with_vat']);
        self::assertSame((int)$product['id'], $variant['line_items'][0]['source_product_id']);
        $duplicate = $quotes->duplicateVariant((int)$variant['id'], $supplier);
        self::assertSame($rid, $duplicate['price_release_id']);
        self::assertSame($rid, $duplicate['line_items'][0]['source_release_id']);
        $payload['lock_version'] = $variant['lock_version'];
        $payload['line_items'][0]['source_release_id'] = $nextId;
        try {
            $quotes->saveVariant((int)$variant['id'],$supplier,$payload);
            self::fail('Mismatched release accepted');
        } catch (\RuntimeException $e) {
            self::assertSame('INVALID_CATALOG_ITEM',$e->getMessage());
        }
        // Synthetic rows intentionally remain for browser QA; discard the test database afterwards.
    }
}
