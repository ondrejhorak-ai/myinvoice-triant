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
    public function listInRange(int $supplierId, string $calendar, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.*, j.number AS job_number, j.title AS job_title
               FROM tri_calendar_events e
          LEFT JOIN tri_jobs j ON j.id = e.job_id
              WHERE e.supplier_id = ? AND e.calendar = ?
                AND e.starts_at < ?
                AND COALESCE(e.ends_at, e.starts_at) >= ?
           ORDER BY e.starts_at, e.id'
        );
        $stmt->execute([$supplierId, $calendar, $to, $from]);

        return ['data' => array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.*, j.number AS job_number, j.title AS job_title
               FROM tri_calendar_events e
          LEFT JOIN tri_jobs j ON j.id = e.job_id
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
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_calendar_events
                (supplier_id, calendar, job_id, title, station, starts_at, ends_at, all_day, status, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $data['calendar'],
            $data['job_id'],
            $data['title'],
            $data['station'],
            $data['starts_at'],
            $data['ends_at'],
            $data['all_day'],
            $data['status'],
            $data['note'],
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
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_calendar_events
                SET calendar = ?, job_id = ?, title = ?, station = ?, starts_at = ?, ends_at = ?,
                    all_day = ?, status = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $data['calendar'],
            $data['job_id'],
            $data['title'],
            $data['station'],
            $data['starts_at'],
            $data['ends_at'],
            $data['all_day'],
            $data['status'],
            $data['note'],
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
            'title'      => (string) $row['title'],
            'station'    => $row['station'] !== null ? (string) $row['station'] : null,
            'starts_at'  => (string) $row['starts_at'],
            'ends_at'    => $row['ends_at'] !== null ? (string) $row['ends_at'] : null,
            'all_day'    => (int) $row['all_day'] === 1,
            'status'     => (string) $row['status'],
            'note'       => $row['note'] !== null ? (string) $row['note'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
