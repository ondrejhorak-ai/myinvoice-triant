<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\ComplaintRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeleteComplaintCommentAction
{
    public function __construct(private readonly ComplaintRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $item = $this->repo->findComment((int) ($args['id'] ?? 0));
        if ($item === null || (int) $item['supplier_id'] !== TriRequest::supplierId($request)) {
            return Json::error($response, 'not_found', 'Komentář nenalezen.', 404);
        }
        $isOwn = (int) $item['user_id'] === TriRequest::userId($request);
        if (!$isOwn && TriRequest::userRole($request) !== 'admin') {
            return Json::error($response, 'forbidden', 'Smazat lze jen vlastní komentář.', 403);
        }
        $this->repo->deleteComment((int) $item['id']);

        return Json::ok($response, ['deleted' => true]);
    }
}
