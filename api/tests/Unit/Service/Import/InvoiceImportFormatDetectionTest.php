<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceImportService;
use PHPUnit\Framework\TestCase;

/**
 * Detekce formátu importu (ISDOC vs Pohoda XML) + strip UTF-8 BOM.
 *
 * Regrese issue #39: iDoklad embeduje do PDF ISDOC XML s vedoucím UTF-8 BOM.
 * Stará detekce testovala `str_starts_with(ltrim($content), '<?xml')`, jenže ltrim
 * BOM neodstraní → soubor padal na Pohoda parser ("root není dataPack").
 *
 * Testuje private static metody přes reflexi (stejně jako PdfTotalExtractorTest).
 */
final class InvoiceImportFormatDetectionTest extends TestCase
{
    private const NS = 'http://isdoc.cz/namespace/2013';
    private const BOM = "\xEF\xBB\xBF";

    private function looksLikeIsdoc(string $name, string $content): bool
    {
        $ref = new \ReflectionMethod(InvoiceImportService::class, 'looksLikeIsdoc');
        return (bool) $ref->invoke(null, $name, $content);
    }

    private function stripBom(string $content): string
    {
        $ref = new \ReflectionMethod(InvoiceImportService::class, 'stripBom');
        return (string) $ref->invoke(null, $content);
    }

    public function testIdokladIsdocWithBomIsDetectedAsIsdoc(): void
    {
        // Přesně případ z issue #39: BOM + <?xml + ISDOC namespace.
        $content = self::BOM . '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<Invoice xmlns="' . self::NS . '"><ID>20260004</ID></Invoice>';
        self::assertTrue($this->looksLikeIsdoc('idoklad.pdf', $content));
    }

    public function testIsdocByExtension(): void
    {
        self::assertTrue($this->looksLikeIsdoc('faktura.isdoc', 'cokoliv'));
    }

    public function testIsdocByNamespaceWithoutXmlPrefix(): void
    {
        // I bez '<?xml' deklarace (jen root element) se pozná podle namespace.
        self::assertTrue($this->looksLikeIsdoc('x.xml', '<Invoice xmlns="' . self::NS . '"/>'));
    }

    public function testPohodaXmlIsNotIsdoc(): void
    {
        $pohoda = '<?xml version="1.0"?><dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"></dataPack>';
        self::assertFalse($this->looksLikeIsdoc('export.xml', $pohoda));
    }

    public function testStripBomRemovesLeadingBom(): void
    {
        self::assertSame('<?xml', $this->stripBom(self::BOM . '<?xml'));
    }

    public function testStripBomLeavesContentWithoutBomUntouched(): void
    {
        self::assertSame('<?xml', $this->stripBom('<?xml'));
        // BOM jen na začátku — uprostřed nech být
        self::assertSame("a" . self::BOM, $this->stripBom("a" . self::BOM));
    }
}
