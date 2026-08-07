<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\JobNumberConflictException;
use MyInvoice\Tri\Service\JobNumberFormatException;
use MyInvoice\Tri\Service\JobNumberService;
use PDO;

final class JobRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobNumberService $numbers,
        private readonly TagRepository $tags,
    ) {}

    /** @return array{data: list<array>, meta: array<string, int>} */
    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        $where = ['j.supplier_id = ?', 'j.archived_at IS NULL'];
        $params = [$supplierId];

        if (!empty($filters['status'])) {
            $where[] = 'j.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(j.number LIKE ? OR j.title LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tri_jobs j WHERE $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $pdo->prepare(
            "SELECT j.*, u.name AS owner_name,
                    c.company_name AS customer_name
               FROM tri_jobs j
               JOIN users u ON u.id = j.owner_user_id
          LEFT JOIN clients c ON c.id = j.customer_client_id
              WHERE $whereSql
              ORDER BY j.updated_at DESC
              LIMIT ? OFFSET ?"
        );
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'data' => array_map(function (array $r) {
                $summary = $this->castSummary($r);
                $summary['assignees'] = $this->loadAssignees((int) $r['id']);

                return $summary;
            }, $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT j.*, u.name AS owner_name, u.email AS owner_email,
                    c.company_name AS customer_name, c.main_email AS customer_email
               FROM tri_jobs j
               JOIN users u ON u.id = j.owner_user_id
          LEFT JOIN clients c ON c.id = j.customer_client_id
              WHERE j.id = ? AND j.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $job = $this->castFull($row);
        $job['contacts'] = $this->loadContacts($id);
        $job['assignees'] = $this->loadAssignees($id);

        return $job;
    }

    /** @return list<array{id: int, name: string}> */
    public function listActiveUsers(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, name FROM users WHERE is_active = 1 ORDER BY name'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $r) => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $rows);
    }

    /**
     * @param list<int> $clientIds
     * @param list<int> $assigneeUserIds
     * @throws JobNumberConflictException|JobNumberFormatException
     */
    public function create(int $supplierId, array $data, int $ownerUserId, array $clientIds, array $assigneeUserIds = []): array
    {
        $pdo = $this->db->pdo();
        $yearSuffix = $this->numbers->getYearSuffix();
        $pdo->beginTransaction();
        try {
            $number = $this->numbers->assign(
                $pdo,
                $supplierId,
                $yearSuffix,
                isset($data['number']) ? (string) $data['number'] : null,
            );
            $customerId = $this->tags->findCustomerClientIdAmong($supplierId, $clientIds);

            $stmt = $pdo->prepare(
                'INSERT INTO tri_jobs (
                    supplier_id, number, title, owner_user_id, status,
                    customer_client_id, site_street, site_city, site_zip, site_country, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $number,
                (string) $data['title'],
                $ownerUserId,
                (string) ($data['status'] ?? 'active'),
                $customerId,
                $data['site_street'] ?? null,
                $data['site_city'] ?? null,
                $data['site_zip'] ?? null,
                $data['site_country'] ?? 'CZ',
                $data['notes'] ?? null,
            ]);
            $jobId = (int) $pdo->lastInsertId();
            $this->syncContacts($pdo, $jobId, $clientIds);
            $assignees = $assigneeUserIds !== [] ? $assigneeUserIds : [$ownerUserId];
            $this->syncAssignees($pdo, $jobId, $assignees);

            $variantCode = 'A';
            $variantNumber = $this->numbers->formatVariantNumber($number, $variantCode);
            $jobDate = (string) ($data['job_date'] ?? date('Y-m-d'));
            $vStmt = $pdo->prepare(
                'INSERT INTO tri_quote_variants (
                    job_id, variant_code, number, job_date, status, customer_client_id
                 ) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $vStmt->execute([$jobId, $variantCode, $variantNumber, $jobDate, 'draft', $customerId]);
            $variantId = (int) $pdo->lastInsertId();

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $job = $this->find($jobId, $supplierId);
        assert($job !== null);
        $job['initial_variant_id'] = $variantId;

        return $job;
    }

    /**
     * @param list<int> $clientIds
     * @param list<int>|null $assigneeUserIds null = beze změny; prázdné pole = fallback na zakladatele
     */
    public function update(int $id, int $supplierId, array $data, array $clientIds, ?array $assigneeUserIds = null): ?array
    {
        $existing = $this->find($id, $supplierId);
        if ($existing === null) {
            return null;
        }

        $pdo = $this->db->pdo();
        $yearSuffix = $this->numbers->getYearSuffix();
        $number = (string) ($data['number'] ?? $existing['number']);
        $numberChanged = $number !== $existing['number'];

        $pdo->beginTransaction();
        try {
            if ($numberChanged) {
                $this->numbers->assign($pdo, $supplierId, $yearSuffix, $number);
            }

            $customerId = $this->tags->findCustomerClientIdAmong($supplierId, $clientIds);

            $stmt = $pdo->prepare(
                'UPDATE tri_jobs SET
                    number = ?, title = ?, customer_client_id = ?,
                    site_street = ?, site_city = ?, site_zip = ?, site_country = ?, notes = ?
                 WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([
                $number,
                (string) $data['title'],
                $customerId,
                $data['site_street'] ?? null,
                $data['site_city'] ?? null,
                $data['site_zip'] ?? null,
                $data['site_country'] ?? 'CZ',
                $data['notes'] ?? null,
                $id,
                $supplierId,
            ]);
            $this->syncContacts($pdo, $id, $clientIds);

            if ($assigneeUserIds !== null) {
                $fallback = $assigneeUserIds !== [] ? $assigneeUserIds : [(int) $existing['owner_user_id']];
                $this->syncAssignees($pdo, $id, $fallback);
            }

            if ($numberChanged) {
                $this->cascadeJobNumber($pdo, $id, $number);
            }

            $pdo->prepare(
                'UPDATE tri_quote_variants SET customer_client_id = ? WHERE job_id = ?'
            )->execute([$customerId, $id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($id, $supplierId);
    }

    public function updateStatus(int $id, int $supplierId, string $status): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_jobs SET status = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$status, $id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    public function archive(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_jobs SET archived_at = NOW() WHERE id = ? AND supplier_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tri_jobs WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    public function isNumberAvailable(int $supplierId, string $number, ?int $excludeJobId = null): bool
    {
        $sql = 'SELECT id FROM tri_jobs WHERE supplier_id = ? AND number = ?';
        $params = [$supplierId, $number];
        if ($excludeJobId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeJobId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() === false;
    }

    /** @return list<array<string, mixed>> */
    private function loadContacts(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT jc.sort_order, c.id, c.company_name, c.first_name, c.last_name,
                    c.main_email, c.phone, c.ic, c.street, c.city, c.zip,
                    co.iso2 AS country_iso2
               FROM tri_job_contacts jc
               JOIN clients c ON c.id = jc.client_id
               JOIN countries co ON co.id = c.country_id
              WHERE jc.job_id = ?
              ORDER BY jc.sort_order'
        );
        $stmt->execute([$jobId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $clientId = (int) $row['id'];
            $out[] = [
                'client_id'    => $clientId,
                'sort_order'   => (int) $row['sort_order'],
                'company_name' => (string) $row['company_name'],
                'first_name'   => $row['first_name'] !== null ? (string) $row['first_name'] : null,
                'last_name'    => $row['last_name'] !== null ? (string) $row['last_name'] : null,
                'main_email'   => $row['main_email'] !== null ? (string) $row['main_email'] : null,
                'phone'        => $row['phone'] !== null ? (string) $row['phone'] : null,
                'ic'             => $row['ic'] !== null ? (string) $row['ic'] : null,
                'street'         => $row['street'] !== null ? (string) $row['street'] : null,
                'city'           => $row['city'] !== null ? (string) $row['city'] : null,
                'zip'            => $row['zip'] !== null ? (string) $row['zip'] : null,
                'country_iso2'   => $row['country_iso2'] !== null ? (string) $row['country_iso2'] : null,
                'tags'           => $this->tags->tagsForClient($clientId),
            ];
        }

        return $out;
    }

    /** @return list<array{user_id: int, name: string}> */
    private function loadAssignees(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ju.user_id, u.name
               FROM tri_job_users ju
               JOIN users u ON u.id = ju.user_id
              WHERE ju.job_id = ?
              ORDER BY ju.sort_order'
        );
        $stmt->execute([$jobId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $r) => [
            'user_id' => (int) $r['user_id'],
            'name'    => (string) $r['name'],
        ], $rows);
    }

    /** @param list<int> $userIds */
    private function syncAssignees(PDO $pdo, int $jobId, array $userIds): void
    {
        $unique = array_values(array_unique(array_map('intval', $userIds)));
        $pdo->prepare('DELETE FROM tri_job_users WHERE job_id = ?')->execute([$jobId]);
        $ins = $pdo->prepare(
            'INSERT INTO tri_job_users (job_id, user_id, sort_order) VALUES (?, ?, ?)'
        );
        foreach ($unique as $i => $userId) {
            if ($userId > 0) {
                $ins->execute([$jobId, $userId, $i]);
            }
        }
    }

    /** @param list<int> $clientIds */
    private function syncContacts(PDO $pdo, int $jobId, array $clientIds): void
    {
        $unique = array_values(array_unique(array_map('intval', $clientIds)));
        $pdo->prepare('DELETE FROM tri_job_contacts WHERE job_id = ?')->execute([$jobId]);
        $ins = $pdo->prepare(
            'INSERT INTO tri_job_contacts (job_id, client_id, sort_order) VALUES (?, ?, ?)'
        );
        foreach ($unique as $i => $clientId) {
            if ($clientId > 0) {
                $ins->execute([$jobId, $clientId, $i]);
            }
        }
    }

    private function cascadeJobNumber(PDO $pdo, int $jobId, string $newNumber): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, variant_code FROM tri_quote_variants WHERE job_id = ?'
        );
        $stmt->execute([$jobId]);
        $upd = $pdo->prepare('UPDATE tri_quote_variants SET number = ? WHERE id = ?');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $num = $this->numbers->formatVariantNumber(
                $newNumber,
                (string) $row['variant_code'],
            );
            $upd->execute([$num, (int) $row['id']]);
        }
    }

    /** @param array<string, mixed> $row */
    private function castSummary(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'number'             => (string) $row['number'],
            'title'              => (string) $row['title'],
            'status'             => (string) $row['status'],
            'owner_user_id'      => (int) $row['owner_user_id'],
            'owner_name'         => (string) $row['owner_name'],
            'customer_client_id' => $row['customer_client_id'] !== null ? (int) $row['customer_client_id'] : null,
            'customer_name'      => $row['customer_name'] !== null ? (string) $row['customer_name'] : null,
            'updated_at'         => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function castFull(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'supplier_id'          => (int) $row['supplier_id'],
            'number'               => (string) $row['number'],
            'title'                => (string) $row['title'],
            'status'               => (string) $row['status'],
            'owner_user_id'        => (int) $row['owner_user_id'],
            'owner_name'           => (string) $row['owner_name'],
            'owner_email'          => (string) $row['owner_email'],
            'customer_client_id'   => $row['customer_client_id'] !== null ? (int) $row['customer_client_id'] : null,
            'customer_name'        => $row['customer_name'] !== null ? (string) $row['customer_name'] : null,
            'customer_email'       => $row['customer_email'] !== null ? (string) $row['customer_email'] : null,
            'approved_variant_id'  => $row['approved_variant_id'] !== null ? (int) $row['approved_variant_id'] : null,
            'site_street'          => $row['site_street'] !== null ? (string) $row['site_street'] : null,
            'site_city'            => $row['site_city'] !== null ? (string) $row['site_city'] : null,
            'site_zip'             => $row['site_zip'] !== null ? (string) $row['site_zip'] : null,
            'site_country'         => (string) $row['site_country'],
            'notes'                => $row['notes'] !== null ? (string) $row['notes'] : null,
            'archived_at'          => $row['archived_at'],
            'created_at'           => (string) $row['created_at'],
            'updated_at'           => (string) $row['updated_at'],
        ];
    }
}
