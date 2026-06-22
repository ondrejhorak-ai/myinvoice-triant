<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Tag;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateTagAction
{
    public function __construct(private readonly TagRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'validation_failed', 'Název tagu je povinný.', 400);
        }
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Tag nenalezen.', 404);
        }
        $this->repo->update($id, $supplierId, $name, (string) ($body['color'] ?? '#6366f1'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
