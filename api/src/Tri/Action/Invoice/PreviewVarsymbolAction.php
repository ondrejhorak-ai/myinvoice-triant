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

final class PreviewVarsymbolAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $gw = new InvoiceGateway($this->api, $this->db->pdo(), TriRequest::supplierId($request));
        $query = [];
        if (!empty($q['type'])) {
            $query['invoice_type'] = (string) $q['type'];
        }
        if (!empty($q['issue_date'])) {
            $query['issue_date'] = (string) $q['issue_date'];
        }
        if (!empty($q['client_id'])) {
            $query['client_id'] = (int) $q['client_id'];
        }
        try {
            $data = $gw->previewVarsymbol($query);
        } catch (MyUctoApiException $e) {
            return InvoiceError::fromException($response, $e);
        }

        return Json::ok($response, $data);
    }
}
