<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\ComplaintRepository;
use MyInvoice\Tri\Service\ComplaintRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateComplaintCommentAction
{
    public function __construct(private readonly ComplaintRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $complaint = $this->repo->find((int) ($args['id'] ?? 0), TriRequest::supplierId($request));
        if ($complaint === null) {
            return Json::error($response, 'not_found', 'Reklamace nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $text = ComplaintRules::normalizeComment($body['body'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', ComplaintError::message($e->getMessage()), 400);
        }
        $item = $this->repo->addComment((int) $complaint['id'], TriRequest::userId($request), $text);

        return Json::ok($response, $item, 201);
    }
}
