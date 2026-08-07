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

final class UpdateJobAction
{
    public function __construct(
        private readonly JobRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly JobActivityLogger $activity,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        $clientIds = array_map('intval', (array) ($body['client_ids'] ?? []));
        $assigneeUserIds = array_key_exists('assignee_user_ids', $body)
            ? array_map('intval', (array) $body['assignee_user_ids'])
            : null;

        $before = $this->repo->find($id, $supplierId);

        try {
            $job = $this->repo->update($id, $supplierId, $body, $clientIds, $assigneeUserIds);
        } catch (JobNumberConflictException) {
            return Json::error($response, 'conflict', 'Toto číslo zakázky už existuje.', 409);
        } catch (JobNumberFormatException) {
            return Json::error($response, 'validation_failed', 'Neplatný formát čísla zakázky.', 400);
        }

        if ($job === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('tri_job.updated', TriRequest::userId($request), 'tri_job', $id, [], $ip, $request->getHeaderLine('User-Agent'));

        if ($before !== null) {
            $this->emitFeedEvents($request, $id, $before, $job);
        }

        return Json::ok($response, $job);
    }

    /**
     * Systémové události do feedu zakázky: diff kontaktů, řešitelů a základních polí.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function emitFeedEvents(Request $request, int $jobId, array $before, array $after): void
    {
        $userId = TriRequest::userId($request);

        $contactName = static fn (array $c): string => trim(
            (string) $c['company_name'] !== ''
                ? (string) $c['company_name']
                : trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))
        );
        $beforeContacts = [];
        foreach ((array) ($before['contacts'] ?? []) as $c) {
            $beforeContacts[(int) $c['client_id']] = $contactName($c);
        }
        $afterContacts = [];
        foreach ((array) ($after['contacts'] ?? []) as $c) {
            $afterContacts[(int) $c['client_id']] = $contactName($c);
        }
        foreach (array_diff_key($afterContacts, $beforeContacts) as $name) {
            $this->activity->event($jobId, $userId, 'contact_added', ['name' => $name]);
        }
        foreach (array_diff_key($beforeContacts, $afterContacts) as $name) {
            $this->activity->event($jobId, $userId, 'contact_removed', ['name' => $name]);
        }

        $beforeAssignees = [];
        foreach ((array) ($before['assignees'] ?? []) as $a) {
            $beforeAssignees[(int) $a['user_id']] = (string) $a['name'];
        }
        $afterAssignees = [];
        foreach ((array) ($after['assignees'] ?? []) as $a) {
            $afterAssignees[(int) $a['user_id']] = (string) $a['name'];
        }
        foreach (array_diff_key($afterAssignees, $beforeAssignees) as $name) {
            $this->activity->event($jobId, $userId, 'assignee_added', ['name' => $name]);
        }
        foreach (array_diff_key($beforeAssignees, $afterAssignees) as $name) {
            $this->activity->event($jobId, $userId, 'assignee_removed', ['name' => $name]);
        }

        $changedFields = [];
        foreach (['number', 'title', 'site_street', 'site_city', 'site_zip', 'notes'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changedFields[] = $field;
            }
        }
        if ($changedFields !== []) {
            $this->activity->event($jobId, $userId, 'job_updated', ['fields' => $changedFields]);
        }
    }
}
