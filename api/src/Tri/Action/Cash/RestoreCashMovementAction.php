<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Cash;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CashLedgerRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RestoreCashMovementAction
{
    public function __construct(private readonly CashLedgerRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) !== 'admin') {
            return Json::error($response, 'forbidden', 'Koš pokladny smí obnovit jen správce.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        try {
            $restored = $this->repo->restoreMovement(
                (int) ($args['id'] ?? 0),
                $supplierId,
                TriRequest::userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return CashErrors::from($e, $response);
        }

        return Json::ok($response, $restored);
    }
}
