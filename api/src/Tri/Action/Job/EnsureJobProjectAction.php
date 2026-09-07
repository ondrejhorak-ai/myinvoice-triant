<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\ProjectGateway;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EnsureJobProjectAction
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly Connection $db,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }
        try {
            $job = (new ProjectGateway($this->api, $this->db->pdo(), $supplierId))->ensureProjectForJob($job);
        } catch (MyUctoApiException $e) {
            return \MyInvoice\Tri\Action\Invoice\InvoiceError::fromException($response, $e);
        }

        return Json::ok($response, [
            'myucto_project_id' => $job['myucto_project_id'] ?? null,
            'job' => $job,
        ]);
    }
}
