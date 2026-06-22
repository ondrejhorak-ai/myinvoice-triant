<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Tag;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListTagsAction
{
    public function __construct(private readonly TagRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        return Json::ok($response, ['data' => $this->repo->listForSupplier($supplierId)]);
    }
}
