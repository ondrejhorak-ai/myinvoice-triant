<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\CatalogSnapshot;
use PHPUnit\Framework\TestCase;

final class CatalogSnapshotTest extends TestCase
{
    public function testCopiesCatalogFieldsIntoQuoteLine(): void
    {
        $line = CatalogSnapshot::toQuoteLine([
            'id'               => 42,
            'image_id'         => 7,
            'image'            => ['id' => 7, 'url' => '/api/tri/quote-images/7?v=abc'],
            'designation'      => 'P-01',
            'title'            => 'Police dub',
            'description'      => '18 mm',
            'default_quantity' => 2.5,
            'unit'             => 'm2',
            'base_unit_price'  => 1250.5,
            'vat_rate'         => 12,
        ]);

        $this->assertSame(42, $line['catalog_item_id']);
        $this->assertSame(7, $line['image_id']);
        $this->assertSame('P-01', $line['designation']);
        $this->assertSame('Police dub', $line['title']);
        $this->assertSame('18 mm', $line['description']);
        $this->assertSame(2.5, $line['quantity']);
        $this->assertSame('m2', $line['unit']);
        $this->assertSame(1250.5, $line['base_unit_price']);
        $this->assertSame(12, $line['vat_rate']);
        $this->assertTrue($line['is_manufactured']);
        $this->assertSame(7, $line['image']['id']);
    }

    public function testDoesNotKeepLivePriceUpdatedAt(): void
    {
        $line = CatalogSnapshot::toQuoteLine([
            'id'               => 1,
            'title'            => 'X',
            'price_updated_at' => '2026-08-01 10:00:00',
            'base_unit_price'  => 100,
        ]);

        $this->assertArrayNotHasKey('price_updated_at', $line);
        $this->assertSame(100.0, $line['base_unit_price']);
    }

    public function testZeroQuantityFallsBackToOneAndEmptyUnitToKs(): void
    {
        $line = CatalogSnapshot::toQuoteLine([
            'id'               => 3,
            'title'            => 'Y',
            'default_quantity' => 0,
            'unit'             => '  ',
        ]);

        $this->assertSame(1.0, $line['quantity']);
        $this->assertSame('ks', $line['unit']);
        $this->assertNull($line['description']);
        $this->assertNull($line['image_id']);
    }
}
