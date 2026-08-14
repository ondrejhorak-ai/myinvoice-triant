<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\CalendarEventRules;
use PHPUnit\Framework\TestCase;

final class CalendarEventRulesTest extends TestCase
{
    public function testProductionRequiresStationFromCalendarSetNotTravelerSet(): void
    {
        $row = CalendarEventRules::normalize([
            'calendar'  => 'production',
            'title'     => 'CNC běh',
            'station'   => 'vyroba',
            'starts_at' => '2026-08-17',
            'all_day'   => 1,
        ]);
        $this->assertSame('vyroba', $row['station']);
        $this->assertSame('2026-08-17 00:00:00', $row['starts_at']);
        $this->assertNotContains('narezove_centrum', CalendarEventRules::STATIONS);
        $this->assertNotContains('cnc', CalendarEventRules::STATIONS);
    }

    public function testShiftsDropStation(): void
    {
        $row = CalendarEventRules::normalize([
            'calendar'  => 'shifts',
            'title'     => 'Ranní',
            'station'   => 'vyroba',
            'starts_at' => '2026-08-17',
            'all_day'   => true,
        ]);
        $this->assertNull($row['station']);
        $this->assertSame('shifts', $row['calendar']);
    }

    public function testProductionWithoutStationFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('STATION_REQUIRED');
        CalendarEventRules::normalize([
            'calendar'  => 'production',
            'title'     => 'X',
            'starts_at' => '2026-08-17',
        ]);
    }

    public function testTravelerStationIsRejectedOnProduction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalendarEventRules::normalize([
            'calendar'  => 'production',
            'title'     => 'X',
            'station'   => 'cnc',
            'starts_at' => '2026-08-17',
        ]);
    }

    public function testOverlapsRangeInclusiveEnd(): void
    {
        $this->assertTrue(CalendarEventRules::overlapsRange(
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
            '2026-08-31 00:00:00',
            '2026-08-31 23:59:59',
        ));
        $this->assertFalse(CalendarEventRules::overlapsRange(
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
            '2026-09-01 00:00:00',
            null,
        ));
        $this->assertTrue(CalendarEventRules::overlapsRange(
            '2026-08-10 00:00:00',
            '2026-08-17 00:00:00',
            '2026-08-09 00:00:00',
            '2026-08-10 12:00:00',
        ));
    }

    public function testMonthGridStartsOnMondayAndHasFortyTwoDays(): void
    {
        $days = CalendarEventRules::monthGrid(2026, 8);
        $this->assertCount(42, $days);
        $this->assertSame('2026-07-27', $days[0]);
        $this->assertSame('2026-08-01', $days[5]);
        $this->assertSame(1, (int) (new \DateTimeImmutable($days[0]))->format('N'));
        $this->assertSame('2026-09-06', $days[41]);
    }

    public function testWeekDaysAreMondayToSunday(): void
    {
        $days = CalendarEventRules::weekDays('2026-08-14');
        $this->assertSame([
            '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13',
            '2026-08-14', '2026-08-15', '2026-08-16',
        ], $days);
    }

    public function testEndBeforeStartFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_RANGE');
        CalendarEventRules::normalize([
            'calendar'  => 'dispatch',
            'title'     => 'Expedice',
            'starts_at' => '2026-08-18 10:00:00',
            'ends_at'   => '2026-08-18 09:00:00',
            'all_day'   => 0,
        ]);
    }
}
