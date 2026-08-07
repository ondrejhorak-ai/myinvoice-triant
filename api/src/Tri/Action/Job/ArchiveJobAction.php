<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ArchiveJobAction
{
    public function __construct(
        private readonly JobRepository $repo,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $id = (int) $args['id'];
        if (!$this->repo->archive($id, TriRequest::supplierId($request))) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $this->activity->event($id, TriRequest::userId($request), 'job_archived');

        return Json::ok($response, ['archived' => true]);
    }
}
