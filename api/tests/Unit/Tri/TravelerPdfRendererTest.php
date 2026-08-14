<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\TravelerGenerator;
use MyInvoice\Tri\Service\TravelerPdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TravelerPdfRendererTest extends TestCase
{
    public function testStationRowsMatchGeneratorOrderAndHaveNoHours(): void
    {
        $rows = TravelerPdfRenderer::stationRows();

        $this->assertCount(9, $rows);
        $this->assertSame(TravelerGenerator::STATIONS, array_column($rows, 'station'));
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('hours', $row);
            $this->assertArrayNotHasKey('date', $row);
            $this->assertArrayNotHasKey('signature', $row);
            $this->assertNotSame('', $row['label_cs']);
            $this->assertNotSame('', $row['label_en']);
        }
    }

    public function testItemViewDropsPricesAndKeepsSnapshotFields(): void
    {
        $item = TravelerPdfRenderer::itemView([
            'designation'      => 'P-01',
            'title'            => 'Police',
            'description'      => 'dub',
            'quantity'         => 2,
            'unit'             => 'ks',
            'base_unit_price'  => 999,
            'unit_price'       => 999,
            'vat_rate'         => 21,
            'line_total'       => 1998,
            'catalog_item_id'  => 7,
        ]);

        $this->assertSame('P-01', $item['designation']);
        $this->assertSame('Police', $item['title']);
        $this->assertSame('dub', $item['description']);
        $this->assertSame(2.0, $item['quantity']);
        $this->assertSame('ks', $item['unit']);
        $this->assertArrayNotHasKey('base_unit_price', $item);
        $this->assertArrayNotHasKey('unit_price', $item);
        $this->assertArrayNotHasKey('vat_rate', $item);
        $this->assertArrayNotHasKey('line_total', $item);
        $this->assertSame(['designation', 'title', 'description', 'quantity', 'unit'], array_keys($item));
    }

    public function testZeroQuantityFallsBackToOne(): void
    {
        $item = TravelerPdfRenderer::itemView([
            'title'    => 'X',
            'quantity' => 0,
            'unit'     => '',
        ]);

        $this->assertSame(1.0, $item['quantity']);
        $this->assertSame('ks', $item['unit']);
    }

    #[DataProvider('basenameCases')]
    public function testFilenames(string $number, string $expectedTraveler, string $expectedJob): void
    {
        $this->assertSame($expectedTraveler, TravelerPdfRenderer::filenameForTraveler(['number' => $number]));
        $this->assertSame($expectedJob, TravelerPdfRenderer::filenameForJob(['number' => $number]));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function basenameCases(): iterable
    {
        yield 'ascii' => ['Z26-01-03', 'Pruvodka-Z26-01-03.pdf', 'Pruvodky-Z26-01-03.pdf'];
        yield 'spaces' => ['zak 1', 'Pruvodka-zak_1.pdf', 'Pruvodky-zak_1.pdf'];
        yield 'empty' => ['???', 'Pruvodka.pdf', 'Pruvodky.pdf'];
    }

    #[DataProvider('imageDimensionCases')]
    public function testItemImageFitsInsideFortyEightMillimetres(int $width, int $height, array $expected): void
    {
        $this->assertSame($expected, TravelerPdfRenderer::fitImageDimensions($width, $height));
    }

    /** @return iterable<string, array{int, int, array{float, float}}> */
    public static function imageDimensionCases(): iterable
    {
        yield 'landscape' => [640, 320, [48.0, 24.0]];
        yield 'portrait' => [320, 640, [24.0, 48.0]];
        yield 'square' => [640, 640, [48.0, 48.0]];
        yield 'small source is not enlarged beyond 300 DPI' => [100, 50, [8.47, 4.23]];
    }
}
