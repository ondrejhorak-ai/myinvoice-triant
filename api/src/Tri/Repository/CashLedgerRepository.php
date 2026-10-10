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
     * @param array{q?:string,kind?:string,handed?:string} $filters
     * @return array{summary: array<string, mixed>, data: list<array<string, mixed>>, handovers: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        $where = ['m.supplier_id = ?'];
        $params = [$supplierId];
        $kind = (string) ($filters['kind'] ?? '');
        if ($kind !== '' && in_array($kind, ['cash_in', 'cash_out', 'card', 'remittance'], true)) {
            $where[] = 'm.kind = ?';
            $params[] = $kind;
        }
        $handed = (string) ($filters['handed'] ?? 'all');
        if ($handed === 'open') {
            $where[] = 'm.handover_id IS NULL';
        } elseif ($handed === 'handed') {
            $where[] = 'm.handover_id IS NOT NULL';
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
            "SELECT m.*, u.name AS recorded_by_name, h.number AS handover_number
               FROM tri_cash_movements m
          LEFT JOIN users u ON u.id = m.recorded_by
          LEFT JOIN tri_cash_handovers h ON h.id = m.handover_id
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
            'handovers' => $this->handovers($supplierId),
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
                COALESCE(SUM(CASE WHEN kind = 'card' AND handover_id IS NULL THEN amount ELSE 0 END), 0) AS card_open,
                SUM(CASE WHEN handover_id IS NULL THEN 1 ELSE 0 END) AS open_docs
               FROM tri_cash_movements
              WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'drawer' => number_format((float) ($row['drawer'] ?? 0), 2, '.', ''),
            'card_open' => number_format((float) ($row['card_open'] ?? 0), 2, '.', ''),
            'open_documents' => (int) ($row['open_docs'] ?? 0),
            'float' => CashLedgerRules::format(CashLedgerRules::FLOAT_CENTS),
        ];
    }

    /** @param array<string, mixed> $data */
    public function createMovement(int $supplierId, int $userId, array $data): array
    {
        $existing = $this->findMovementByToken($supplierId, $data['client_token']);
        if ($existing !== null) {
            return $existing;
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO tri_cash_movements
                    (supplier_id, kind, amount, occurred_on, description, note, recorded_by, client_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['kind'],
                $data['amount'],
                $data['occurred_on'],
                $data['description'],
                $data['note'],
                $userId > 0 ? $userId : null,
                $data['client_token'],
            ]);
        } catch (PDOException $e) {
            $again = $this->findMovementByToken($supplierId, $data['client_token']);
            if ($again !== null && $this->isDuplicate($e)) {
                return $again;
            }
            throw $e;
        }

        return $this->findMovement((int) $this->db->pdo()->lastInsertId(), $supplierId) ?? [];
    }

    /** @param array<string, mixed> $input */
    public function previewHandover(int $supplierId, array $input): array
    {
        $book = CashLedgerRules::cents($this->summary($supplierId)['drawer']);
        $plan = CashLedgerRules::handover($book, CashLedgerRules::cents($input['counted']), CashLedgerRules::cents($input['keep']));

        return $this->previewPayload($supplierId, $book, $plan, $input);
    }

    /** @param array<string, mixed> $input */
    public function createHandover(int $supplierId, int $userId, array $input): array
    {
        $existing = $this->findHandoverByToken($supplierId, $input['client_token']);
        if ($existing !== null) {
            return $existing;
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                'SELECT id FROM tri_cash_movements WHERE supplier_id = ? AND handover_id IS NULL FOR UPDATE'
            );
            $lock->execute([$supplierId]);
            $openIds = array_map('intval', $lock->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $again = $this->findHandoverByToken($supplierId, $input['client_token']);
            if ($again !== null) {
                $pdo->commit();

                return $again;
            }
            $book = CashLedgerRules::cents($this->summary($supplierId)['drawer']);
            $plan = CashLedgerRules::handover($book, CashLedgerRules::cents($input['counted']), CashLedgerRules::cents($input['keep']));
            if ($openIds === [] && $plan['remitted_cents'] === 0) {
                throw new \InvalidArgumentException('NOTHING_TO_HAND_OVER');
            }
            $number = $this->nextNumber($supplierId, $input['occurred_on']);
            $insert = $pdo->prepare(
                'INSERT INTO tri_cash_handovers
                    (supplier_id, number, handed_on, book_amount, counted_amount, difference_amount,
                     keep_amount, remitted_amount, note, handed_by, client_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $number,
                $input['occurred_on'],
                CashLedgerRules::format($book),
                $input['counted'],
                CashLedgerRules::format($plan['difference_cents']),
                $input['keep'],
                CashLedgerRules::format($plan['remitted_cents']),
                $input['note'],
                $userId > 0 ? $userId : null,
                $input['client_token'],
            ]);
            $handoverId = (int) $pdo->lastInsertId();
            if ($plan['remitted_cents'] > 0) {
                $move = $pdo->prepare(
                    'INSERT INTO tri_cash_movements
                        (supplier_id, kind, amount, occurred_on, description, handover_id, recorded_by, client_token)
                     VALUES (?, \'remittance\', ?, ?, ?, ?, ?, ?)'
                );
                $token = $input['client_token'] !== null ? $input['client_token'] . '-r' : null;
                if ($token !== null && strlen($token) > 36) {
                    $token = substr(hash('sha256', $token), 0, 32);
                }
                $move->execute([
                    $supplierId,
                    CashLedgerRules::format($plan['remitted_cents']),
                    $input['occurred_on'],
                    'Odvod hotovosti účetní · ' . $number,
                    $handoverId,
                    $userId > 0 ? $userId : null,
                    $token,
                ]);
            }
            if ($openIds !== []) {
                $place = implode(',', array_fill(0, count($openIds), '?'));
                $update = $pdo->prepare(
                    "UPDATE tri_cash_movements SET handover_id = ? WHERE supplier_id = ? AND id IN ($place)"
                );
                $update->execute(array_merge([$handoverId, $supplierId], $openIds));
            }
            $pdo->commit();

            return $this->findHandover($handoverId, $supplierId) ?? [];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $again = $this->findHandoverByToken($supplierId, $input['client_token']);
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

    /** @return list<array<string, mixed>> */
    private function handovers(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT h.*, u.name AS handed_by_name
               FROM tri_cash_handovers h
          LEFT JOIN users u ON u.id = h.handed_by
              WHERE h.supplier_id = ?
           ORDER BY h.handed_on DESC, h.id DESC
              LIMIT 24'
        );
        $stmt->execute([$supplierId]);

        return array_map([$this, 'castHandover'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{difference_cents:int,remitted_cents:int,remain_cents:int} $plan
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function previewPayload(int $supplierId, int $book, array $plan, array $input): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, occurred_on, description, kind, amount
               FROM tri_cash_movements
              WHERE supplier_id = ? AND handover_id IS NULL
           ORDER BY occurred_on, id'
        );
        $stmt->execute([$supplierId]);

        return [
            'book' => CashLedgerRules::format($book),
            'counted' => $input['counted'],
            'keep' => $input['keep'],
            'difference' => CashLedgerRules::format($plan['difference_cents']),
            'remitted' => CashLedgerRules::format($plan['remitted_cents']),
            'remain' => CashLedgerRules::format($plan['remain_cents']),
            'documents' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'occurred_on' => (string) $row['occurred_on'],
                    'description' => (string) $row['description'],
                    'kind' => (string) $row['kind'],
                    'amount' => number_format((float) $row['amount'], 2, '.', ''),
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
        ];
    }

    private function nextNumber(int $supplierId, string $date): string
    {
        $prefix = 'PR-' . substr($date, 0, 7);
        $stmt = $this->db->pdo()->prepare(
            'SELECT number FROM tri_cash_handovers WHERE supplier_id = ? AND number LIKE ?'
        );
        $stmt->execute([$supplierId, $prefix . '%']);
        $taken = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        if (!isset($taken[$prefix])) {
            return $prefix;
        }
        for ($n = 2; $n < 100; $n++) {
            $candidate = $prefix . '-' . $n;
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }
        throw new \RuntimeException('HANDOVER_NUMBER');
    }

    /** @return array<string, mixed>|null */
    private function findMovement(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT m.*, u.name AS recorded_by_name, h.number AS handover_number
               FROM tri_cash_movements m
          LEFT JOIN users u ON u.id = m.recorded_by
          LEFT JOIN tri_cash_handovers h ON h.id = m.handover_id
              WHERE m.id = ? AND m.supplier_id = ?'
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

    /** @return array<string, mixed>|null */
    private function findHandover(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT h.*, u.name AS handed_by_name
               FROM tri_cash_handovers h
          LEFT JOIN users u ON u.id = h.handed_by
              WHERE h.id = ? AND h.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castHandover($row);
    }

    /** @return array<string, mixed>|null */
    private function findHandoverByToken(int $supplierId, ?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM tri_cash_handovers WHERE supplier_id = ? AND client_token = ?'
        );
        $stmt->execute([$supplierId, $token]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->findHandover((int) $id, $supplierId);
    }

    private function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /** @param array<string, mixed> $row */
    private function castMovement(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'amount' => number_format((float) $row['amount'], 2, '.', ''),
            'occurred_on' => (string) $row['occurred_on'],
            'description' => (string) $row['description'],
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'handover_id' => $row['handover_id'] !== null ? (int) $row['handover_id'] : null,
            'handover_number' => isset($row['handover_number']) && $row['handover_number'] !== null ? (string) $row['handover_number'] : null,
            'recorded_by_name' => isset($row['recorded_by_name']) && $row['recorded_by_name'] !== null ? (string) $row['recorded_by_name'] : null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function castHandover(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'handed_on' => (string) $row['handed_on'],
            'book_amount' => number_format((float) $row['book_amount'], 2, '.', ''),
            'counted_amount' => number_format((float) $row['counted_amount'], 2, '.', ''),
            'difference_amount' => number_format((float) $row['difference_amount'], 2, '.', ''),
            'keep_amount' => number_format((float) $row['keep_amount'], 2, '.', ''),
            'remitted_amount' => number_format((float) $row['remitted_amount'], 2, '.', ''),
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'handed_by_name' => isset($row['handed_by_name']) && $row['handed_by_name'] !== null ? (string) $row['handed_by_name'] : null,
        ];
    }
}
