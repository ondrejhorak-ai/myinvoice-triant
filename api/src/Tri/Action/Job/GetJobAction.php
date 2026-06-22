<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetJobAction
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly QuoteRepository $quotes,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $job = $this->jobs->find((int) $args['id'], $supplierId);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }
        $job['variants'] = $this->quotes->listVariantsForJob((int) $job['id'], $supplierId);

        return Json::ok($response, $job);
    }
}
