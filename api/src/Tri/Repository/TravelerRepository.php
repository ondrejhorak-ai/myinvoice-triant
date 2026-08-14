<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\TravelerGenerator;
use PDO;

final class TravelerRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobRepository $jobs,
        private readonly QuoteRepository $quotes,
    ) {}

    /** @return array{data: list<array<string, mixed>>} */
    public function listForSupplier(int $supplierId, ?int $jobId = null, ?string $status = null): array
    {
        $sql = 'SELECT t.*, j.number AS job_number, j.title AS job_title
                  FROM tri_travelers t
                  JOIN tri_jobs j ON j.id = t.job_id AND j.supplier_id = ?
                 WHERE 1=1';
        $params = [$supplierId];
        if ($jobId !== null && $jobId > 0) {
            $sql .= ' AND t.job_id = ?';
            $params[] = $jobId;
        }
        if ($status !== null && $status !== '' && in_array($status, ['open', 'done'], true)) {
            $sql .= ' AND t.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY j.number, t.number, t.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return ['data' => array_map([$this, 'castList'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*, j.number AS job_number, j.title AS job_title, j.status AS job_status
               FROM tri_travelers t
               JOIN tri_jobs j ON j.id = t.job_id AND j.supplier_id = ?
              WHERE t.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $traveler = $this->castList($row);
        $traveler['job_status'] = (string) $row['job_status'];
        $traveler['operations'] = $this->loadOperations((int) $row['id']);

        return $traveler;
    }

    /**
     * @return array{created: int, operations_added: int, data: list<array<string, mixed>>}
     */
    public function generateForJob(int $jobId, int $supplierId): array
    {
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null) {
            throw new \RuntimeException('NOT_FOUND');
        }
        if (!in_array((string) $job['status'], ['confirmed', 'completed'], true)) {
            throw new \RuntimeException('JOB_NOT_CONFIRMED');
        }
        if (empty($job['approved_variant_id'])) {
            throw new \RuntimeException('NO_APPROVED_VARIANT');
        }
        $variant = $this->quotes->findVariant((int) $job['approved_variant_id'], $supplierId);
        if ($variant === null) {
            throw new \RuntimeException('NO_APPROVED_VARIANT');
        }

        $existing = $this->loadForJobWithOperations($jobId);
        $plan = TravelerGenerator::plan($variant['line_items'] ?? [], $existing);
        $created = $this->applyPlan($jobId, (string) $job['number'], $plan);

        $list = $this->listForSupplier($supplierId, $jobId);

        return [
            'created'           => $created['created'],
            'operations_added'  => $created['operations_added'],
            'data'              => $list['data'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @return array{created: int, operations_added: int}
     */
    private function applyPlan(int $jobId, string $jobNumber, array $plan): array
    {
        $pdo = $this->db->pdo();
        $created = 0;
        $opsAdded = 0;
        $ins = $pdo->prepare(
            'INSERT INTO tri_travelers (job_id, quote_line_item_id, number, designation, title, description, quantity, unit, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'open\')'
        );
        $opIns = $pdo->prepare(
            'INSERT INTO tri_traveler_operations (traveler_id, station, hours, note) VALUES (?, ?, NULL, NULL)'
        );

        $pdo->beginTransaction();
        try {
            foreach ($plan['create'] as $row) {
                $ins->execute([
                    $jobId,
                    $row['quote_line_item_id'],
                    $this->nextNumber($jobId, $jobNumber),
                    $row['designation'],
                    $row['title'],
                    $row['description'],
                    $row['quantity'],
                    $row['unit'],
                ]);
                $travelerId = (int) $pdo->lastInsertId();
                $created++;
                foreach ($row['stations'] as $station) {
                    $opIns->execute([$travelerId, $station]);
                    $opsAdded++;
                }
            }
            foreach ($plan['missing_operations'] as $op) {
                $opIns->execute([(int) $op['traveler_id'], $op['station']]);
                $opsAdded++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['created' => $created, 'operations_added' => $opsAdded];
    }

    private function nextNumber(int $jobId, string $jobNumber): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT number FROM tri_travelers WHERE job_id = ?');
        $stmt->execute([$jobId]);
        $max = 0;
        $prefix = $jobNumber . '-';
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $number) {
            $n = (string) $number;
            if (str_starts_with($n, $prefix)) {
                $max = max($max, (int) substr($n, strlen($prefix)));
            }
        }

        return $prefix . str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string, mixed>> */
    private function loadForJobWithOperations(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, quote_line_item_id FROM tri_travelers WHERE job_id = ?'
        );
        $stmt->execute([$jobId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $out[] = [
                'id'                 => $id,
                'quote_line_item_id' => $row['quote_line_item_id'] !== null ? (int) $row['quote_line_item_id'] : null,
                'operations'         => $this->loadOperations($id),
            ];
        }

        return $out;
    }

    public function lineImageId(?int $quoteLineItemId, int $supplierId): ?int
    {
        if ($quoteLineItemId === null || $quoteLineItemId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT li.image_id
               FROM tri_quote_line_items li
               JOIN tri_quote_variants v ON v.id = li.quote_variant_id
               JOIN tri_jobs j ON j.id = v.job_id AND j.supplier_id = ?
              WHERE li.id = ?'
        );
        $stmt->execute([$supplierId, $quoteLineItemId]);
        $id = $stmt->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    /** @return list<array<string, mixed>> */
    private function loadOperations(int $travelerId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, station, hours, note FROM tri_traveler_operations WHERE traveler_id = ?'
        );
        $stmt->execute([$travelerId]);
        $byStation = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byStation[(string) $row['station']] = [
                'id'      => (int) $row['id'],
                'station' => (string) $row['station'],
                'hours'   => $row['hours'] !== null ? (float) $row['hours'] : null,
                'note'    => $row['note'] !== null ? (string) $row['note'] : null,
            ];
        }
        $ordered = [];
        foreach (TravelerGenerator::STATIONS as $station) {
            if (isset($byStation[$station])) {
                $ordered[] = $byStation[$station];
            }
        }

        return $ordered;
    }

    /** @param array<string, mixed> $row */
    private function castList(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'job_id'             => (int) $row['job_id'],
            'job_number'         => (string) ($row['job_number'] ?? ''),
            'job_title'          => (string) ($row['job_title'] ?? ''),
            'quote_line_item_id' => $row['quote_line_item_id'] !== null ? (int) $row['quote_line_item_id'] : null,
            'number'             => (string) $row['number'],
            'designation'        => (string) $row['designation'],
            'title'              => (string) $row['title'],
            'description'        => $row['description'] !== null ? (string) $row['description'] : null,
            'quantity'           => (float) $row['quantity'],
            'unit'               => (string) $row['unit'],
            'status'             => (string) $row['status'],
            'created_at'         => (string) $row['created_at'],
            'updated_at'         => (string) $row['updated_at'],
        ];
    }
}
