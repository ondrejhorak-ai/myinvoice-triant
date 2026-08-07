<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Chat + log událostí u zakázek TRI (tabulka tri_job_activity).
 * Komentáře uživatelů (kind=comment) a systémové události (kind=event)
 * žijí v jedné tabulce — chronologie feedu je jeden SELECT podle id.
 */
final class JobActivityRepository
{
    public function __construct(private readonly Connection $db) {}

    public function jobExists(int $jobId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM tri_jobs WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$jobId, $supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Vrátí položky feedu vzestupně podle id.
     * - before_id: starší historie (donačítání nahoru)
     * - after_id:  novější položky (polling)
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function listForJob(int $jobId, ?int $beforeId = null, ?int $afterId = null, int $limit = 50): array
    {
        $where = ['a.job_id = ?', 'a.deleted_at IS NULL'];
        $params = [$jobId];

        if ($afterId !== null) {
            $where[] = 'a.id > ?';
            $params[] = $afterId;
            $order = 'ASC';
        } else {
            if ($beforeId !== null) {
                $where[] = 'a.id < ?';
                $params[] = $beforeId;
            }
            // nejnovější stránka: čteme DESC a otočíme
            $order = 'DESC';
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.*, u.name AS user_name
               FROM tri_job_activity a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE $whereSql
              ORDER BY a.id $order
              LIMIT ?"
        );
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p);
        }
        $stmt->bindValue($idx, $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        if ($order === 'DESC') {
            $rows = array_reverse($rows);
        }

        return [
            'data'     => array_map([$this, 'castRow'], $rows),
            'has_more' => $hasMore,
        ];
    }

    /** @return array<string, mixed> */
    public function addComment(int $jobId, int $userId, string $body): array
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tri_job_activity (job_id, user_id, kind, body) VALUES (?, ?, 'comment', ?)"
        );
        $stmt->execute([$jobId, $userId, $body]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $item = $this->findItem($id);
        assert($item !== null);

        return $item;
    }

    /** @param array<string, mixed> $payload */
    public function addEvent(int $jobId, ?int $userId, string $eventType, array $payload = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tri_job_activity (job_id, user_id, kind, event_type, payload)
             VALUES (?, ?, 'event', ?, ?)"
        );
        $stmt->execute([
            $jobId,
            $userId !== null && $userId > 0 ? $userId : null,
            $eventType,
            $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /** Vrátí položku vč. supplier_id zakázky (pro ověření scope). */
    public function findItem(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, u.name AS user_name, j.supplier_id
               FROM tri_job_activity a
               JOIN tri_jobs j ON j.id = a.job_id
          LEFT JOIN users u ON u.id = a.user_id
              WHERE a.id = ? AND a.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $item = $this->castRow($row);
        $item['supplier_id'] = (int) $row['supplier_id'];

        return $item;
    }

    public function updateComment(int $id, string $body): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tri_job_activity SET body = ?, updated_at = NOW()
              WHERE id = ? AND kind = 'comment' AND deleted_at IS NULL"
        );
        $stmt->execute([$body, $id]);

        return $this->findItem($id);
    }

    public function softDeleteComment(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tri_job_activity SET deleted_at = NOW()
              WHERE id = ? AND kind = 'comment' AND deleted_at IS NULL"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private function castRow(array $row): array
    {
        $payload = null;
        if ($row['payload'] !== null) {
            $decoded = json_decode((string) $row['payload'], true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        return [
            'id'         => (int) $row['id'],
            'job_id'     => (int) $row['job_id'],
            'user_id'    => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name'  => isset($row['user_name']) && $row['user_name'] !== null ? (string) $row['user_name'] : null,
            'kind'       => (string) $row['kind'],
            'event_type' => $row['event_type'] !== null ? (string) $row['event_type'] : null,
            'body'       => $row['body'] !== null ? (string) $row['body'] : null,
            'payload'    => $payload,
            'created_at' => (string) $row['created_at'],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }
}
