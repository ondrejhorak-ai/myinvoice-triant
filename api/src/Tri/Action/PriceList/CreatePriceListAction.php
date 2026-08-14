<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\PriceList;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\PriceListRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreatePriceListAction
{
    public function __construct(private readonly PriceListRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'validation_failed', 'Název ceníku je povinný.', 400);
        }
        $note = isset($body['note']) ? trim((string) $body['note']) : '';
        $id = $this->repo->create($supplierId, $name, $note !== '' ? $note : null);
        if (isset($body['items']) && is_array($body['items'])) {
            try {
                $this->repo->saveItems($id, $supplierId, $body['items']);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'INVALID_QUOTE_IMAGE') {
                    return Json::error($response, 'invalid_image', 'Vybraný obrázek není dostupný.', 400);
                }
                throw $e;
            }
        }
        $created = $this->repo->find($id, $supplierId);

        return Json::ok($response, $created, 201);
    }
}
