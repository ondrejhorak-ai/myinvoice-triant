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

    public function testRemittanceIsNoLongerAMovementKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_KIND');
        CashLedgerRules::drawerDelta('remittance', 100);
    }

    public function testAccountedMovementIsLockedForEveryoneExceptAdmin(): void
    {
        CashLedgerRules::assertEditable(false, false);
        CashLedgerRules::assertEditable(true, true);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ACCOUNTED');
        CashLedgerRules::assertEditable(true, false);
    }

    public function testMovementRejectsEmptyAmountAndKeepsCardManual(): void
    {
        $row = CashLedgerRules::normalizeMovement([
            'kind' => 'card',
            'amount' => '6000',
            'occurred_on' => '2026-10-09',
            'description' => 'Faktura 202610001',
            'party_user_id' => '4',
            'job_id' => '',
            'client_token' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);
        $this->assertSame('6000.00', $row['amount']);
        $this->assertSame(4, $row['party_user_id']);
        $this->assertNull($row['job_id']);
        $this->assertFalse($row['accounted']);
        $this->expectException(\InvalidArgumentException::class);
        CashLedgerRules::normalizeMovement([
            'kind' => 'cash_out',
            'amount' => '0',
            'occurred_on' => '2026-10-09',
            'description' => 'Drogerie',
        ]);
    }

    public function testAuditRecordsOnlyRealChangesAndNamesTheAccountedFlag(): void
    {
        $changes = CashLedgerRules::changes(
            [
                'kind' => 'cash_in',
                'amount' => '10.00',
                'occurred_on' => '2026-10-09',
                'description' => 'Prodej',
                'note' => null,
                'party_name' => 'Eva',
                'job_label' => null,
                'accounted' => false,
            ],
            [
                'kind' => 'cash_in',
                'amount' => '12.50',
                'occurred_on' => '2026-10-09',
                'description' => 'Prodej',
                'note' => null,
                'party_name' => 'Eva',
                'job_label' => '26-001 Kuchyně',
                'accounted' => true,
            ],
        );
        $this->assertSame(['amount', 'job_label', 'accounted'], array_keys($changes));
        $this->assertSame('updated', CashLedgerRules::auditAction($changes));
        $this->assertSame('accounted', CashLedgerRules::auditAction([
            'accounted' => ['from' => false, 'to' => true],
        ]));
        $this->assertSame('unaccounted', CashLedgerRules::auditAction([
            'accounted' => ['from' => true, 'to' => false],
        ]));
    }

    public function testBulkIdsAreUniqueAndBounded(): void
    {
        $this->assertSame([3, 9], CashLedgerRules::idList(['3', 9, '3']));
        $this->expectException(\InvalidArgumentException::class);
        CashLedgerRules::idList([]);
    }
}
