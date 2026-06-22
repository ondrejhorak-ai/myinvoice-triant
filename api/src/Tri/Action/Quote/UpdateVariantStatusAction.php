<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateVariantStatusAction
{
    private const ALLOWED = ['draft', 'sent', 'approved'];

    public function __construct(private readonly QuoteRepository $repo) {}

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
        $variant = $this->repo->updateVariantStatus(
            (int) $args['id'],
            TriRequest::supplierId($request),
            $status,
        );
        if ($variant === null) {
            return Json::error($response, 'not_found', 'Varianta nenalezena.', 404);
        }

        return Json::ok($response, $variant);
    }
}
