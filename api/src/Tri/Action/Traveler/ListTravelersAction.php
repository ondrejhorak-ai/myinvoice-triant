<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Traveler;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TravelerRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListTravelersAction
{
    public function __construct(private readonly TravelerRepository $repo) {}

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

        return Json::ok($response, $this->repo->listForSupplier($supplierId, $jobId, $status));
    }
}
