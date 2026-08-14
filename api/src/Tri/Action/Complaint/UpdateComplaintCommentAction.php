<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\ComplaintRepository;
use MyInvoice\Tri\Service\ComplaintRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateComplaintCommentAction
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
        if ((int) $item['user_id'] !== TriRequest::userId($request)) {
            return Json::error($response, 'forbidden', 'Upravit lze jen vlastní komentář.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $text = ComplaintRules::normalizeComment($body['body'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', ComplaintError::message($e->getMessage()), 400);
        }
        $updated = $this->repo->updateComment((int) $item['id'], $text);
        assert($updated !== null);
        unset($updated['supplier_id']);

        return Json::ok($response, $updated);
    }
}
