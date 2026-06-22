<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ArchiveJobAction
{
    public function __construct(private readonly JobRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        if (!$this->repo->archive((int) $args['id'], TriRequest::supplierId($request))) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        return Json::ok($response, ['archived' => true]);
    }
}
