<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceJobAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly JobInvoiceRepository $jobInvoices,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($invoiceId);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $supplierId = TriRequest::supplierId($request);
        $job = $this->jobInvoices->jobForInvoiceScoped($invoiceId, $supplierId);

        return Json::ok($response, ['job' => $job]);
    }
}
