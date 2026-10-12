<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Contact\BillingContactPublisher;
use MyInvoice\Tri\Contact\JobContactOrder;
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
            $customerId = JobContactOrder::billingClientId(is_array($job['contacts'] ?? null) ? $job['contacts'] : []);
            if ($customerId <= 0) {
                $customerId = (int) ($job['customer_client_id'] ?? 0);
            }
            if ($customerId > 0) {
                $job['customer_client_id'] = $customerId;
                (new BillingContactPublisher($this->api, $this->db->pdo(), $supplierId))->ensure($customerId);
            }
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
