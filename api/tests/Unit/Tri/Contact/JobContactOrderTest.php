<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Contact;

use MyInvoice\Tri\Contact\JobContactOrder;
use PHPUnit\Framework\TestCase;

final class JobContactOrderTest extends TestCase
{
    public function testPersonOnlyUsesTheFirstPersonName(): void
    {
        $label = JobContactOrder::label([
            ['client_id' => 70, 'party_kind' => 'person', 'company_name' => 'E2E-dva Osoba', 'first_name' => null, 'last_name' => null],
            ['client_id' => 75, 'party_kind' => 'person', 'company_name' => 'E2E-davka Nová', 'first_name' => null, 'last_name' => null],
        ]);

        $this->assertSame('E2E-dva Osoba', $label);
    }

    public function testCompanyOnlyUsesTheFirstCompany(): void
    {
        $label = JobContactOrder::label([
            ['client_id' => 40, 'party_kind' => 'company', 'company_name' => 'Ing. Klára Jeřábková'],
            ['client_id' => 38, 'party_kind' => 'company', 'company_name' => 'Interiéry Malinová s.r.o.'],
        ]);

        $this->assertSame('Ing. Klára Jeřábková', $label);
    }

    public function testPersonAndCompanyJoinInContactOrder(): void
    {
        $label = JobContactOrder::label([
            ['client_id' => 8, 'party_kind' => 'company', 'company_name' => 'TRIANT, spol. s r.o.'],
            ['client_id' => 3, 'party_kind' => 'person', 'company_name' => 'Karta', 'first_name' => 'Jana', 'last_name' => 'Eberlová'],
            ['client_id' => 9, 'party_kind' => 'person', 'company_name' => 'Další', 'first_name' => 'Petr', 'last_name' => 'Novák'],
        ]);

        $this->assertSame('Jana Eberlová, TRIANT, spol. s r.o.', $label);
        $this->assertSame(8, JobContactOrder::billingClientId([
            ['client_id' => 3, 'party_kind' => 'person', 'company_name' => 'Jana'],
            ['client_id' => 8, 'party_kind' => 'company', 'company_name' => 'TRIANT, spol. s r.o.'],
        ]));
    }

    public function testWithoutACompanyTheFirstPersonIsTheCustomer(): void
    {
        $id = JobContactOrder::billingClientId([
            ['client_id' => 3, 'party_kind' => 'person', 'company_client_id' => 8, 'company_name' => 'Osoba u firmy'],
        ]);

        $this->assertSame(3, $id);
    }

    public function testNoContactsHaveNoLabel(): void
    {
        $this->assertNull(JobContactOrder::label([]));
        $this->assertSame(0, JobContactOrder::billingClientId([]));
    }
}
