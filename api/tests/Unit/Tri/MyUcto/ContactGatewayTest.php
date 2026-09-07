<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\MyUcto;

use MyInvoice\Tri\MyUcto\ContactGateway;
use PHPUnit\Framework\TestCase;

final class ContactGatewayTest extends TestCase
{
    public function testToClientInputMapsIso2AndSkipsLocalId(): void
    {
        $input = ContactGateway::toClientInput([
            'id' => 99,
            'company_name' => ' TEST s.r.o. ',
            'street' => 'Ulice 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country_iso2' => 'cz',
            'main_email' => 'a@b.cz',
            'is_customer' => 1,
            'is_vendor' => 0,
            'payment_due_default' => 14,
        ]);

        $this->assertArrayNotHasKey('id', $input);
        $this->assertSame('TEST s.r.o.', $input['company_name']);
        $this->assertSame('CZ', $input['country_iso2']);
        $this->assertTrue($input['is_customer']);
        $this->assertFalse($input['is_vendor']);
        $this->assertSame(14, $input['payment_due_default']);
    }

    public function testUnwrapPrefersNestedData(): void
    {
        $this->assertSame(5, ContactGateway::unwrap(['data' => ['id' => 5, 'company_name' => 'X']])['id']);
        $this->assertSame(8, ContactGateway::unwrap(['id' => 8])['id']);
    }
}
