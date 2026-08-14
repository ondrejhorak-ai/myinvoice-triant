<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Traveler;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TravelerRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetTravelerAction
{
    public function __construct(private readonly TravelerRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traveler = $this->repo->find((int) ($args['id'] ?? 0), TriRequest::supplierId($request));
        if ($traveler === null) {
            return Json::error($response, 'not_found', 'Průvodka nenalezena.', 404);
        }

        return Json::ok($response, $traveler);
    }
}
