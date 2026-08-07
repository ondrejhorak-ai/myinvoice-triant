<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Tri;

use MyInvoice\Tri\Service\QuotePdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuotePdfRendererTest extends TestCase
{
    public function testStoredValidityTakesPrecedenceOverFallback(): void
    {
        $renderer = (new \ReflectionClass(QuotePdfRenderer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($renderer, 'resolveValidUntil');

        self::assertSame('2026-08-31', $method->invoke($renderer, [
            'job_date'   => '2026-08-01',
            'valid_until' => '2026-08-31',
        ]));
    }

    public function testValidityFallsBackToThirtyDaysFromIssueDate(): void
    {
        $renderer = (new \ReflectionClass(QuotePdfRenderer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($renderer, 'resolveValidUntil');

        self::assertSame('2026-09-05', $method->invoke($renderer, [
            'job_date'   => '2026-08-06',
            'valid_until' => null,
        ]));
    }

    #[DataProvider('validityDayCases')]
    public function testValidityDays(string $issuedAt, ?string $validUntil, ?int $expected): void
    {
        $renderer = (new \ReflectionClass(QuotePdfRenderer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($renderer, 'validityDays');

        self::assertSame($expected, $method->invoke($renderer, $issuedAt, $validUntil));
    }

    /** @return iterable<string, array{string, ?string, ?int}> */
    public static function validityDayCases(): iterable
    {
        yield 'thirty days' => ['2026-08-01', '2026-08-31', 30];
        yield 'past date' => ['2026-08-31', '2026-08-01', null];
        yield 'missing date' => ['', null, null];
    }

    #[DataProvider('imageDimensionCases')]
    public function testItemImageFitsInsideThirtySixMillimetres(int $width, int $height, array $expected): void
    {
        $renderer = (new \ReflectionClass(QuotePdfRenderer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($renderer, 'fitImageDimensions');

        self::assertSame($expected, $method->invoke($renderer, $width, $height));
    }

    /** @return iterable<string,array{int,int,array{float,float}}> */
    public static function imageDimensionCases(): iterable
    {
        yield 'landscape' => [640, 320, [36.0, 18.0]];
        yield 'portrait' => [320, 640, [18.0, 36.0]];
        yield 'square' => [640, 640, [36.0, 36.0]];
        yield 'small source is not enlarged beyond 300 DPI' => [100, 50, [8.47, 4.23]];
    }
}
