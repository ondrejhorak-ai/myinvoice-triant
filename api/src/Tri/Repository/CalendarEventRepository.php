<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CalendarEventRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobRepository $jobs,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>}
     */
    public function listInRange(int $supplierId, ?string $calendar, string $from, string $to): array
    {
        $sql = 'SELECT e.*, j.number AS job_number, j.title AS job_title, u.name AS user_name
               FROM tri_calendar_events e
          LEFT JOIN tri_jobs j ON j.id = e.job_id
          LEFT JOIN users u ON u.id = e.user_id
              WHERE e.supplier_id = ?';
        $params = [$supplierId];
        if ($calendar !== null && $calendar !== '') {
            $sql .= ' AND e.calendar = ?';
            $params[] = $calendar;
        }
        $sql .= ' AND e.starts_at < ?
                AND COALESCE(e.ends_at, e.starts_at) >= ?
           ORDER BY e.starts_at, e.id';
        $params[] = $to;
        $params[] = $from;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return ['data' => array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])];
    }

    /**
     * Nadcházející expedice a výroba zakázky (od $from včetně).
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function listUpcomingForJob(int $supplierId, int $jobId, string $from): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.*, j.number AS job_number, j.title AS job_title, u.name AS user_name
               FROM tri_calendar_events e
          LEFT JOIN tri_jobs j ON j.id = e.job_id
          LEFT JOIN users u ON u.id = e.user_id
              WHERE e.supplier_id = ? AND e.job_id = ?
                AND e.calendar IN ('dispatch', 'production')
                AND COALESCE(e.ends_at, e.starts_at) >= ?
           ORDER BY e.starts_at, e.id
              LIMIT 20"
        );
        $stmt->execute([$supplierId, $jobId, $from]);

        return ['data' => array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.*, j.number AS job_number, j.title AS job_title, u.name AS user_name
               FROM tri_calendar_events e
          LEFT JOIN tri_jobs j ON j.id = e.job_id
          LEFT JOIN users u ON u.id = e.user_id
              WHERE e.id = ? AND e.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->cast($row);
    }

    /** @param array<string, mixed> $data */
    public function create(int $supplierId, array $data): array
    {
        $this->assertJob($data['job_id'], $supplierId);
        $this->assertUser($data['user_id']);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_calendar_events
                (supplier_id, calendar, job_id, user_id, title, station, starts_at, ends_at, all_day, status, note, arrival_window, crew)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $data['calendar'],
            $data['job_id'],
            $data['user_id'],
            $data['title'],
            $data['station'],
            $data['starts_at'],
            $data['ends_at'],
            $data['all_day'],
            $data['status'],
            $data['note'],
            $data['arrival_window'],
            $data['crew'],
        ]);

        return $this->find((int) $this->db->pdo()->lastInsertId(), $supplierId) ?? [];
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, int $supplierId, array $data): ?array
    {
        if ($this->find($id, $supplierId) === null) {
            return null;
        }
        $this->assertJob($data['job_id'], $supplierId);
        $this->assertUser($data['user_id']);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_calendar_events
                SET calendar = ?, job_id = ?, user_id = ?, title = ?, station = ?, starts_at = ?, ends_at = ?,
                    all_day = ?, status = ?, note = ?, arrival_window = ?, crew = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $data['calendar'],
            $data['job_id'],
            $data['user_id'],
            $data['title'],
            $data['station'],
            $data['starts_at'],
            $data['ends_at'],
            $data['all_day'],
            $data['status'],
            $data['note'],
            $data['arrival_window'],
            $data['crew'],
            $id,
            $supplierId,
        ]);

        return $this->find($id, $supplierId);
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM tri_calendar_events WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    private function assertJob(?int $jobId, int $supplierId): void
    {
        if ($jobId === null) {
            return;
        }
        if ($this->jobs->find($jobId, $supplierId) === null) {
            throw new \RuntimeException('JOB_NOT_FOUND');
        }
    }

    private function assertUser(?int $userId): void
    {
        if ($userId === null) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('USER_NOT_FOUND');
        }
    }

    /** @param array<string, mixed> $row */
    private function cast(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'supplier_id'=> (int) $row['supplier_id'],
            'calendar'   => (string) $row['calendar'],
            'job_id'     => $row['job_id'] !== null ? (int) $row['job_id'] : null,
            'job_number' => $row['job_number'] !== null ? (string) $row['job_number'] : null,
            'job_title'  => $row['job_title'] !== null ? (string) $row['job_title'] : null,
            'user_id'    => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name'  => isset($row['user_name']) && $row['user_name'] !== null ? (string) $row['user_name'] : null,
            'title'      => (string) $row['title'],
            'station'    => $row['station'] !== null ? (string) $row['station'] : null,
            'starts_at'  => (string) $row['starts_at'],
            'ends_at'    => $row['ends_at'] !== null ? (string) $row['ends_at'] : null,
            'all_day'    => (int) $row['all_day'] === 1,
            'status'     => (string) $row['status'],
            'note'       => $row['note'] !== null ? (string) $row['note'] : null,
            'arrival_window' => isset($row['arrival_window']) && $row['arrival_window'] !== null ? (string) $row['arrival_window'] : null,
            'crew'       => isset($row['crew']) && $row['crew'] !== null ? (string) $row['crew'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
