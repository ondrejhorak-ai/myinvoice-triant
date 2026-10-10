<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tri;
use MyInvoice\Tri\Service\PriceReleaseRules;
use PHPUnit\Framework\TestCase;

final class PriceReleaseRulesTest extends TestCase
{
    public function testMissingPriceIsDifferentFromZero(): void
    {
        self::assertNull(PriceReleaseRules::money(''));
        self::assertSame('0.00',PriceReleaseRules::money(0));
        self::assertSame('120.50',PriceReleaseRules::money('120.5'));
    }
    public function testRejectsNonFinitePrice(): void
    {
        $this->expectException(\DomainException::class);
        PriceReleaseRules::money(INF);
    }
    public function testRejectsImpossibleDate(): void
    {
        $this->expectException(\DomainException::class);
        PriceReleaseRules::date('2026-02-30');
    }
    public function testCurrentReleaseCanBeSuperseded(): void
    {
        PriceReleaseRules::publication(['valid_from'=>'2026-10-01'],[['valid_from'=>'2026-09-01','valid_until'=>null]],'2026-09-21');
        $this->addToAssertionCount(1);
    }
    public function testScheduledReleaseCannotBeSilentlyOverwritten(): void
    {
        $this->expectException(\DomainException::class);
        PriceReleaseRules::publication(['valid_from'=>'2026-10-01'],[['valid_from'=>'2026-10-15','valid_until'=>null]],'2026-09-21');
    }
    public function testPastPublicationIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        PriceReleaseRules::publication(['valid_from'=>'2026-09-20'],[],'2026-09-21');
    }
}
