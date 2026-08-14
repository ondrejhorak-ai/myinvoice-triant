<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\ComplaintRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetComplaintAction
{
    public function __construct(private readonly ComplaintRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $row = $this->repo->find((int) ($args['id'] ?? 0), TriRequest::supplierId($request));
        if ($row === null) {
            return Json::error($response, 'not_found', 'Reklamace nenalezena.', 404);
        }

        return Json::ok($response, $row);
    }
}
