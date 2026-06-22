<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SetInvoiceJobAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly JobInvoiceRepository $jobInvoices,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($invoiceId);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $supplierId = TriRequest::supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $jobId = $body['job_id'] ?? null;

        try {
            if ($jobId === null || $jobId === '') {
                $this->jobInvoices->unlink($invoiceId, $supplierId);
            } else {
                $this->jobInvoices->link($invoiceId, (int) $jobId, $supplierId);
            }
        } catch (\Throwable $e) {
            return Json::error($response, 'link_failed', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('tri.invoice_job_linked', (int) ($user['id'] ?? 0), 'invoice', $invoiceId, [
            'job_id' => $jobId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $job = $this->jobInvoices->jobForInvoiceScoped($invoiceId, $supplierId);

        return Json::ok($response, ['job' => $job]);
    }
}
