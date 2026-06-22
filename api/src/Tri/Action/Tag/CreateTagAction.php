<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Tag;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateTagAction
{
    public function __construct(private readonly TagRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'validation_failed', 'Název tagu je povinný.', 400);
        }
        $id = $this->repo->create($supplierId, $name, isset($body['color']) ? (string) $body['color'] : null);

        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }
}
