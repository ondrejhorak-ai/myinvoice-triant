<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Cash;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CashLedgerRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListCashAction
{
    public function __construct(private readonly CashLedgerRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($q['per_page'] ?? 30)));

        return Json::ok($response, $this->repo->list($supplierId, [
            'q' => isset($q['q']) ? trim((string) $q['q']) : '',
            'kind' => isset($q['kind']) ? (string) $q['kind'] : '',
            'handed' => isset($q['handed']) ? (string) $q['handed'] : 'all',
        ], $page, $perPage));
    }
}
