<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\ComplaintRepository;
use MyInvoice\Tri\Service\ComplaintRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListComplaintsAction
{
    public function __construct(private readonly ComplaintRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args = []): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $params = $request->getQueryParams();
        $jobId = isset($args['id']) && $args['id'] !== ''
            ? (int) $args['id']
            : (isset($params['job_id']) && $params['job_id'] !== '' ? (int) $params['job_id'] : null);
        $status = isset($params['status']) ? trim((string) $params['status']) : null;
        if ($status !== null && $status !== '' && !in_array($status, ComplaintRules::STATUSES, true)) {
            return Json::error($response, 'validation_failed', ComplaintError::message('INVALID_STATUS'), 400);
        }

        return Json::ok($response, $this->repo->listForSupplier($supplierId, $jobId, $status !== '' ? $status : null));
    }
}
