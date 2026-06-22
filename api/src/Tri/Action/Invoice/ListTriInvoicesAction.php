<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListTriInvoicesAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);

        $filters = [
            'q'           => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'      => $filter['status'] ?? null,
            'type'        => $filter['type'] ?? null,
            'client_id'   => $filter['client_id'] ?? null,
            'tri_job_id'  => $filter['tri_job_id'] ?? null,
            'year'        => $filter['year'] ?? null,
            'month'       => $filter['month'] ?? null,
            'date_from'   => $filter['date_from'] ?? null,
            'date_to'     => $filter['date_to'] ?? null,
            'currency'    => $filter['currency'] ?? null,
            'unpaid_only' => !empty($filter['unpaid_only']),
            'overdue'     => !empty($filter['overdue']),
            'supplier_id' => TriRequest::supplierId($request),
            'tri_columns' => true,
        ];

        foreach (['status', 'type'] as $f) {
            if (is_string($filters[$f]) && $filters[$f] !== '' && str_contains($filters[$f], ',')) {
                $filters[$f] = explode(',', $filters[$f]);
            }
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        return Json::ok($response, $this->repo->listGroupedByMonth($filters, $page, $perPage));
    }
}
