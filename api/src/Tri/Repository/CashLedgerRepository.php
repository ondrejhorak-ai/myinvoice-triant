<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\CashLedgerRules;
use PDO;
use PDOException;

final class CashLedgerRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{q?:string,kind?:string,accounted?:string,date_from?:string,date_to?:string,party_user_id?:int,trash?:bool} $filters
     * @return array{summary: array<string, mixed>, data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        $where = ['m.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['trash'])) {
            $where[] = 'm.deleted_at IS NOT NULL';
        } else {
            $where[] = 'm.deleted_at IS NULL';
        }
        $kind = (string) ($filters['kind'] ?? '');
        if ($kind !== '' && in_array($kind, CashLedgerRules::KINDS, true)) {
            $where[] = 'm.kind = ?';
            $params[] = $kind;
        }
        $accounted = (string) ($filters['accounted'] ?? 'all');
        if ($accounted === 'open') {
            $where[] = 'm.accounted = 0';
        } elseif ($accounted === 'done') {
            $where[] = 'm.accounted = 1';
        }
        $from = (string) ($filters['date_from'] ?? '');
        if ($from !== '') {
            $where[] = 'm.occurred_on >= ?';
            $params[] = $from;
        }
        $to = (string) ($filters['date_to'] ?? '');
        if ($to !== '') {
            $where[] = 'm.occurred_on <= ?';
            $params[] = $to;
        }
        $party = (int) ($filters['party_user_id'] ?? 0);
        if ($party > 0) {
            $where[] = 'm.party_user_id = ?';
            $params[] = $party;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'm.description LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM tri_cash_movements m WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$this->selectSql()}
              WHERE $whereSql
           ORDER BY m.occurred_on DESC, m.id DESC
              LIMIT ? OFFSET ?"
        );
        $idx = 1;
        foreach ($params as $value) {
            $stmt->bindValue($idx++, $value);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_map([$this, 'castMovement'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return [
            'summary' => $this->summary($supplierId),
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function summary(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN kind = 'cash_in' THEN amount
                    WHEN kind IN ('cash_out','remittance') THEN -amount
                    ELSE 0 END), 0) AS drawer,
                COALESCE(SUM(CASE
                    WHEN accounted = 0 AND kind = 'cash_in' THEN amount
                    WHEN accounted = 0 AND kind IN ('cash_out','remittance') THEN -amount
                    ELSE 0 END), 0) AS to_hand_over,
                SUM(CASE WHEN accounted = 0 THEN 1 ELSE 0 END) AS unaccounted
               FROM tri_cash_movements
              WHERE supplier_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'drawer' => number_format((float) ($row['drawer'] ?? 0), 2, '.', ''),
            'to_hand_over' => number_format((float) ($row['to_hand_over'] ?? 0), 2, '.', ''),
            'unaccounted' => (int) ($row['unaccounted'] ?? 0),
        ];
    }

    /**
     * @return array{movement: array<string, mixed>, audit: list<array<string, mixed>>}
     */
    public function detail(int $id, int $supplierId, bool $admin): array
    {
        $movement = $this->findMovement($id, $supplierId);
        if ($movement === null || ($movement['deleted_at'] !== null && !$admin)) {
            throw new \InvalidArgumentException('NOT_FOUND');
        }

        return [
            'movement' => $movement,
            'audit' => $this->auditLog($id, $supplierId),
        ];
    }

    /** @param array<string, mixed> $data */
    public function createMovement(int $supplierId, int $userId, array $data, bool $admin): array
    {
        $existing = $this->findMovementByToken($supplierId, $data['client_token']);
        if ($existing !== null) {
            return $existing;
        }
        $party = $data['party_user_id'] ?? null;
        if ($party === null && $userId > 0) {
            $party = $userId;
        }
        $this->assertUser($party);
        $this->assertJob($supplierId, $data['job_id']);
        $accounted = $admin && !empty($data['accounted']);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tri_cash_movements
                    (supplier_id, kind, amount, occurred_on, description, note, job_id,
                     recorded_by, party_user_id, accounted, accounted_at, accounted_by, client_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['kind'],
                $data['amount'],
                $data['occurred_on'],
                $data['description'],
                $data['note'],
                $data['job_id'],
                $userId > 0 ? $userId : null,
                $party,
                $accounted ? 1 : 0,
                $accounted ? date('Y-m-d H:i:s') : null,
                $accounted && $userId > 0 ? $userId : null,
                $data['client_token'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $created = $this->findMovement($id, $supplierId);
            if ($created === null) {
                throw new \RuntimeException('CASH_MOVEMENT_MISSING');
            }
            $this->writeAudit($id, $supplierId, $userId, 'created', ['after' => $this->snapshot($created)]);
            $pdo->commit();

            return $created;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $again = $this->findMovementByToken($supplierId, $data['client_token']);
            if ($again !== null && $this->isDuplicate($e)) {
                return $again;
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function updateMovement(int $id, int $supplierId, int $userId, array $data, bool $admin): array
    {
        $this->assertUser($data['party_user_id']);
        $this->assertJob($supplierId, $data['job_id']);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lock($id, $supplierId);
            $current = $this->requireActive($id, $supplierId);
            CashLedgerRules::assertEditable((bool) $current['accounted'], $admin);
            $accounted = $admin ? !empty($data['accounted']) : (bool) $current['accounted'];
            $accountedAt = $current['accounted_at'];
            $accountedBy = $current['accounted_by'];
            if ($accounted && !$current['accounted']) {
                $accountedAt = date('Y-m-d H:i:s');
                $accountedBy = $userId > 0 ? $userId : null;
            } elseif (!$accounted) {
                $accountedAt = null;
                $accountedBy = null;
            }
            $stmt = $pdo->prepare(
                'UPDATE tri_cash_movements
                    SET kind = ?, amount = ?, occurred_on = ?, description = ?, note = ?,
                        job_id = ?, party_user_id = ?, accounted = ?, accounted_at = ?, accounted_by = ?
                  WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([
                $data['kind'],
                $data['amount'],
                $data['occurred_on'],
                $data['description'],
                $data['note'],
                $data['job_id'],
                $data['party_user_id'],
                $accounted ? 1 : 0,
                $accountedAt,
                $accountedBy,
                $id,
                $supplierId,
            ]);
            $updated = $this->requireActive($id, $supplierId);
            $changes = CashLedgerRules::changes($this->snapshot($current), $this->snapshot($updated));
            if ($changes !== []) {
                $this->writeAudit($id, $supplierId, $userId, CashLedgerRules::auditAction($changes), $changes);
            }
            $pdo->commit();

            return $updated;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteMovement(int $id, int $supplierId, int $userId, bool $admin): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lock($id, $supplierId);
            $current = $this->requireActive($id, $supplierId);
            CashLedgerRules::assertEditable((bool) $current['accounted'], $admin);
            $stmt = $pdo->prepare(
                'UPDATE tri_cash_movements
                    SET deleted_at = NOW(), deleted_by = ?
                  WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$userId > 0 ? $userId : null, $id, $supplierId]);
            $this->writeAudit($id, $supplierId, $userId, 'deleted', null);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function restoreMovement(int $id, int $supplierId, int $userId): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lock($id, $supplierId);
            $current = $this->findMovement($id, $supplierId);
            if ($current === null) {
                throw new \InvalidArgumentException('NOT_FOUND');
            }
            if ($current['deleted_at'] === null) {
                $pdo->commit();

                return $current;
            }
            $stmt = $pdo->prepare(
                'UPDATE tri_cash_movements
                    SET deleted_at = NULL, deleted_by = NULL
                  WHERE id = ? AND supplier_id = ? AND deleted_at IS NOT NULL'
            );
            $stmt->execute([$id, $supplierId]);
            $this->writeAudit($id, $supplierId, $userId, 'restored', null);
            $restored = $this->findMovement($id, $supplierId) ?? $current;
            $pdo->commit();

            return $restored;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<int> $ids */
    public function markAccounted(int $supplierId, int $userId, array $ids): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $updated = 0;
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $lock = $pdo->prepare(
                "SELECT id FROM tri_cash_movements
                  WHERE supplier_id = ? AND deleted_at IS NULL AND accounted = 0 AND id IN ($place)
                  FOR UPDATE"
            );
            $lock->execute(array_merge([$supplierId], $ids));
            $open = array_map('intval', $lock->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($open !== []) {
                $markPlace = implode(',', array_fill(0, count($open), '?'));
                $stmt = $pdo->prepare(
                    "UPDATE tri_cash_movements
                        SET accounted = 1, accounted_at = NOW(), accounted_by = ?
                      WHERE supplier_id = ? AND deleted_at IS NULL AND accounted = 0 AND id IN ($markPlace)"
                );
                $stmt->execute(array_merge([$userId > 0 ? $userId : null, $supplierId], $open));
                $updated = count($open);
                foreach ($open as $id) {
                    $this->writeAudit($id, $supplierId, $userId, 'accounted', [
                        'accounted' => ['from' => false, 'to' => true],
                    ]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $updated;
    }

    /** @return array<string, mixed> */
    private function requireActive(int $id, int $supplierId): array
    {
        $current = $this->findMovement($id, $supplierId);
        if ($current === null) {
            throw new \InvalidArgumentException('NOT_FOUND');
        }
        if ($current['deleted_at'] !== null) {
            throw new \InvalidArgumentException('DELETED');
        }

        return $current;
    }

    private function lock(int $id, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM tri_cash_movements WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$id, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('NOT_FOUND');
        }
    }

    private function assertJob(int $supplierId, ?int $jobId): void
    {
        if ($jobId === null) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM tri_jobs WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$jobId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('INVALID_JOB');
        }
    }

    private function assertUser(?int $userId): void
    {
        if ($userId === null) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('INVALID_USER');
        }
    }

    /** @param array<string, mixed>|null $changes */
    private function writeAudit(int $movementId, int $supplierId, int $userId, string $action, ?array $changes): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_cash_movement_audit (movement_id, supplier_id, user_id, action, changes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $movementId,
            $supplierId,
            $userId > 0 ? $userId : null,
            $action,
            $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function auditLog(int $movementId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.action, a.changes, a.created_at, u.name AS user_name
               FROM tri_cash_movement_audit a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE a.movement_id = ? AND a.supplier_id = ?
           ORDER BY a.id DESC
              LIMIT 50'
        );
        $stmt->execute([$movementId, $supplierId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $changes = null;
            if ($row['changes'] !== null && $row['changes'] !== '') {
                $decoded = json_decode((string) $row['changes'], true);
                $changes = is_array($decoded) ? $decoded : null;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'user_name' => $row['user_name'] !== null ? (string) $row['user_name'] : null,
                'created_at' => (string) $row['created_at'],
                'changes' => $changes,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function findMovement(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$this->selectSql()} WHERE m.id = ? AND m.supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castMovement($row);
    }

    /** @return array<string, mixed>|null */
    private function findMovementByToken(int $supplierId, ?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM tri_cash_movements WHERE supplier_id = ? AND client_token = ?'
        );
        $stmt->execute([$supplierId, $token]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->findMovement((int) $id, $supplierId);
    }

    private function selectSql(): string
    {
        return 'm.*, u.name AS recorded_by_name, p.name AS party_name,
                a.name AS accounted_by_name, j.number AS job_number, j.title AS job_title
               FROM tri_cash_movements m
          LEFT JOIN users u ON u.id = m.recorded_by
          LEFT JOIN users p ON p.id = m.party_user_id
          LEFT JOIN users a ON a.id = m.accounted_by
          LEFT JOIN tri_jobs j ON j.id = m.job_id AND j.supplier_id = m.supplier_id';
    }

    private function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /** @param array<string, mixed> $row */
    private function castMovement(array $row): array
    {
        $jobNumber = isset($row['job_number']) && $row['job_number'] !== null ? (string) $row['job_number'] : null;
        $jobTitle = isset($row['job_title']) && $row['job_title'] !== null ? (string) $row['job_title'] : null;

        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'amount' => number_format((float) $row['amount'], 2, '.', ''),
            'occurred_on' => substr((string) $row['occurred_on'], 0, 10),
            'description' => (string) $row['description'],
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'accounted' => (int) ($row['accounted'] ?? 0) === 1,
            'accounted_at' => isset($row['accounted_at']) && $row['accounted_at'] !== null ? (string) $row['accounted_at'] : null,
            'accounted_by' => isset($row['accounted_by']) && $row['accounted_by'] !== null ? (int) $row['accounted_by'] : null,
            'accounted_by_name' => isset($row['accounted_by_name']) && $row['accounted_by_name'] !== null ? (string) $row['accounted_by_name'] : null,
            'party_user_id' => isset($row['party_user_id']) && $row['party_user_id'] !== null ? (int) $row['party_user_id'] : null,
            'party_name' => isset($row['party_name']) && $row['party_name'] !== null ? (string) $row['party_name'] : null,
            'job_id' => isset($row['job_id']) && $row['job_id'] !== null ? (int) $row['job_id'] : null,
            'job_number' => $jobNumber,
            'job_title' => $jobTitle,
            'recorded_by_name' => isset($row['recorded_by_name']) && $row['recorded_by_name'] !== null ? (string) $row['recorded_by_name'] : null,
            'deleted_at' => isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
        ];
    }

    /** @param array<string, mixed> $cast @return array<string, mixed> */
    private function snapshot(array $cast): array
    {
        $number = trim((string) ($cast['job_number'] ?? ''));
        $title = trim((string) ($cast['job_title'] ?? ''));

        return [
            'kind' => $cast['kind'] ?? null,
            'amount' => $cast['amount'] ?? null,
            'occurred_on' => $cast['occurred_on'] ?? null,
            'description' => $cast['description'] ?? null,
            'note' => $cast['note'] ?? null,
            'party_name' => $cast['party_name'] ?? null,
            'job_label' => $number === '' ? null : trim($number . ' ' . $title),
            'accounted' => (bool) ($cast['accounted'] ?? false),
        ];
    }
}
