<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\PriceList;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\PriceListRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListPriceListsAction
{
    public function __construct(private readonly PriceListRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));

        return Json::ok($response, $this->repo->listForSupplier($supplierId, $q));
    }
}
