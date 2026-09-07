<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\MyUcto;

use MyInvoice\Tri\MyUcto\CodebookSync;
use PHPUnit\Framework\TestCase;

final class CodebookSyncVatTest extends TestCase
{
    public function testReadsRatePercentAndCzechLabelFromMyuctoPayload(): void
    {
        $row = [
            'id' => 1,
            'code' => 'CZ-21',
            'rate_percent' => 21,
            'label_cs' => 'Základní 21 %',
            'is_reverse_charge' => false,
        ];

        $this->assertSame(21.0, CodebookSync::vatPercent($row));
        $this->assertSame('Základní 21 %', CodebookSync::vatName($row));
        $this->assertFalse(CodebookSync::vatIsReverseCharge($row));
    }

    public function testZeroColumnRateFallsBackToRawJson(): void
    {
        $row = [
            'id' => 1,
            'code' => 'CZ-21',
            'name' => null,
            'rate' => '0.000',
            'raw_json' => json_encode([
                'id' => 1,
                'code' => 'CZ-21',
                'rate_percent' => 21,
                'label_cs' => 'Základní 21 %',
                'is_reverse_charge' => false,
            ]),
        ];

        $this->assertSame(21.0, CodebookSync::vatPercent($row));
        $this->assertSame('Základní 21 %', CodebookSync::vatName($row));
    }

    public function testReverseChargeFromRawJson(): void
    {
        $row = [
            'id' => 4,
            'rate' => '0.000',
            'raw_json' => json_encode([
                'code' => 'CZ-RC',
                'rate_percent' => 0,
                'is_reverse_charge' => true,
                'label_cs' => 'Reverse charge',
            ]),
        ];

        $this->assertSame(0.0, CodebookSync::vatPercent($row));
        $this->assertTrue(CodebookSync::vatIsReverseCharge($row));
        $this->assertSame('Reverse charge', CodebookSync::vatName($row));
    }
}
