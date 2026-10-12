<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tri\Contact\BillingContactPublisher;
use MyInvoice\Tri\Invoice\JobInvoiceClient;
use MyInvoice\Tri\MyUcto\InvoiceGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\ProjectGateway;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SetInvoiceJobAction
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobInvoiceRepository $jobInvoices,
        private readonly Connection $db,
        private readonly MyUctoClient $api,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $invoiceId = (int) ($args['id'] ?? 0);
        $supplierId = TriRequest::supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $jobId = $body['job_id'] ?? null;
        $previousJob = $this->jobInvoices->jobForInvoiceScoped($invoiceId, $supplierId);
        $gw = new InvoiceGateway($this->api, $this->db->pdo(), $supplierId);

        $detail = [];
        try {
            $detail = $gw->getDetail($invoiceId);
            $isDraft = ($detail['status'] ?? '') === 'draft';
            if ($jobId === null || $jobId === '') {
                if ($isDraft) {
                    $gw->updateDraft($invoiceId, array_merge($detail, ['project_id' => null, 'items' => $detail['items'] ?? []]));
                }
                $this->jobInvoices->unlink($invoiceId, $supplierId);
            } else {
                $job = $this->jobs->find((int) $jobId, $supplierId);
                if ($job === null) {
                    return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
                }
                $projectId = 0;
                if ($isDraft) {
                    $billingId = JobInvoiceClient::id($job, $detail);
                    $billing = $billingId > 0 ? $this->clientRow($supplierId, $billingId) : null;
                    if ($billing !== null && (int) ($job['customer_client_id'] ?? 0) > 0 && $billing['myucto_id'] <= 0) {
                        (new BillingContactPublisher($this->api, $this->db->pdo(), $supplierId))->ensure($billing['id']);
                        $billing = $this->clientRow($supplierId, $billing['id']) ?? $billing;
                    }
                    $projectId = (int) ($job['myucto_project_id'] ?? 0);
                    if ($projectId <= 0 && $billing !== null && $billing['myucto_id'] > 0) {
                        $forProject = $job;
                        $forProject['customer_client_id'] = $billing['id'];
                        try {
                            $job = (new ProjectGateway($this->api, $this->db->pdo(), $supplierId))->ensureProjectForJob($forProject);
                            $projectId = (int) ($job['myucto_project_id'] ?? 0);
                        } catch (MyUctoApiException $e) {
                            if ($e->errorCode !== 'not_linked') {
                                throw $e;
                            }
                        }
                    }
                }
                $this->jobInvoices->link($invoiceId, (int) $jobId, $supplierId);
                if ($isDraft && $projectId > 0) {
                    $gw->updateDraft($invoiceId, array_merge($detail, [
                        'project_id' => $projectId,
                        'items' => $detail['items'] ?? [],
                    ]));
                }
            }
        } catch (MyUctoApiException $e) {
            return InvoiceError::fromException($response, $e);
        } catch (\Throwable $e) {
            return Json::error($response, 'link_failed', $e->getMessage(), 409);
        }

        $userId = TriRequest::userId($request);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('tri.invoice_job_linked', $userId, 'invoice', $invoiceId, [
            'job_id' => $jobId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $varsymbol = isset($detail['varsymbol']) && $detail['varsymbol'] !== null ? (string) $detail['varsymbol'] : null;
        $newJobId = $jobId !== null && $jobId !== '' ? (int) $jobId : null;
        $previousJobId = $previousJob !== null ? (int) $previousJob['id'] : null;
        if ($previousJobId !== null && $previousJobId !== $newJobId) {
            $this->activity->event($previousJobId, $userId, 'invoice_unlinked', [
                'invoice_id' => $invoiceId,
                'varsymbol' => $varsymbol,
            ]);
        }
        if ($newJobId !== null && $newJobId !== $previousJobId) {
            $this->activity->event($newJobId, $userId, 'invoice_linked', [
                'invoice_id' => $invoiceId,
                'varsymbol' => $varsymbol,
            ]);
        }

        $job = $this->jobInvoices->jobForInvoiceScoped($invoiceId, $supplierId);

        return Json::ok($response, ['job' => $job]);
    }

    /** @return array{id: int, myucto_id: int}|null */
    private function clientRow(int $supplierId, int $clientId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, myucto_id FROM clients
              WHERE supplier_id = ? AND (id = ? OR myucto_id = ?)
              ORDER BY (id = ?) DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $clientId, $clientId, $clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'myucto_id' => $row['myucto_id'] !== null && $row['myucto_id'] !== '' ? (int) $row['myucto_id'] : 0,
        ];
    }
}
