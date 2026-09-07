<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Contact;

use MyInvoice\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Core client write je vypnutý — master je MyÚčto přes /api/tri/contacts. */
final class CoreClientWriteGoneAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        return Json::error(
            $response,
            'gone',
            'Kontakty se zakládají a upravují v Triant office přes /api/tri/contacts.',
            410,
            ['use' => '/api/tri/contacts'],
        );
    }
}
