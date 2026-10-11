<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Invoice;

use MyInvoice\Tri\Invoice\JobInvoiceClient;
use PHPUnit\Framework\TestCase;

final class JobInvoiceClientTest extends TestCase
{
    public function testInvoiceClientIsUsedWhenTheJobHasNoCustomer(): void
    {
        $id = JobInvoiceClient::id(
            ['customer_client_id' => null],
            ['client_id' => 70],
        );

        $this->assertSame(70, $id);
    }

    public function testStoredJobCustomerWins(): void
    {
        $id = JobInvoiceClient::id(
            ['customer_client_id' => 9],
            ['client_id' => 70],
        );

        $this->assertSame(9, $id);
    }
}
