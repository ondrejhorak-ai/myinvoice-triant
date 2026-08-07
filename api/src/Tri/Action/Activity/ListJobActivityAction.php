<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Activity;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobActivityRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/tri/jobs/{id}/activity — feed komentářů a událostí zakázky.
 * Query: before_id (donačítání starší historie), after_id (polling nových), limit.
 */
final class ListJobActivityAction
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(private readonly JobActivityRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $jobId = (int) $args['id'];
        $supplierId = TriRequest::supplierId($request);
        if (!$this->repo->jobExists($jobId, $supplierId)) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $query = $request->getQueryParams();
        $beforeId = isset($query['before_id']) && is_numeric($query['before_id']) ? (int) $query['before_id'] : null;
        $afterId = isset($query['after_id']) && is_numeric($query['after_id']) ? (int) $query['after_id'] : null;
        $limit = isset($query['limit']) && is_numeric($query['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $query['limit']))
            : self::DEFAULT_LIMIT;

        $result = $this->repo->listForJob($jobId, $beforeId, $afterId, $limit);

        return Json::ok($response, $result);
    }
}
