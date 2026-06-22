<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ApproveVariantAction
{
    public function __construct(private readonly QuoteRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $variant = $this->repo->approveVariant((int) $args['id'], TriRequest::supplierId($request));
        if ($variant === null) {
            return Json::error($response, 'not_found', 'Varianta nenalezena.', 404);
        }

        return Json::ok($response, $variant);
    }
}
