<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateVariantAction
{
    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        try {
            $variant = $this->repo->createVariant((int) $args['job_id'], TriRequest::supplierId($request));
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'NO_VARIANT_CODE_AVAILABLE') {
                return Json::error($response, 'limit', 'Nelze vytvořit další variantu.', 400);
            }
            throw $e;
        }
        if ($variant === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $this->activity->event((int) $variant['job_id'], TriRequest::userId($request), 'variant_created', [
            'variant_code' => (string) $variant['variant_code'],
            'number'       => (string) $variant['number'],
        ]);

        return Json::ok($response, $variant, 201);
    }
}
