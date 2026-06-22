<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Service\JobNumberService;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SuggestJobNumberAction
{
    public function __construct(private readonly JobNumberService $numbers) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $yearSuffix = $this->numbers->getYearSuffix();

        return Json::ok($response, [
            'suggested' => $this->numbers->peekNext($supplierId, $yearSuffix),
            'year_suffix' => $yearSuffix,
        ]);
    }
}
