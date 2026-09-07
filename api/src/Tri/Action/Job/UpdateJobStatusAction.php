<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\ProjectGateway;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateJobStatusAction
{
    private const ALLOWED = ['active', 'confirmed', 'rejected', 'completed'];

    public function __construct(
        private readonly JobRepository $repo,
        private readonly JobActivityLogger $activity,
        private readonly Connection $db,
        private readonly MyUctoClient $api,
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
        $id = (int) $args['id'];
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $gw = new ProjectGateway($this->api, $this->db->pdo(), $supplierId);
        if (in_array($status, ['confirmed', 'active'], true) && !empty($existing['customer_client_id'])) {
            try {
                $existing = $gw->ensureProjectForJob($existing);
            } catch (MyUctoApiException $e) {
                return \MyInvoice\Tri\Action\Invoice\InvoiceError::fromException($response, $e);
            }
        }

        if ((string) $existing['status'] !== $status) {
            if (!$this->repo->updateStatus($id, $supplierId, $status)) {
                return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
            }
            $this->activity->event($id, TriRequest::userId($request), 'status_changed', [
                'from' => (string) $existing['status'],
                'to' => $status,
            ]);
        }

        if (in_array($status, ['completed', 'rejected'], true)) {
            $fresh = $this->repo->find($id, $supplierId) ?? $existing;
            try {
                $gw->closeProjectForJob($fresh);
            } catch (MyUctoApiException) {
                // Stav zakázky už je uložený; uzavření projektu doplní další sync / tlačítko.
            }
        }

        return Json::ok($response, [
            'status' => $status,
            'myucto_project_id' => $existing['myucto_project_id'] ?? null,
        ]);
    }
}
