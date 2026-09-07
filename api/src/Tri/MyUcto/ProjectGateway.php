<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

final class ProjectGateway
{
    public function __construct(
        private readonly MyUctoClient $api,
        private readonly PDO $pdo,
        private readonly int $supplierId,
    ) {}

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed> job row fields including myucto_project_id
     */
    public function ensureProjectForJob(array $job): array
    {
        $jobId = (int) ($job['id'] ?? 0);
        $existing = (int) ($job['myucto_project_id'] ?? 0);
        if ($existing > 0) {
            return $job;
        }
        if ($jobId <= 0) {
            throw new MyUctoApiException('not_found', 'Zakázka nenalezena.', 404);
        }

        $clientMyuctoId = $this->clientMyuctoId((int) ($job['customer_client_id'] ?? 0));
        $number = trim((string) ($job['number'] ?? ''));
        $title = trim((string) ($job['title'] ?? ''));
        $name = trim($number . ' ' . $title);
        if ($name === '') {
            $name = 'Zakázka ' . $jobId;
        }

        $dueDays = $this->paymentDueDays((int) ($job['customer_client_id'] ?? 0));

        try {
            $remote = ContactGateway::unwrap($this->api->createProject([
                'client_id' => $clientMyuctoId,
                'name' => $name,
                'project_number' => $number !== '' ? $number : (string) $jobId,
                'payment_due_days' => $dueDays,
                'status' => 'active',
                'note' => $title !== '' ? $title : null,
            ]));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }

        $projectId = (int) ($remote['id'] ?? 0);
        if ($projectId <= 0) {
            throw new MyUctoApiException('sync_failed', 'MyÚčto vrátilo projekt bez id.', 502);
        }

        (new ProjectSync($this->api, $this->pdo, new MirrorWriter($this->pdo)))->upsert($remote);
        $this->pdo->prepare(
            'UPDATE tri_jobs SET myucto_project_id = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$projectId, $jobId, $this->supplierId]);

        $job['myucto_project_id'] = $projectId;

        return $job;
    }

    /**
     * Stejné jako {@see ensureProjectForJob}, ale chyba MyÚčta nepropaguje —
     * lokální stav (schválení varianty) už je uložený.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function tryEnsureProjectForJob(array $job): array
    {
        try {
            return $this->ensureProjectForJob($job);
        } catch (MyUctoApiException) {
            return $job;
        }
    }

    /** @param array<string, mixed> $job */
    public function closeProjectForJob(array $job): void
    {
        $projectId = (int) ($job['myucto_project_id'] ?? 0);
        if ($projectId <= 0) {
            return;
        }
        try {
            $remote = ContactGateway::unwrap($this->api->updateProject($projectId, ['status' => 'closed']));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        if (isset($remote['id'])) {
            (new ProjectSync($this->api, $this->pdo, new MirrorWriter($this->pdo)))->upsert($remote);
        }
    }

    private function clientMyuctoId(int $localClientId): int
    {
        if ($localClientId <= 0) {
            throw new MyUctoApiException(
                'not_linked',
                'Zakázka nemá zákazníka napojeného na MyÚčto.',
                409,
            );
        }
        $stmt = $this->pdo->prepare(
            'SELECT myucto_id FROM clients WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$localClientId, $this->supplierId]);
        $id = $stmt->fetchColumn();
        if ($id === false || $id === null || (int) $id <= 0) {
            throw new MyUctoApiException(
                'not_linked',
                'Kontakt není napojený na MyÚčto (chybí myucto_id). Spusťte synchronizaci.',
                409,
            );
        }

        return (int) $id;
    }

    private function paymentDueDays(int $localClientId): int
    {
        if ($localClientId <= 0) {
            return 14;
        }
        $stmt = $this->pdo->prepare(
            'SELECT payment_due_default FROM clients WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$localClientId, $this->supplierId]);
        $days = $stmt->fetchColumn();
        $n = $days !== false && $days !== null ? (int) $days : 0;

        return $n > 0 ? $n : 14;
    }

    private function mapUnavailable(MyUctoApiException $e): void
    {
        if ($e->isUnavailable() || $e->errorCode === 'network') {
            throw new MyUctoApiException('network', 'MyÚčto nedostupné', 503, $e, $e->details);
        }
    }
}
