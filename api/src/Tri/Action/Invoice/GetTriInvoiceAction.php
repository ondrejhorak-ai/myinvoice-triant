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

final class GetTriInvoiceAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $gw = new InvoiceGateway($this->api, $this->db->pdo(), TriRequest::supplierId($request));
        try {
            $row = $gw->getDetail((int) ($args['id'] ?? 0));
        } catch (MyUctoApiException $e) {
            return InvoiceError::fromException($response, $e);
        }

        return Json::ok($response, $row);
    }
}
