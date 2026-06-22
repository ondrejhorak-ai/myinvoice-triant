<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Tag;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeleteTagAction
{
    public function __construct(private readonly TagRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $id = (int) $args['id'];
        if (!$this->repo->delete($id, $supplierId)) {
            return Json::error($response, 'not_found', 'Tag nenalezen.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }
}
