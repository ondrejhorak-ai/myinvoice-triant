<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Traveler;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TravelerRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GenerateTravelersAction
{
    public function __construct(private readonly TravelerRepository $repo) {}

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
            $result = $this->repo->generateForJob((int) ($args['id'] ?? 0), $supplierId, isset(((array)$request->getParsedBody())['line_id']) ? (int)((array)$request->getParsedBody())['line_id'] : null);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INVALID_LINE') return Json::error($response, 'invalid_line', 'Položka není výrobní položkou schválené varianty.', 422);
            if ($e->getMessage() === 'NOT_FOUND') {
                return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
            }
            if ($e->getMessage() === 'JOB_NOT_CONFIRMED') {
                return Json::error($response, 'job_not_confirmed', 'Průvodky lze vytvořit jen na potvrzené zakázce.', 400);
            }
            if ($e->getMessage() === 'NO_APPROVED_VARIANT') {
                return Json::error($response, 'no_approved_variant', 'Zakázka nemá schválenou variantu.', 400);
            }
            throw $e;
        }

        return Json::ok($response, $result);
    }
}
