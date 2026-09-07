<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Core invoice write je vypnutý — master je MyÚčto přes /api/tri/invoices. */
final class CoreInvoiceWriteGoneAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        return Json::error(
            $response,
            'gone',
            'Faktury se zakládají a upravují v Triant office přes /api/tri/invoices.',
            410,
            ['use' => '/api/tri/invoices'],
        );
    }
}
