<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Tri\MyUcto\InvoiceSync;
use MyInvoice\Tri\MyUcto\MirrorWriter;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Support\TriRequest;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RefreshJobInvoicesAction
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobInvoiceRepository $jobInvoices,
        private readonly Connection $db,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $jobId = (int) ($args['id'] ?? 0);
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }
        $projectId = (int) ($job['myucto_project_id'] ?? 0);
        if ($projectId > 0) {
            try {
                $sync = new InvoiceSync($this->api, $this->db->pdo(), new MirrorWriter($this->db->pdo()));
                $sync->pullForProject($projectId);
            } catch (MyUctoApiException $e) {
                return InvoiceError::fromException($response, $e);
            }
        }

        return Json::ok($response, [
            'invoices' => $this->jobInvoices->listForJob($jobId, $supplierId),
            'summary' => $this->jobInvoices->summaryForJob($jobId, $supplierId),
        ]);
    }
}
