<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\MonthIncrementer;
use PHPUnit\Framework\TestCase;

final class IncrementMonthInStringTest extends TestCase
{
    public function testSimpleIncrement(): void
    {
        self::assertSame(
            'Konzultace 4/2026',
            MonthIncrementer::increment('Konzultace 3/2026'),
        );
    }

    public function testYearRollover(): void
    {
        self::assertSame(
            'Vícepráce 1/2026',
            MonthIncrementer::increment('Vícepráce 12/2025'),
        );
    }

    public function testNoMatchKeepsText(): void
    {
        self::assertSame(
            'Nějaký popis bez data',
            MonthIncrementer::increment('Nějaký popis bez data'),
        );
    }

    public function testMultipleMatches(): void
    {
        self::assertSame(
            'Část 4/2026 a část 6/2026',
            MonthIncrementer::increment('Část 3/2026 a část 5/2026'),
        );
    }

    public function testInvalidMonthUnchanged(): void
    {
        self::assertSame(
            'Faktura 13/2026',
            MonthIncrementer::increment('Faktura 13/2026'),
        );
    }

    public function testZeroMonthUnchanged(): void
    {
        self::assertSame(
            'Faktura 0/2026',
            MonthIncrementer::increment('Faktura 0/2026'),
        );
    }

    public function testIsoYearDashMonthPadded(): void
    {
        self::assertSame(
            'Výkaz víceprací — 2026-06',
            MonthIncrementer::increment('Výkaz víceprací — 2026-05'),
        );
    }

    public function testIsoYearDashMonthRollover(): void
    {
        self::assertSame(
            'Období 2026-01',
            MonthIncrementer::increment('Období 2025-12'),
        );
    }

    public function testYearSlashMonth(): void
    {
        self::assertSame(
            'Konzultace 2026/06',
            MonthIncrementer::increment('Konzultace 2026/05'),
        );
    }

    public function testMonthDotYearGerman(): void
    {
        self::assertSame(
            'Vícepráce 1.2026',
            MonthIncrementer::increment('Vícepráce 12.2025'),
        );
    }

    public function testMonthDashYearPadded(): void
    {
        self::assertSame(
            'Práce 02-2026',
            MonthIncrementer::increment('Práce 01-2026'),
        );
    }

    public function testFullDateNotIncremented(): void
    {
        self::assertSame(
            'Smlouva 2026-05-15',
            MonthIncrementer::increment('Smlouva 2026-05-15'),
        );
    }

    public function testCzechFullDateNotIncremented(): void
    {
        self::assertSame(
            'Datum 20.5.2026',
            MonthIncrementer::increment('Datum 20.5.2026'),
        );
    }

    public function testShortYearAmbiguousNotIncremented(): void
    {
        // "5/26" je ambiguózní (může být 26. května) — bez 4-místného roku neměníme.
        self::assertSame(
            'Konzultace 5/26',
            MonthIncrementer::increment('Konzultace 5/26'),
        );
    }

    public function testMixedFormatsInOneString(): void
    {
        self::assertSame(
            'Položka 4/2026 a období 2026-09',
            MonthIncrementer::increment('Položka 3/2026 a období 2026-08'),
        );
    }
}
