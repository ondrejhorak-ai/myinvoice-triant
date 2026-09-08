<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Static source-level guards — NEexercují runtime, jen čtou zdrojový kód a hlídají,
 * že konkrétní call-sity používají správnou API. Pomalu degradují (každý reformat
 * je rozbije), ale chytí regresi typu „někdo přepsal canBeMarkedPaid zpátky na
 * hasPositiveAmountToPay". Pro skutečnou behavioral coverage viz odpovídající
 * unit testy v tests/Unit/Service/Validation/.
 */
final class InvoiceAmountSourceGuardsTest extends TestCase
{
    public function testInvoiceListsRenderZeroAmountToPayWithoutFallbackToTotal(): void
    {
        // Regrese: amount_to_pay = 0 nesmí padnout na total_with_vat (zmátlo by
        // uživatele u finálního daňového dokladu k záloze). `??` je správně, `||` špatně.
        // Core stránky (invoices/projects/clients) byly smazané — guard hlídá TRI ekvivalenty.
        $files = [
            '/web/src/pages/tri/invoices/InvoiceList.vue',
            '/web/src/pages/tri/contacts/ContactDetail.vue',
        ];
        $root = dirname(__DIR__, 3);

        foreach ($files as $rel) {
            $code = file_get_contents($root . $rel);
            self::assertIsString($code, "Nenalezen $rel");
            self::assertStringNotContainsString(
                'formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency)',
                $code,
                "$rel stále používá `||` fallback — nahraď za `??`."
            );
            self::assertStringContainsString(
                'formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency)',
                $code,
                "$rel nepoužívá očekávaný `??` fallback pro amount_to_pay."
            );
        }
    }
}
