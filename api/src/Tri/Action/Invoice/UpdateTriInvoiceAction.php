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

final class UpdateTriInvoiceAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $gw = new InvoiceGateway($this->api, $this->db->pdo(), TriRequest::supplierId($request));
        try {
            $row = $gw->updateDraft((int) ($args['id'] ?? 0), (array) ($request->getParsedBody() ?? []));
        } catch (MyUctoApiException $e) {
            return InvoiceError::fromException($response, $e);
        }

        return Json::ok($response, $row);
    }
}
