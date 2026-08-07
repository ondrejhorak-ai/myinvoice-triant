<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation;
use PHPUnit\Framework\TestCase;

final class ClientValidationTest extends TestCase
{
    public function testMinimalClientPasses(): void
    {
        $err = Validation::client([
            'company_name' => 'ACME s.r.o.',
            'street'       => '',
            'city'         => '',
            'zip'          => '',
            'main_email'   => '',
        ]);
        self::assertSame([], $err);
    }

    public function testMissingCompanyNameRejected(): void
    {
        $err = Validation::client([
            'company_name' => '',
            'main_email'   => 'info@acme.cz',
        ]);
        self::assertArrayHasKey('company_name', $err);
    }

    public function testInvalidEmailWhenProvidedRejected(): void
    {
        $err = Validation::client([
            'company_name' => 'ACME s.r.o.',
            'main_email'   => 'not-an-email',
        ]);
        self::assertArrayHasKey('main_email', $err);
    }

    public function testValidEmailPasses(): void
    {
        $err = Validation::client([
            'company_name' => 'ACME s.r.o.',
            'main_email'   => 'info@acme.cz',
        ]);
        self::assertSame([], $err);
    }
}
