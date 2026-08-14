<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\PriceListPdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceListPdfRendererTest extends TestCase
{
    public function testPricedItemUsesQuantityTimesUnitPrice(): void
    {
        $item = PriceListPdfRenderer::pricedItem([
            'designation'      => 'A1',
            'title'            => 'Police',
            'default_quantity' => 2,
            'unit'             => 'ks',
            'base_unit_price'  => 1500,
            'vat_rate'         => 21,
        ]);

        $this->assertSame(2.0, $item['quantity']);
        $this->assertSame(1500.0, $item['unit_price']);
        $this->assertSame(3000.0, $item['line_total']);
        $this->assertSame(3630.0, $item['gross_total']);
        $this->assertSame(21, $item['vat_rate']);
    }

    public function testZeroQuantityFallsBackToOne(): void
    {
        $item = PriceListPdfRenderer::pricedItem([
            'default_quantity' => 0,
            'base_unit_price'  => 100,
            'vat_rate'         => 0,
        ]);

        $this->assertSame(1.0, $item['quantity']);
        $this->assertSame(100.0, $item['line_total']);
        $this->assertSame(100.0, $item['gross_total']);
    }

    #[DataProvider('basenameCases')]
    public function testSafeBasename(string $name, string $expected): void
    {
        $this->assertSame($expected, PriceListPdfRenderer::safeBasename($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function basenameCases(): iterable
    {
        yield 'ascii' => ['Standard 2026', 'Cenik-Standard_2026'];
        yield 'spaces' => ['Kitchen tops', 'Cenik-Kitchen_tops'];
        yield 'empty' => ['???', 'Cenik'];
    }

    #[DataProvider('imageDimensionCases')]
    public function testItemImageFitsInsideThirtySixMillimetres(int $width, int $height, array $expected): void
    {
        $this->assertSame($expected, PriceListPdfRenderer::fitImageDimensions($width, $height));
    }

    /** @return iterable<string,array{int,int,array{float,float}}> */
    public static function imageDimensionCases(): iterable
    {
        yield 'landscape' => [640, 320, [36.0, 18.0]];
        yield 'portrait' => [320, 640, [18.0, 36.0]];
        yield 'square' => [640, 640, [36.0, 36.0]];
        yield 'small source is not enlarged beyond 300 DPI' => [100, 50, [8.47, 4.23]];
    }
}
