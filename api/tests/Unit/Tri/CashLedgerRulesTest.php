<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\CashLedgerRules;
use PHPUnit\Framework\TestCase;

final class CashLedgerRulesTest extends TestCase
{
    public function testCardDoesNotChangeTheDrawer(): void
    {
        $this->assertSame(0, CashLedgerRules::drawerDelta('card', 600_000));
        $this->assertSame(400_000, CashLedgerRules::drawerDelta('cash_in', 400_000));
        $this->assertSame(-35_200, CashLedgerRules::drawerDelta('cash_out', 35_200));
    }

    public function testHandoverLeavesTheFloatAndKeepsTheBookDifference(): void
    {
        $plan = CashLedgerRules::handover(2_134_800, 2_134_800, CashLedgerRules::FLOAT_CENTS);
        $this->assertSame(0, $plan['difference_cents']);
        $this->assertSame(1_634_800, $plan['remitted_cents']);
        $this->assertSame(500_000, $plan['remain_cents']);

        $short = CashLedgerRules::handover(2_134_800, 2_130_000, CashLedgerRules::FLOAT_CENTS);
        $this->assertSame(-4_800, $short['difference_cents']);
        $this->assertSame(1_630_000, $short['remitted_cents']);
        $this->assertNotSame(2_130_000, 2_134_800);
    }

    public function testCountedBelowFloatRemitsNothing(): void
    {
        $plan = CashLedgerRules::handover(420_000, 420_000, CashLedgerRules::FLOAT_CENTS);
        $this->assertSame(0, $plan['remitted_cents']);
        $this->assertSame(420_000, $plan['remain_cents']);
    }

    public function testHandedMovementCannotBeEdited(): void
    {
        CashLedgerRules::assertMutable(null, 'cash_in');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HANDED_OVER');
        CashLedgerRules::assertMutable(4, 'cash_in');
    }

    public function testMovementRejectsEmptyAmountAndCardIsManual(): void
    {
        $row = CashLedgerRules::normalizeMovement([
            'kind' => 'card',
            'amount' => '6000',
            'occurred_on' => '2026-10-09',
            'description' => 'Faktura 202610001',
            'client_token' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);
        $this->assertSame('6000.00', $row['amount']);
        $this->expectException(\InvalidArgumentException::class);
        CashLedgerRules::normalizeMovement([
            'kind' => 'cash_out',
            'amount' => '0',
            'occurred_on' => '2026-10-09',
            'description' => 'Drogerie',
        ]);
    }
}
