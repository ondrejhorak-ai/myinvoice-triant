<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceJobAction
{
    public function __construct(
        private readonly JobInvoiceRepository $jobInvoices,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $job = $this->jobInvoices->jobForInvoiceScoped((int) ($args['id'] ?? 0), $supplierId);

        return Json::ok($response, ['job' => $job]);
    }
}
