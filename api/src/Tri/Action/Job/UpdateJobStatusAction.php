<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateJobStatusAction
{
    private const ALLOWED = ['active', 'confirmed', 'rejected', 'completed'];

    public function __construct(private readonly JobRepository $repo) {}

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
        $id = (int) $args['id'];
        if (!$this->repo->updateStatus($id, $supplierId, $status)) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        return Json::ok($response, ['status' => $status]);
    }
}
