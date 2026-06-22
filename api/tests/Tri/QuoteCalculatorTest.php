<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Tri;

use MyInvoice\Tri\Service\QuoteCalculator;
use PHPUnit\Framework\TestCase;

final class QuoteCalculatorTest extends TestCase
{
    public function testCommissionGrossupWithLineDiscount(): void
    {
        $calc = new QuoteCalculator();
        $result = $calc->calculateVariant([], [[
            'quantity'             => 1,
            'base_unit_price'      => 10000,
            'vat_rate'             => 21,
            'markup_type'          => 'percent',
            'markup_value'         => 10,
            'line_discount_type'   => 'percent',
            'line_discount_value'  => 20,
        ]], []);

        $line = $result['lines'][0];
        $this->assertSame(10000.0, $line['line_base']);
        $this->assertSame(1111.11, $line['markup_amount']);
        $this->assertSame(8888.89, $line['line_total']);
        $this->assertSame(1111.11, $result['commission_total']);
        $this->assertSame(2222.22, $result['discount_total']);
        $this->assertSame(8888.89, $result['subtotal']);
    }

    public function testSectionCommissionOverridesLineCommission(): void
    {
        $calc = new QuoteCalculator();
        $result = $calc->calculateVariant([
            ['temp_id' => 's1', 'commission_type' => 'percent', 'commission_value' => 10],
        ], [[
            'quantity'        => 1,
            'base_unit_price' => 900,
            'vat_rate'        => 21,
            'markup_type'     => 'percent',
            'markup_value'    => 5,
            'section_temp_id' => 's1',
        ]], []);

        $line = $result['lines'][0];
        $this->assertSame(1000.0, $line['line_base'] + $line['markup_amount']);
        $this->assertSame(100.0, $line['markup_amount']);
        $this->assertSame(1000.0, $line['line_total']);
    }

    public function testQuoteCommissionOverridesSectionAndLine(): void
    {
        $calc = new QuoteCalculator();
        $result = $calc->calculateVariant([
            ['temp_id' => 's1', 'commission_type' => 'percent', 'commission_value' => 5],
        ], [[
            'quantity'        => 1,
            'base_unit_price' => 900,
            'vat_rate'        => 21,
            'markup_type'     => 'absolute',
            'markup_value'    => 50,
            'section_temp_id' => 's1',
        ]], [
            'quote_commission_type'  => 'percent',
            'quote_commission_value' => 10,
        ]);

        $line = $result['lines'][0];
        $this->assertSame(1000.0, $line['line_base'] + $line['markup_amount']);
    }

    public function testCascadeSectionAndQuoteDiscount(): void
    {
        $calc = new QuoteCalculator();
        $result = $calc->calculateVariant([
            ['temp_id' => 's1', 'discount_type' => 'percent', 'discount_value' => 10],
        ], [[
            'quantity'             => 1,
            'base_unit_price'      => 1000,
            'vat_rate'             => 21,
            'line_discount_type'   => 'percent',
            'line_discount_value'  => 10,
            'section_temp_id'      => 's1',
        ]], [
            'quote_discount_type'  => 'percent',
            'quote_discount_value' => 10,
        ]);

        // 1000 -> line -10% = 900 -> section -10% = 810 -> quote -10% = 729
        $this->assertSame(810.0, $result['lines'][0]['line_total']);
        $this->assertSame(729.0, $result['subtotal']);
        $this->assertSame(271.0, $result['discount_total']);
    }

    public function testVariantTotalsWithMixedVat(): void
    {
        $calc = new QuoteCalculator();
        $result = $calc->calculateVariant([], [
            ['quantity' => 1, 'base_unit_price' => 100, 'vat_rate' => 21],
            ['quantity' => 1, 'base_unit_price' => 50, 'vat_rate' => 12],
        ], [
            'quote_discount_type'  => 'percent',
            'quote_discount_value' => 10,
        ]);

        $this->assertSame(135.0, $result['subtotal']);
        $this->assertGreaterThan(135.0, $result['total_with_vat']);
    }

    public function testAbsoluteLineDiscount(): void
    {
        $calc = new QuoteCalculator();
        $result = $calc->calculateVariant([], [[
            'quantity'             => 1,
            'base_unit_price'      => 10000,
            'vat_rate'             => 21,
            'line_discount_type'   => 'absolute',
            'line_discount_value'  => 500,
        ]], []);

        $this->assertSame(9500.0, $result['lines'][0]['line_total']);
        $this->assertSame(500.0, $result['discount_total']);
    }
}
