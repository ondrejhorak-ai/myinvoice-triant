<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Service\JobInvoiceBuilder;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateAdvanceInvoiceAction
{
    public function __construct(
        private readonly JobInvoiceBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $userId = TriRequest::userId($request);
        $jobId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->builder->buildAdvanceDraft($jobId, $supplierId, $userId, [
                'percent' => $body['percent'] ?? null,
                'amount'  => $body['amount'] ?? null,
                'text'    => $body['text'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return Json::error($response, 'create_failed', $e->getMessage(), 409);
        }

        $invoiceId = $result['invoice_id'];
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('tri.advance_invoice_created', $userId, 'invoice', $invoiceId, [
            'job_id' => $jobId,
        ], $ip, $request->getHeaderLine('User-Agent'));
        $this->activity->event($jobId, $userId, 'invoice_created', [
            'invoice_kind' => 'advance',
            'invoice_id'   => $invoiceId,
        ]);

        return Json::ok($response, [
            'invoice_id' => $invoiceId,
            'edit_url'   => "/tri/invoices/$invoiceId/edit",
            'invoice'    => $result['invoice'],
        ], 201);
    }
}
