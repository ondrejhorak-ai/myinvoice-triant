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
        $slug = $this->uniqueSlug($supplierId, self::slugify($name));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_tags (supplier_id, name, slug, color) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $name, $slug, $color ?? '#6366f1']);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, int $supplierId, string $name, string $color): void
    {
        $slug = $this->uniqueSlug($supplierId, self::slugify($name), $id);
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

    /**
     * First client on job contacts with Zákazník tag; fallback: klient
     * s příznakem `is_customer` (checkbox na kontaktu) — tag není nutný.
     */
    public function findCustomerClientIdAmong(int $supplierId, array $clientIds): ?int
    {
        if ($clientIds === []) {
            return null;
        }
        $clientIds = array_values(array_map('intval', $clientIds));

        $taggedIds = [];
        $customerTag = $this->findBySlug($supplierId, self::CUSTOMER_SLUG);
        if ($customerTag !== null) {
            $tagId = (int) $customerTag['id'];
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT client_id FROM tri_client_tags WHERE tag_id = ? AND client_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$tagId], $clientIds));
            $taggedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM clients WHERE supplier_id = ? AND is_customer = 1 AND id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$supplierId], $clientIds));
        $flaggedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return self::pickCustomerClientId($clientIds, $taggedIds, $flaggedIds);
    }

    /**
     * Čistá volba fakturačního klienta: nejdřív tag Zákazník (v pořadí kontaktů
     * na zakázce), pak fallback na příznak `is_customer` z formuláře kontaktu.
     *
     * @param list<int> $clientIds ordered job contacts
     * @param list<int> $taggedClientIds clients with the zakaznik tag
     * @param list<int> $customerFlaggedIds clients with is_customer = 1
     */
    public static function pickCustomerClientId(array $clientIds, array $taggedClientIds, array $customerFlaggedIds): ?int
    {
        foreach ($clientIds as $clientId) {
            if (in_array((int) $clientId, $taggedClientIds, true)) {
                return (int) $clientId;
            }
        }
        foreach ($clientIds as $clientId) {
            if (in_array((int) $clientId, $customerFlaggedIds, true)) {
                return (int) $clientId;
            }
        }

        return null;
    }

    /** Slug bez diakritiky („Zákazník" → `zakaznik`). */
    public static function slugify(string $name): string
    {
        $s = trim($name);
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr !== null) {
                $s = $tr->transliterate($s) ?: $s;
            }
        }
        $s = mb_strtolower($s, 'UTF-8');
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
