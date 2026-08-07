<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Tri;

use MyInvoice\Tri\Service\QuoteImageException;
use MyInvoice\Tri\Service\QuoteImageService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteImageServiceTest extends TestCase
{
    private QuoteImageService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Test vyžaduje GD.');
        }
        $this->service = (new \ReflectionClass(QuoteImageService::class))->newInstanceWithoutConstructor();
    }

    #[DataProvider('formats')]
    public function testSupportedFormatsAreNormalisedToSmallJpeg(string $format): void
    {
        if ($format === 'webp' && !function_exists('imagewebp')) {
            self::markTestSkipped('GD nemá podporu WebP.');
        }
        $path = $this->temporaryPath('.' . $format);
        $image = imagecreatetruecolor(1200, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 160));
        match ($format) {
            'jpg'  => imagejpeg($image, $path, 92),
            'png'  => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 90),
            'gif'  => imagegif($image, $path),
        };
        unset($image);

        try {
            $result = $this->service->normaliseUploadedFile($path);
            self::assertSame(640, $result['width']);
            self::assertSame(320, $result['height']);
            self::assertLessThanOrEqual(QuoteImageService::MAX_OUTPUT_BYTES, strlen($result['bytes']));
            $info = getimagesizefromstring($result['bytes']);
            self::assertIsArray($info);
            self::assertSame(IMAGETYPE_JPEG, $info[2]);
        } finally {
            @unlink($path);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function formats(): iterable
    {
        yield 'JPEG' => ['jpg'];
        yield 'PNG' => ['png'];
        yield 'WebP' => ['webp'];
        yield 'GIF' => ['gif'];
    }

    public function testInvalidFileIsRejectedBeforeDatabaseAccess(): void
    {
        $path = $this->temporaryPath('.txt');
        file_put_contents($path, 'not an image');
        try {
            $this->expectException(QuoteImageException::class);
            $this->expectExceptionMessage('platný obrázek');
            $this->service->normaliseUploadedFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testOversizedUploadIsRejected(): void
    {
        $path = $this->temporaryPath('.jpg');
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        ftruncate($handle, QuoteImageService::MAX_INPUT_BYTES + 1);
        fclose($handle);
        try {
            $this->expectException(QuoteImageException::class);
            $this->expectExceptionMessage('10 MiB');
            $this->service->normaliseUploadedFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testPixelBombIsRejectedBeforeDecode(): void
    {
        $path = $this->temporaryPath('.png');
        $ihdr = pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0);
        $chunk = 'IHDR' . $ihdr;
        $png = "\x89PNG\r\n\x1a\n" . pack('N', strlen($ihdr)) . $chunk
            . pack('N', crc32($chunk));
        file_put_contents($path, $png);
        try {
            $this->expectException(QuoteImageException::class);
            $this->expectExceptionMessage('velké rozměry');
            $this->service->normaliseUploadedFile($path);
        } finally {
            @unlink($path);
        }
    }

    private function temporaryPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/quote-image-' . bin2hex(random_bytes(8)) . $suffix;
    }
}
