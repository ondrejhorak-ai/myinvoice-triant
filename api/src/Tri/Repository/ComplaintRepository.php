<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ComplaintRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobRepository $jobs,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>}
     */
    public function listForSupplier(int $supplierId, ?int $jobId = null, ?string $status = null): array
    {
        $where = ['j.supplier_id = ?'];
        $params = [$supplierId];
        if ($jobId !== null && $jobId > 0) {
            $where[] = 'c.job_id = ?';
            $params[] = $jobId;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.*, j.number AS job_number, j.title AS job_title
               FROM tri_complaints c
               JOIN tri_jobs j ON j.id = c.job_id
              WHERE $whereSql
           ORDER BY c.status ASC, c.id DESC"
        );
        $stmt->execute($params);

        return ['data' => array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, j.number AS job_number, j.title AS job_title, j.supplier_id
               FROM tri_complaints c
               JOIN tri_jobs j ON j.id = c.job_id
              WHERE c.id = ? AND j.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $item = $this->cast($row);
        $item['comments'] = $this->listComments($id);

        return $item;
    }

    /**
     * @param array{job_id: int, title: string, description: ?string} $data
     * @return array<string, mixed>
     */
    public function create(int $supplierId, array $data): array
    {
        $this->assertJob($data['job_id'], $supplierId);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_complaints (job_id, title, description, status) VALUES (?, ?, ?, \'open\')'
        );
        $stmt->execute([$data['job_id'], $data['title'], $data['description']]);

        return $this->find((int) $this->db->pdo()->lastInsertId(), $supplierId) ?? [];
    }

    /**
     * @param array{title: string, description: ?string} $data
     * @return array<string, mixed>|null
     */
    public function update(int $id, int $supplierId, array $data): ?array
    {
        if ($this->find($id, $supplierId) === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_complaints SET title = ?, description = ? WHERE id = ?'
        );
        $stmt->execute([$data['title'], $data['description'], $id]);

        return $this->find($id, $supplierId);
    }

    /**
     * @param array{status: string, closed_at: ?string} $data
     * @return array<string, mixed>|null
     */
    public function updateStatus(int $id, int $supplierId, array $data): ?array
    {
        if ($this->find($id, $supplierId) === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_complaints SET status = ?, closed_at = ? WHERE id = ?'
        );
        $stmt->execute([$data['status'], $data['closed_at'], $id]);

        return $this->find($id, $supplierId);
    }

    /** @return list<array<string, mixed>> */
    public function listComments(int $complaintId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cc.*, u.name AS user_name
               FROM tri_complaint_comments cc
          LEFT JOIN users u ON u.id = cc.user_id
              WHERE cc.complaint_id = ?
           ORDER BY cc.id ASC'
        );
        $stmt->execute([$complaintId]);

        return array_map([$this, 'castComment'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string, mixed> */
    public function addComment(int $complaintId, int $userId, string $body): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_complaint_comments (complaint_id, user_id, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$complaintId, $userId > 0 ? $userId : null, $body]);

        return $this->findComment((int) $this->db->pdo()->lastInsertId()) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function findComment(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cc.*, u.name AS user_name, j.supplier_id
               FROM tri_complaint_comments cc
               JOIN tri_complaints c ON c.id = cc.complaint_id
               JOIN tri_jobs j ON j.id = c.job_id
          LEFT JOIN users u ON u.id = cc.user_id
              WHERE cc.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $item = $this->castComment($row);
        $item['supplier_id'] = (int) $row['supplier_id'];
        $item['complaint_id'] = (int) $row['complaint_id'];

        return $item;
    }

    public function updateComment(int $id, string $body): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_complaint_comments SET body = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$body, $id]);

        return $this->findComment($id);
    }

    public function deleteComment(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tri_complaint_comments WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    private function assertJob(int $jobId, int $supplierId): void
    {
        if ($this->jobs->find($jobId, $supplierId) === null) {
            throw new \RuntimeException('JOB_NOT_FOUND');
        }
    }

    /** @param array<string, mixed> $row */
    private function cast(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'job_id'      => (int) $row['job_id'],
            'job_number'  => (string) ($row['job_number'] ?? ''),
            'job_title'   => (string) ($row['job_title'] ?? ''),
            'title'       => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'status'      => (string) $row['status'],
            'created_at'  => (string) $row['created_at'],
            'closed_at'   => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function castComment(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'complaint_id' => (int) $row['complaint_id'],
            'user_id'      => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name'    => isset($row['user_name']) && $row['user_name'] !== null ? (string) $row['user_name'] : null,
            'body'         => (string) $row['body'],
            'created_at'   => (string) $row['created_at'],
            'updated_at'   => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }
}
