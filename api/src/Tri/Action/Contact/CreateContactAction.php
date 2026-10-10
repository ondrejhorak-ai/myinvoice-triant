<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Contact;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tri\Contact\ContactValidationException;
use MyInvoice\Tri\Contact\OfficeContactStore;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateContactAction
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
        $store = new OfficeContactStore($this->db->pdo(), $this->clients, $supplierId);
        try {
            $row = $store->create((array) ($request->getParsedBody() ?? []));
        } catch (ContactValidationException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422, ['fields' => $e->fields]);
        }

        return Json::ok($response, $row, 201);
    }
}
