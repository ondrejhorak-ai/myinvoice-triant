<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Activity;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobActivityRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** DELETE /api/tri/activity/{id} — soft-delete komentáře (autor nebo admin). */
final class DeleteJobCommentAction
{
    public function __construct(private readonly JobActivityRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }

        $id = (int) $args['id'];
        $item = $this->repo->findItem($id);
        if ($item === null || $item['kind'] !== 'comment' || (int) $item['supplier_id'] !== TriRequest::supplierId($request)) {
            return Json::error($response, 'not_found', 'Komentář nenalezen.', 404);
        }

        $isOwn = (int) $item['user_id'] === TriRequest::userId($request);
        if (!$isOwn && TriRequest::userRole($request) !== 'admin') {
            return Json::error($response, 'forbidden', 'Smazat lze jen vlastní komentář.', 403);
        }

        $this->repo->softDeleteComment($id);

        return Json::ok($response, ['deleted' => true]);
    }
}
