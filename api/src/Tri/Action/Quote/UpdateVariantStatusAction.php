<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateVariantStatusAction
{
    private const ALLOWED = ['draft', 'sent', 'approved'];

    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, self::ALLOWED, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný stav.', 400);
        }
        $supplierId = TriRequest::supplierId($request);
        $before = $this->repo->findVariant((int) $args['id'], $supplierId);
        $variant = $this->repo->updateVariantStatus((int) $args['id'], $supplierId, $status);
        if ($variant === null) {
            return Json::error($response, 'not_found', 'Varianta nenalezena.', 404);
        }

        if ($before !== null && (string) $before['status'] !== $status) {
            $eventType = $status === 'approved' ? 'variant_approved' : 'variant_status_changed';
            $this->activity->event((int) $variant['job_id'], TriRequest::userId($request), $eventType, [
                'variant_code' => (string) $variant['variant_code'],
                'number'       => (string) $variant['number'],
                'from'         => (string) $before['status'],
                'to'           => $status,
            ]);
        }

        return Json::ok($response, $variant);
    }
}
