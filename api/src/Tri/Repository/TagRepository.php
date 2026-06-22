<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class TagRepository
{
    public const CUSTOMER_SLUG = 'zakaznik';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string, mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, name, slug, color, created_at
               FROM tri_tags
              WHERE supplier_id = ?
              ORDER BY name'
        );
        $stmt->execute([$supplierId]);

        return array_map([$this, 'castTag'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, name, slug, color, created_at
               FROM tri_tags WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->castTag($row) : null;
    }

    public function findBySlug(int $supplierId, string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, name, slug, color, created_at
               FROM tri_tags WHERE supplier_id = ? AND slug = ?'
        );
        $stmt->execute([$supplierId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->castTag($row) : null;
    }

    public function create(int $supplierId, string $name, ?string $color = null): int
    {
        $slug = $this->uniqueSlug($supplierId, $this->slugify($name));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_tags (supplier_id, name, slug, color) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $name, $slug, $color ?? '#6366f1']);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, int $supplierId, string $name, string $color): void
    {
        $slug = $this->uniqueSlug($supplierId, $this->slugify($name), $id);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_tags SET name = ?, slug = ?, color = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$name, $slug, $color, $id, $supplierId]);
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tri_tags WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function tagsForClient(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.supplier_id, t.name, t.slug, t.color
               FROM tri_client_tags ct
               JOIN tri_tags t ON t.id = ct.tag_id
              WHERE ct.client_id = ?
              ORDER BY t.name'
        );
        $stmt->execute([$clientId]);

        return array_map([$this, 'castTag'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<int> $tagIds */
    public function setClientTags(int $clientId, int $supplierId, array $tagIds): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM tri_client_tags WHERE client_id = ?')->execute([$clientId]);
            if ($tagIds !== []) {
                $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
                $check = $pdo->prepare(
                    "SELECT id FROM tri_tags WHERE supplier_id = ? AND id IN ($placeholders)"
                );
                $check->execute(array_merge([$supplierId], $tagIds));
                $valid = $check->fetchAll(PDO::FETCH_COLUMN);
                $ins = $pdo->prepare('INSERT INTO tri_client_tags (client_id, tag_id) VALUES (?, ?)');
                foreach ($valid as $tagId) {
                    $ins->execute([$clientId, (int) $tagId]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** First client on job contacts with Zákazník tag. */
    public function findCustomerClientIdAmong(int $supplierId, array $clientIds): ?int
    {
        if ($clientIds === []) {
            return null;
        }
        $customerTag = $this->findBySlug($supplierId, self::CUSTOMER_SLUG);
        if ($customerTag === null) {
            return null;
        }
        $tagId = (int) $customerTag['id'];
        foreach ($clientIds as $clientId) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM tri_client_tags WHERE client_id = ? AND tag_id = ? LIMIT 1'
            );
            $stmt->execute([(int) $clientId, $tagId]);
            if ($stmt->fetchColumn() !== false) {
                return (int) $clientId;
            }
        }

        return null;
    }

    private function slugify(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? $s;
        $s = trim($s, '-');

        return $s !== '' ? $s : 'tag';
    }

    private function uniqueSlug(int $supplierId, string $base, ?int $excludeId = null): string
    {
        $slug = $base;
        $n = 2;
        while ($this->slugTaken($supplierId, $slug, $excludeId)) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
    }

    private function slugTaken(int $supplierId, string $slug, ?int $excludeId): bool
    {
        $sql = 'SELECT 1 FROM tri_tags WHERE supplier_id = ? AND slug = ?';
        $params = [$supplierId, $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    private function castTag(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'name'        => (string) $row['name'],
            'slug'        => (string) $row['slug'],
            'color'       => (string) $row['color'],
            'created_at'  => (string) $row['created_at'],
        ];
    }
}
