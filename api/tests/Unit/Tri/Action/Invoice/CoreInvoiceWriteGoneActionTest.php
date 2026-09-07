<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Action\Invoice;

use MyInvoice\Tri\Action\Invoice\CoreInvoiceWriteGoneAction;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CoreInvoiceWriteGoneActionTest extends TestCase
{
    public function testWriteReturns410WithTriPointer(): void
    {
        $action = new CoreInvoiceWriteGoneAction();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices');
        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(410, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('gone', $body['error']['code'] ?? null);
        self::assertSame('/api/tri/invoices', $body['error']['use'] ?? null);
    }
}
