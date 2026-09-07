<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\InvoiceGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceMetaAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $gw = new InvoiceGateway($this->api, $this->db->pdo(), $supplierId);

        return Json::ok($response, $gw->meta());
    }
}
