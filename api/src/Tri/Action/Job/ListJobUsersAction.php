<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListJobUsersAction
{
    public function __construct(private readonly JobRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::ok($response, ['data' => $this->repo->listActiveUsers()]);
    }
}
