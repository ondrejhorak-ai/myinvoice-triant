<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Service\PriceListPricing;
use PHPUnit\Framework\TestCase;

final class PriceListPricingTest extends TestCase
{
    public function testNewItemAlwaysStampsPriceUpdatedAt(): void
    {
        $this->assertTrue(PriceListPricing::shouldStampPriceUpdatedAt(true, null, 0));
        $this->assertTrue(PriceListPricing::shouldStampPriceUpdatedAt(true, null, '1250.00'));
    }

    public function testUnchangedPriceDoesNotStamp(): void
    {
        $this->assertFalse(PriceListPricing::shouldStampPriceUpdatedAt(false, '100.00', 100));
        $this->assertFalse(PriceListPricing::shouldStampPriceUpdatedAt(false, 100.5, '100.50'));
        $this->assertFalse(PriceListPricing::shouldStampPriceUpdatedAt(false, 0, '0.00'));
    }

    public function testChangedPriceStamps(): void
    {
        $this->assertTrue(PriceListPricing::shouldStampPriceUpdatedAt(false, '100.00', 110));
        $this->assertTrue(PriceListPricing::shouldStampPriceUpdatedAt(false, 0, 1));
        $this->assertTrue(PriceListPricing::shouldStampPriceUpdatedAt(false, '99.99', '100.00'));
    }

    public function testNormalizeMoneyUsesTwoDecimals(): void
    {
        $this->assertSame('100.00', PriceListPricing::normalizeMoney(100));
        $this->assertSame('100.50', PriceListPricing::normalizeMoney('100.5'));
        $this->assertSame('0.00', PriceListPricing::normalizeMoney(null));
    }
}
