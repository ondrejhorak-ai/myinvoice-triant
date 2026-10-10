<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\MyUcto;

use MyInvoice\Tri\MyUcto\AdvanceSettlement;
use PHPUnit\Framework\TestCase;

final class AdvanceSettlementTest extends TestCase
{
    public function testDueDateKeepsTheProformaSpan(): void
    {
        $this->assertSame('2026-10-24', AdvanceSettlement::dueDate('2026-10-10', '2026-10-10', '2026-10-24'));
    }

    public function testDueDateUsesFourteenDaysWhenTheSpanIsMissing(): void
    {
        $this->assertSame('2026-10-24', AdvanceSettlement::dueDate('2026-10-10', '2026-10-10', '2026-10-10'));
        $this->assertSame('2026-10-24', AdvanceSettlement::dueDate('2026-10-10', null, null));
    }

    public function testDatesFollowThePaymentAndCopyNetLines(): void
    {
        $dates = AdvanceSettlement::dates([
            'paid_at' => '2026-10-10 12:00:00',
            'issue_date' => '2026-10-10',
            'due_date' => '2026-10-24',
        ]);
        $this->assertSame('2026-10-10', $dates['tax_date']);
        $this->assertSame('2026-10-24', $dates['due_date']);

        $lines = AdvanceSettlement::linesFromProforma([
            'items' => [[
                'description' => 'E2E záloha baseline',
                'quantity' => 1,
                'unit_price_without_vat' => 1652.89,
                'vat_rate_id' => 1,
                'unit' => 'ks',
            ]],
        ]);
        $this->assertSame(1652.89, $lines[0]['unit_price_without_vat']);
        $this->assertSame(1, $lines[0]['vat_rate_id']);
        $this->assertTrue(AdvanceSettlement::needsAmountRepair(['items' => [], 'total_with_vat' => 0]));
        $this->assertFalse(AdvanceSettlement::needsAmountRepair([
            'items' => $lines,
            'totals' => ['with_vat' => 2000],
        ]));
    }
}
