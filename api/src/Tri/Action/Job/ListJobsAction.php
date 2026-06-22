<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListJobsAction
{
    public function __construct(private readonly JobRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $supplierId = TriRequest::supplierId($request);
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($q['per_page'] ?? 25)));
        $filters = [
            'q'      => isset($q['q']) ? trim((string) $q['q']) : '',
            'status' => isset($q['status']) ? (string) $q['status'] : '',
        ];

        return Json::ok($response, $this->repo->list($supplierId, $filters, $page, $perPage));
    }
}
