<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Cash;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CashLedgerRepository;
use MyInvoice\Tri\Service\CashLedgerRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateCashMovementAction
{
    public function __construct(private readonly CashLedgerRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        try {
            $data = CashLedgerRules::normalizeMovement((array) ($request->getParsedBody() ?? []));
            $updated = $this->repo->updateMovement((int) ($args['id'] ?? 0), $supplierId, $data);
        } catch (\InvalidArgumentException $e) {
            return self::fromRule($response, $e);
        }

        return Json::ok($response, $updated);
    }

    private static function fromRule(Response $response, \InvalidArgumentException $e): Response
    {
        return match ($e->getMessage()) {
            'NOT_FOUND' => Json::error($response, 'not_found', 'Pohyb nenalezen.', 404),
            'HANDED_OVER' => Json::error($response, 'handed_over', 'Pohyb je v předání a nejde upravit.', 409),
            default => Json::error($response, 'validation_failed', $e->getMessage(), 400),
        };
    }
}
