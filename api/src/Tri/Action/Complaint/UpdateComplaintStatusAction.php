<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\ComplaintRepository;
use MyInvoice\Tri\Service\ComplaintRules;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateComplaintStatusAction
{
    public function __construct(
        private readonly ComplaintRepository $repo,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $current = $this->repo->find($id, $supplierId);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Reklamace nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $next = ComplaintRules::applyStatus((string) ($body['status'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', ComplaintError::message($e->getMessage()), 400);
        }
        $updated = $this->repo->updateStatus($id, $supplierId, $next);
        assert($updated !== null);
        $event = ComplaintRules::eventTypeForTransition((string) $current['status'], $next['status']);
        if ($event !== null) {
            $this->activity->event((int) $updated['job_id'], TriRequest::userId($request), $event, [
                'complaint_id' => $updated['id'],
                'title'        => $updated['title'],
            ]);
        }

        return Json::ok($response, $updated);
    }
}
