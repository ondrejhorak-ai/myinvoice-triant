<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\PriceList;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\PriceListRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdatePriceListAction
{
    public function __construct(private readonly PriceListRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->repo->exists($id, $supplierId)) {
            return Json::error($response, 'not_found', 'Ceník nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'validation_failed', 'Název ceníku je povinný.', 400);
        }
        $note = isset($body['note']) ? trim((string) $body['note']) : '';
        $this->repo->update($id, $supplierId, $name, $note !== '' ? $note : null);
        if (array_key_exists('items', $body)) {
            if (!is_array($body['items'])) {
                return Json::error($response, 'validation_failed', 'Položky ceníku musí být seznam.', 400);
            }
            try {
                $this->repo->saveItems($id, $supplierId, $body['items']);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'INVALID_QUOTE_IMAGE') {
                    return Json::error($response, 'invalid_image', 'Vybraný obrázek není dostupný.', 400);
                }
                throw $e;
            }
        }

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
