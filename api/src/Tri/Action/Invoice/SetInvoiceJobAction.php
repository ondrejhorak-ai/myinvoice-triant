<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
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
                $job = (new ProjectGateway($this->api, $this->db->pdo(), $supplierId))->ensureProjectForJob($job);
                $projectId = (int) ($job['myucto_project_id'] ?? 0);
                if ($isDraft && $projectId > 0) {
                    $gw->updateDraft($invoiceId, array_merge($detail, [
                        'project_id' => $projectId,
                        'items' => $detail['items'] ?? [],
                    ]));
                } else {
                    $this->jobInvoices->link($invoiceId, (int) $jobId, $supplierId);
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
}
