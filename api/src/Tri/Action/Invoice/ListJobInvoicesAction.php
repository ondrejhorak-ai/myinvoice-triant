<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListJobInvoicesAction
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobInvoiceRepository $jobInvoices,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $jobId = (int) ($args['id'] ?? 0);

        if ($this->jobs->find($jobId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        return Json::ok($response, [
            'invoices' => $this->jobInvoices->listForJob($jobId, $supplierId),
            'summary'  => $this->jobInvoices->summaryForJob($jobId, $supplierId),
        ]);
    }
}
