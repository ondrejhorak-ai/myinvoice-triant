<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\TravelerGenerator;
use MyInvoice\Tri\Service\TravelerHours;
use PHPUnit\Framework\TestCase;

final class TravelerHoursTest extends TestCase
{
    public function testSummarizeAddsHoursPerStationAndSkipsNull(): void
    {
        $sum = TravelerHours::summarize([
            ['station' => 'cnc', 'hours' => 1.5],
            ['station' => 'cnc', 'hours' => 2],
            ['station' => 'lakovna', 'hours' => '0,5'],
            ['station' => 'baleni', 'hours' => null],
            ['station' => 'konstrukce', 'hours' => ''],
        ]);

        $this->assertSame(4.0, $sum['total']);
        $this->assertSame([
            ['station' => 'cnc', 'hours' => 3.5],
            ['station' => 'lakovna', 'hours' => 0.5],
        ], $sum['by_station']);
        $this->assertSame(TravelerGenerator::STATIONS[2], 'cnc');
    }

    public function testSummarizeKeepsStationOrderAndAvoidsFloatDrift(): void
    {
        $sum = TravelerHours::summarize([
            ['station' => 'konstrukce', 'hours' => 0.1],
            ['station' => 'cnc', 'hours' => 0.2],
            ['station' => 'baleni', 'hours' => 0.3],
        ]);

        $this->assertSame(0.6, $sum['total']);
        $this->assertSame('konstrukce', $sum['by_station'][0]['station']);
        $this->assertSame('cnc', $sum['by_station'][1]['station']);
        $this->assertSame('baleni', $sum['by_station'][2]['station']);
    }

    public function testSummarizeTravelersFlattensOperations(): void
    {
        $sum = TravelerHours::summarizeTravelers([
            ['operations' => [
                ['station' => 'montaz', 'hours' => 2],
            ]],
            ['operations' => [
                ['station' => 'montaz', 'hours' => 1.25],
                ['station' => 'olepovacka', 'hours' => 4],
            ]],
        ]);

        $this->assertSame(7.25, $sum['total']);
        $this->assertSame(4.0, $sum['by_station'][0]['hours']);
        $this->assertSame('olepovacka', $sum['by_station'][0]['station']);
        $this->assertSame(3.25, $sum['by_station'][1]['hours']);
    }

    public function testParseHoursAcceptsCzechDecimalAndEmpty(): void
    {
        $this->assertNull(TravelerHours::parseHours(null));
        $this->assertNull(TravelerHours::parseHours(''));
        $this->assertNull(TravelerHours::parseHours('  '));
        $this->assertSame(1.5, TravelerHours::parseHours('1,5'));
        $this->assertSame(12.0, TravelerHours::parseHours('12'));
        $this->assertSame(0.0, TravelerHours::parseHours(0));
    }

    public function testParseHoursRejectsNegativeAndNonNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TravelerHours::parseHours(-1);
    }

    public function testNormalizeOperationsRejectsUnknownStation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_STATION');
        TravelerHours::normalizeOperations([
            ['station' => 'vyroba', 'hours' => 1],
        ]);
    }

    public function testNormalizeOperationsParsesPayload(): void
    {
        $ops = TravelerHours::normalizeOperations([
            ['station' => 'cnc', 'hours' => '1,25', 'note' => '  ok  '],
            ['station' => 'baleni', 'hours' => '', 'note' => ''],
        ]);

        $this->assertCount(2, $ops);
        $this->assertSame('cnc', $ops[0]['station']);
        $this->assertSame(1.25, $ops[0]['hours']);
        $this->assertSame('ok', $ops[0]['note']);
        $this->assertNull($ops[1]['hours']);
        $this->assertNull($ops[1]['note']);
    }
}
