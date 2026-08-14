<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\TravelerGenerator;
use PHPUnit\Framework\TestCase;

final class TravelerGeneratorTest extends TestCase
{
    public function testCreatesOnlyManufacturedLinesWithoutPrices(): void
    {
        $plan = TravelerGenerator::plan([
            [
                'id'              => 1,
                'is_manufactured' => true,
                'designation'     => 'P-01',
                'title'           => 'Police',
                'description'     => 'dub',
                'quantity'        => 2,
                'unit'            => 'ks',
                'base_unit_price' => 999,
                'vat_rate'        => 21,
            ],
            [
                'id'              => 2,
                'is_manufactured' => false,
                'title'           => 'Kování',
                'quantity'        => 1,
                'unit'            => 'ks',
            ],
        ], []);

        $this->assertCount(1, $plan['create']);
        $this->assertSame([], $plan['missing_operations']);
        $row = $plan['create'][0];
        $this->assertSame(1, $row['quote_line_item_id']);
        $this->assertSame('P-01', $row['designation']);
        $this->assertSame('Police', $row['title']);
        $this->assertSame('dub', $row['description']);
        $this->assertSame(2.0, $row['quantity']);
        $this->assertSame('ks', $row['unit']);
        $this->assertSame(TravelerGenerator::STATIONS, $row['stations']);
        $this->assertCount(9, $row['stations']);
        $this->assertArrayNotHasKey('base_unit_price', $row);
        $this->assertArrayNotHasKey('vat_rate', $row);
    }

    public function testExistingTravelerIsNotRewrittenEvenWithHours(): void
    {
        $plan = TravelerGenerator::plan([
            [
                'id'              => 5,
                'is_manufactured' => true,
                'title'           => 'Nový název',
                'quantity'        => 9,
                'unit'            => 'm2',
            ],
        ], [
            [
                'id'                 => 10,
                'quote_line_item_id' => 5,
                'title'              => 'Starý název',
                'operations'         => [
                    ['station' => 'konstrukce', 'hours' => 1.5],
                    ['station' => 'cnc', 'hours' => null],
                ],
            ],
        ]);

        $this->assertSame([], $plan['create']);
        $missing = array_column($plan['missing_operations'], 'station');
        $this->assertNotContains('konstrukce', $missing);
        $this->assertNotContains('cnc', $missing);
        $this->assertContains('narezove_centrum', $missing);
        $this->assertContains('baleni', $missing);
        $this->assertCount(7, $plan['missing_operations']);
        foreach ($plan['missing_operations'] as $op) {
            $this->assertSame(10, $op['traveler_id']);
        }
    }

    public function testIdempotentWhenAllStationsExist(): void
    {
        $ops = [];
        foreach (TravelerGenerator::STATIONS as $station) {
            $ops[] = ['station' => $station, 'hours' => null];
        }
        $plan = TravelerGenerator::plan([
            ['id' => 3, 'is_manufactured' => 1, 'title' => 'X', 'quantity' => 1, 'unit' => 'ks'],
        ], [
            ['id' => 8, 'quote_line_item_id' => 3, 'operations' => $ops],
        ]);

        $this->assertSame([], $plan['create']);
        $this->assertSame([], $plan['missing_operations']);
    }

    public function testSecondManufacturedLineCreatesAlongsideExisting(): void
    {
        $plan = TravelerGenerator::plan([
            ['id' => 1, 'is_manufactured' => true, 'title' => 'A', 'quantity' => 1, 'unit' => 'ks'],
            ['id' => 2, 'is_manufactured' => true, 'title' => 'B', 'quantity' => 1, 'unit' => 'ks'],
        ], [
            ['id' => 20, 'quote_line_item_id' => 1, 'operations' => [['station' => 'konstrukce', 'hours' => 2]]],
        ]);

        $this->assertCount(1, $plan['create']);
        $this->assertSame(2, $plan['create'][0]['quote_line_item_id']);
        $this->assertSame('B', $plan['create'][0]['title']);
        $this->assertCount(8, $plan['missing_operations']);
    }
}
