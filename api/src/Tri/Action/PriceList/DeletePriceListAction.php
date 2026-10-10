<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\PriceList;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\PriceListRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeletePriceListAction
{
    public function __construct(private readonly PriceListRepository $repo, private readonly \MyInvoice\Tri\Repository\PricingRepository $pricing) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $list = $this->repo->find((int)($args['id']??0), TriRequest::supplierId($request));
        if ($list && $this->pricing->detail(TriRequest::supplierId($request), (int)$args['id'])['releases']) return Json::error($response, 'conflict', 'Ceník s vydáními nelze smazat.', 409);
        if (!$this->repo->delete((int) ($args['id'] ?? 0), TriRequest::supplierId($request))) {
            return Json::error($response, 'not_found', 'Ceník nenalezen.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }
}
