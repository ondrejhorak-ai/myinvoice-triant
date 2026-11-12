<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Cash;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CashLedgerRepository;
use MyInvoice\Tri\Service\CashLedgerRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccountCashMovementsAction
{
    public function __construct(private readonly CashLedgerRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (TriRequest::userRole($request) !== 'admin') {
            return Json::error($response, 'forbidden', 'Hromadné zaúčtování smí jen správce.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $ids = CashLedgerRules::idList((array) ($body['ids'] ?? []));
            $updated = $this->repo->markAccounted($supplierId, TriRequest::userId($request), $ids);
        } catch (\InvalidArgumentException $e) {
            return CashErrors::from($e, $response);
        }

        return Json::ok($response, ['updated' => $updated]);
    }
}
