<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

final class ProjectSync
{
    public function __construct(
        private readonly MyUctoClient $api,
        private readonly PDO $pdo,
        private readonly MirrorWriter $writer,
    ) {}

    public function run(): int
    {
        $n = 0;
        $page = 1;
        do {
            $payload = $this->api->listProjects(['per_page' => 200, 'page' => $page]);
            $items = ApiPage::items($payload);
            foreach ($items as $remote) {
                $this->upsert($remote);
                $n++;
            }
            $last = ApiPage::lastPage($payload);
            $page++;
        } while ($page <= $last && $items !== []);

        return $n;
    }

    /** @param array<string, mixed> $remote */
    public function upsert(array $remote): void
    {
        $id = (int) ($remote['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $remoteUpdated = ClientSync::remoteUpdatedAt($remote);
        $stmt = $this->pdo->prepare('SELECT updated_at FROM mu_projects WHERE id = ?');
        $stmt->execute([$id]);
        $localUpdated = $stmt->fetchColumn();
        $localUpdatedStr = $localUpdated !== false && $localUpdated !== null ? (string) $localUpdated : null;
        $now = $this->writer->now();
        if (!ApiPage::shouldWrite($localUpdatedStr, $remoteUpdated)) {
            $this->pdo->prepare('UPDATE mu_projects SET mu_synced_at = ?, deleted_at = NULL WHERE id = ?')
                ->execute([$now, $id]);
            $this->linkJob($id, $remote);
            return;
        }

        $clientId = (int) ($remote['client_id'] ?? 0);
        $this->writer->upsert('mu_projects', [
            'id' => $id,
            'client_myucto_id' => $clientId > 0 ? $clientId : null,
            'name' => (string) ($remote['name'] ?? ''),
            'project_number' => self::nullable($remote['project_number'] ?? null),
            'status' => (string) ($remote['status'] ?? 'active'),
            'updated_at' => $remoteUpdated,
            'raw_json' => json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mu_synced_at' => $now,
            'deleted_at' => null,
        ], [
            'client_myucto_id', 'name', 'project_number', 'status', 'updated_at', 'raw_json', 'mu_synced_at', 'deleted_at',
        ]);

        $this->linkJob($id, $remote);
    }

    /** @param array<string, mixed> $remote */
    private function linkJob(int $projectId, array $remote): void
    {
        $number = trim((string) ($remote['project_number'] ?? ''));
        if ($number === '') {
            return;
        }
        $this->pdo->prepare(
            'UPDATE tri_jobs SET myucto_project_id = ?
              WHERE number = ? AND myucto_project_id IS NULL'
        )->execute([$projectId, $number]);
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
