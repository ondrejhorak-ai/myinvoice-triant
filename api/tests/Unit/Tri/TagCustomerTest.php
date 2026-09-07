<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Tri\Repository\TagRepository;
use PHPUnit\Framework\TestCase;

final class TagCustomerTest extends TestCase
{
    public function testSlugifyTransliteratesDiacritics(): void
    {
        $this->assertSame('zakaznik', TagRepository::slugify('Zákazník'));
        $this->assertSame('architekt', TagRepository::slugify('Architekt'));
        $this->assertSame('dyhovani-a-brouseni', TagRepository::slugify('Dýhování a broušení'));
        $this->assertSame('rezie-vyroby', TagRepository::slugify('  Režie výroby  '));
        $this->assertSame('tag', TagRepository::slugify('***'));
    }

    public function testTaggedClientWinsInContactOrder(): void
    {
        $picked = TagRepository::pickCustomerClientId([5, 7, 9], [9, 7], [5, 7, 9]);
        $this->assertSame(7, $picked);
    }

    public function testFallsBackToIsCustomerFlagWhenNoTag(): void
    {
        $picked = TagRepository::pickCustomerClientId([5, 7], [], [7]);
        $this->assertSame(7, $picked);
    }

    public function testNullWhenNothingMatches(): void
    {
        $this->assertNull(TagRepository::pickCustomerClientId([5], [], []));
        $this->assertNull(TagRepository::pickCustomerClientId([], [1], [1]));
    }
}
