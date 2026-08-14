<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\ComplaintRepository;
use MyInvoice\Tri\Service\ComplaintRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateComplaintAction
{
    public function __construct(private readonly ComplaintRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        try {
            $data = ComplaintRules::normalizeUpdate((array) ($request->getParsedBody() ?? []));
            $updated = $this->repo->update((int) ($args['id'] ?? 0), TriRequest::supplierId($request), $data);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', ComplaintError::message($e->getMessage()), 400);
        }
        if ($updated === null) {
            return Json::error($response, 'not_found', 'Reklamace nenalezena.', 404);
        }

        return Json::ok($response, $updated);
    }
}
