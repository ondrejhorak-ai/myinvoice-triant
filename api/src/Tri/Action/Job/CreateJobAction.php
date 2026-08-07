<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Service\JobActivityLogger;
use MyInvoice\Tri\Service\JobNumberConflictException;
use MyInvoice\Tri\Service\JobNumberFormatException;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateJobAction
{
    public function __construct(
        private readonly JobRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return Json::error($response, 'validation_failed', 'Název zakázky je povinný.', 400);
        }
        $supplierId = TriRequest::supplierId($request);
        $ownerId = isset($body['owner_user_id']) ? (int) $body['owner_user_id'] : TriRequest::userId($request);
        $clientIds = array_map('intval', (array) ($body['client_ids'] ?? []));
        $assigneeUserIds = array_map('intval', (array) ($body['assignee_user_ids'] ?? []));

        try {
            $job = $this->repo->create($supplierId, $body, $ownerId, $clientIds, $assigneeUserIds);
        } catch (JobNumberConflictException) {
            return Json::error($response, 'conflict', 'Toto číslo zakázky už existuje.', 409);
        } catch (JobNumberFormatException) {
            return Json::error($response, 'validation_failed', 'Neplatný formát čísla zakázky.', 400);
        }

        $this->log($request, 'tri_job.created', (int) $job['id'], ['number' => $job['number']]);
        $this->activity->event((int) $job['id'], TriRequest::userId($request), 'job_created', [
            'number' => (string) $job['number'],
            'title'  => (string) $job['title'],
        ]);

        return Json::ok($response, $job, 201);
    }

    /** @param array<string, mixed> $payload */
    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, TriRequest::userId($request), 'tri_job', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
