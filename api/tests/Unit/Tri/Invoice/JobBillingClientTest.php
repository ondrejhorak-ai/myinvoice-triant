<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Invoice;

use MyInvoice\Tri\Service\JobInvoiceBuilder;
use PHPUnit\Framework\TestCase;

final class JobBillingClientTest extends TestCase
{
    public function testStoredCustomerWins(): void
    {
        $id = JobInvoiceBuilder::billingClientId([
            'customer_client_id' => 9,
            'contacts' => [['client_id' => 4, 'role' => 'customer', 'party_kind' => 'company']],
        ]);

        $this->assertSame(9, $id);
    }

    public function testBillableJobContactIsUsedWhenTheJobHasNoCustomer(): void
    {
        $id = JobInvoiceBuilder::billingClientId([
            'customer_client_id' => null,
            'contacts' => [
                ['client_id' => 3, 'role' => 'contact', 'party_kind' => 'person', 'company_client_id' => 8],
                ['client_id' => 8, 'role' => 'customer', 'party_kind' => 'company', 'company_client_id' => null],
            ],
        ]);

        $this->assertSame(8, $id);
    }

    public function testPersonLinkedToACompanyCarriesThatCompany(): void
    {
        $id = JobInvoiceBuilder::billingClientId([
            'customer_client_id' => null,
            'contacts' => [
                ['client_id' => 3, 'role' => 'contact', 'party_kind' => 'person', 'company_client_id' => 8],
            ],
        ]);

        $this->assertSame(8, $id);
    }
}
