<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Invoice;

use MyInvoice\Tri\Action\Invoice\InvoiceError;
use MyInvoice\Tri\Service\JobInvoiceBuilder;
use PHPUnit\Framework\TestCase;

final class JobBillingClientTest extends TestCase
{
    public function testFirstCompanyWinsOverAStoredCustomerAndAnEarlierPerson(): void
    {
        $id = JobInvoiceBuilder::billingClientId([
            'customer_client_id' => 9,
            'contacts' => [
                ['client_id' => 3, 'role' => 'contact', 'party_kind' => 'person', 'company_client_id' => 8],
                ['client_id' => 8, 'role' => 'customer', 'party_kind' => 'company', 'company_client_id' => null],
            ],
        ]);

        $this->assertSame(8, $id);
    }

    public function testFirstPersonIsUsedWhenTheJobHasNoCompany(): void
    {
        $id = JobInvoiceBuilder::billingClientId([
            'customer_client_id' => 9,
            'contacts' => [
                ['client_id' => 3, 'role' => 'contact', 'party_kind' => 'person', 'company_client_id' => 8],
            ],
        ]);

        $this->assertSame(3, $id);
    }

    public function testStoredCustomerRemainsWhenTheJobHasNoContacts(): void
    {
        $id = JobInvoiceBuilder::billingClientId([
            'customer_client_id' => 9,
            'contacts' => [],
        ]);

        $this->assertSame(9, $id);
    }

    public function testChosenContactWinsOverTheFirstCompany(): void
    {
        $id = JobInvoiceBuilder::resolveBillingClientId([
            'customer_client_id' => 9,
            'contacts' => [
                ['client_id' => 8, 'party_kind' => 'company'],
            ],
        ], 55);

        $this->assertSame(55, $id);
    }

    public function testProjectStaysWhenItBelongsToTheChosenContact(): void
    {
        $this->assertSame(47, JobInvoiceBuilder::projectIdForBilling(47, 55, 55));
    }

    public function testProjectIsOmittedWhenItBelongsToSomeoneElse(): void
    {
        $this->assertSame(0, JobInvoiceBuilder::projectIdForBilling(47, 12, 55));
        $this->assertSame(0, JobInvoiceBuilder::projectIdForBilling(47, 0, 55));
        $this->assertSame(0, JobInvoiceBuilder::projectIdForBilling(0, 12, 55));
    }

    public function testAdvanceAndFinalOmitAProjectThatDoesNotMatch(): void
    {
        $advance = JobInvoiceBuilder::advanceInput(55, 0, '260018', '2026-10-26', '2026-10-12', 'Záloha', 100.0, 3);
        $this->assertArrayNotHasKey('project_id', $advance);
        $this->assertSame(55, $advance['client_id']);

        $final = JobInvoiceBuilder::finalInput(55, 0, '260018', '2026-10-12', '2026-10-26', [], 0.0, null, null);
        $this->assertArrayNotHasKey('project_id', $final);
        $this->assertSame(55, $final['client_id']);
    }

    public function testProjectMismatchMessageHidesRecordIds(): void
    {
        $plain = InvoiceError::withoutRecordIds('Zakázka #47 nepatří klientovi #55.');

        $this->assertStringNotContainsString('#', $plain);
        $this->assertStringNotContainsString('47', $plain);
        $this->assertStringNotContainsString('55', $plain);
    }
}
