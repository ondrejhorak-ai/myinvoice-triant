<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Repository;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Service\JobNumberService;
use MyInvoice\Tri\Service\QuoteCalculator;
use PDO;
use PHPUnit\Framework\TestCase;

final class QuoteRepositoryTest extends TestCase
{
    public function testSaveKeepsLongTitleWithTimesSignAndClearsEmptyDiscount(): void
    {
        $title = str_repeat('2656 × 2648 × 600 mm — skříň s posuvnými dveřmi. ', 12);
        $pdo = $this->sqlite();
        $saved = $this->repository($pdo)->saveVariant(18, 1, [
            'lock_version' => 1,
            'quote_discount_type' => 'absolute',
            'quote_discount_value' => '',
            'quote_commission_type' => 'percent',
            'quote_commission_value' => null,
            'sections' => [[
                'temp_id' => 's1',
                'title' => 'Korpus',
                'discount_type' => 'percent',
                'discount_value' => '',
                'commission_value' => '',
            ]],
            'line_items' => [[
                'section_temp_id' => 's1',
                'designation' => 'K1',
                'title' => $title,
                'quantity' => 1,
                'unit' => 'ks',
                'base_unit_price' => 1200,
                'vat_rate' => 21,
                'markup_value' => '',
                'line_discount_type' => 'percent',
                'line_discount_value' => '',
            ]],
        ]);

        $this->assertNotNull($saved);
        $this->assertNull($saved['quote_discount_value']);
        $this->assertSame($title, $saved['line_items'][0]['title']);
        $this->assertStringContainsString('×', $saved['line_items'][0]['title']);
        $this->assertGreaterThan(400, strlen($saved['line_items'][0]['title']));
        $this->assertNull($saved['sections'][0]['discount_value']);
        $this->assertNull($saved['line_items'][0]['line_discount_value']);
        $this->assertSame(2, $saved['lock_version']);

        $stored = $pdo->query('SELECT quote_discount_value FROM tri_quote_variants WHERE id = 18')->fetchColumn();
        $this->assertTrue($stored === null || $stored === false);
    }

    public function testSaveRejectsFractionalQuantity(): void
    {
        $repo = $this->repository($this->sqlite());

        $this->expectException(\DomainException::class);
        $repo->saveVariant(18, 1, [
            'lock_version' => 1,
            'line_items' => [[
                'title' => '2656 × 2648 × 600 mm',
                'quantity' => 1.002,
                'base_unit_price' => 10,
            ]],
        ]);
    }

    private function repository(PDO $pdo): QuoteRepository
    {
        $db = new Connection(new Config([]));
        new \ReflectionProperty(Connection::class, 'pdo')->setValue($db, $pdo);

        return new QuoteRepository($db, new JobNumberService($db), new TagRepository($db), new QuoteCalculator());
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tri_jobs (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER NOT NULL,
            number TEXT NOT NULL,
            title TEXT NOT NULL,
            customer_client_id INTEGER
        )');
        $pdo->exec("INSERT INTO tri_jobs (id, supplier_id, number, title) VALUES (16, 1, '260016', 'Kuchyň')");
        $pdo->exec('CREATE TABLE tri_quote_variants (
            id INTEGER PRIMARY KEY,
            job_id INTEGER NOT NULL,
            variant_code TEXT NOT NULL,
            name TEXT,
            number TEXT NOT NULL,
            job_date TEXT NOT NULL,
            status TEXT NOT NULL,
            customer_client_id INTEGER,
            valid_until TEXT,
            price_release_id INTEGER,
            price_effective_date TEXT,
            quote_discount_type TEXT,
            quote_discount_value TEXT,
            quote_commission_type TEXT,
            quote_commission_value TEXT,
            subtotal REAL NOT NULL DEFAULT 0,
            vat_base_21 REAL NOT NULL DEFAULT 0,
            vat_amount_21 REAL NOT NULL DEFAULT 0,
            vat_base_12 REAL NOT NULL DEFAULT 0,
            vat_amount_12 REAL NOT NULL DEFAULT 0,
            vat_base_0 REAL NOT NULL DEFAULT 0,
            total_with_vat REAL NOT NULL DEFAULT 0,
            commission_total REAL NOT NULL DEFAULT 0,
            discount_total REAL NOT NULL DEFAULT 0,
            version INTEGER NOT NULL DEFAULT 1,
            lock_version INTEGER NOT NULL DEFAULT 1,
            internal_notes TEXT,
            note_above_items TEXT,
            note_below_items TEXT
        )');
        $pdo->exec("INSERT INTO tri_quote_variants (
            id, job_id, variant_code, number, job_date, status, lock_version,
            quote_discount_type, quote_discount_value
        ) VALUES (18, 16, 'A', '260016A', '2026-10-11', 'draft', 1, 'absolute', '')");
        $pdo->exec('CREATE TABLE tri_quote_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quote_variant_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL,
            title TEXT NOT NULL,
            discount_type TEXT,
            discount_value TEXT,
            commission_type TEXT,
            commission_value TEXT
        )');
        $pdo->exec('CREATE TABLE tri_quote_line_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quote_variant_id INTEGER NOT NULL,
            quote_section_id INTEGER,
            catalog_item_id INTEGER,
            source_product_id INTEGER,
            source_release_id INTEGER,
            image_id INTEGER,
            is_manufactured INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL,
            designation TEXT NOT NULL DEFAULT \'\',
            title TEXT NOT NULL DEFAULT \'\',
            description TEXT,
            quantity REAL NOT NULL,
            unit TEXT NOT NULL,
            base_unit_price REAL NOT NULL,
            vat_rate INTEGER NOT NULL,
            markup_type TEXT,
            markup_value TEXT,
            markup_amount REAL NOT NULL DEFAULT 0,
            line_discount_type TEXT,
            line_discount_value TEXT,
            line_total REAL NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE TABLE tri_quote_images (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            sha256 TEXT,
            width_px INTEGER,
            height_px INTEGER,
            size_bytes INTEGER
        )');

        return $pdo;
    }
}
