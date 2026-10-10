<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Contact;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tri\Contact\OfficeContactStore;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SuggestContactsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $clients,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $q = $request->getQueryParams();
        $store = new OfficeContactStore($this->db->pdo(), $this->clients, $supplierId);
        $rows = $store->suggest(
            (string) ($q['q'] ?? ''),
            (string) ($q['email'] ?? ''),
            (string) ($q['phone'] ?? ''),
            (string) ($q['ic'] ?? ''),
            (int) ($q['exclude_id'] ?? 0),
        );

        return Json::ok($response, ['data' => $rows]);
    }
}
