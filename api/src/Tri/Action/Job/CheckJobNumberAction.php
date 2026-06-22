<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CheckJobNumberAction
{
    public function __construct(private readonly JobRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $number = trim((string) ($q['number'] ?? ''));
        $excludeId = isset($q['exclude_id']) ? (int) $q['exclude_id'] : null;
        $supplierId = TriRequest::supplierId($request);
        $available = $number !== '' && $this->repo->isNumberAvailable($supplierId, $number, $excludeId);

        return Json::ok($response, ['available' => $available]);
    }
}
