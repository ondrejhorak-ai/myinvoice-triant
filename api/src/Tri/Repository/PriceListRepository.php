<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\PriceListPricing;
use MyInvoice\Tri\Service\QuoteImageService;
use PDO;

final class PriceListRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{data: list<array<string, mixed>>} */
    public function listForSupplier(int $supplierId, string $q = ''): array
    {
        $sql = 'SELECT pl.id, pl.supplier_id, pl.name, pl.note, pl.created_at, pl.updated_at,
                       (SELECT COUNT(*) FROM tri_price_list_items i WHERE i.price_list_id = pl.id) AS item_count
                  FROM tri_price_lists pl
                 WHERE pl.supplier_id = ?';
        $params = [$supplierId];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' AND (pl.name LIKE ? OR pl.note LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY pl.name, pl.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return ['data' => array_map([$this, 'castList'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, name, note, created_at, updated_at
               FROM tri_price_lists WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $list = $this->castList($row);
        $list['items'] = $this->listItems($id, $supplierId);

        return $list;
    }

    public function exists(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM tri_price_lists WHERE id = ? AND supplier_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(int $supplierId, string $name, ?string $note): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tri_price_lists (supplier_id, name, note) VALUES (?, ?, ?)'
        );
        $stmt->execute([$supplierId, $name, $note]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, int $supplierId, string $name, ?string $note): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tri_price_lists SET name = ?, note = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$name, $note, $id, $supplierId]);

        return $this->exists($id, $supplierId);
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tri_price_lists WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<mixed> $items
     */
    public function saveItems(int $priceListId, int $supplierId, array $items): void
    {
        $this->assertImagesBelongToSupplier($items, $supplierId);
        $pdo = $this->db->pdo();
        $existingStmt = $pdo->prepare(
            'SELECT id, base_unit_price FROM tri_price_list_items WHERE price_list_id = ?'
        );
        $existingStmt->execute([$priceListId]);
        $existing = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $existing[(int) $row['id']] = $row;
        }

        $pdo->beginTransaction();
        try {
            $keepIds = [];
            $sort = 0;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $title = trim((string) ($item['title'] ?? ''));
                $designation = trim((string) ($item['designation'] ?? ''));
                $description = isset($item['description']) ? trim((string) $item['description']) : '';
                $price = (float) ($item['base_unit_price'] ?? 0);
                if ($title === '' && $designation === '' && $description === ''
                    && PriceListPricing::normalizeMoney($price) === '0.00') {
                    continue;
                }

                $imageId = !empty($item['image_id']) ? (int) $item['image_id'] : null;
                $qty = (float) ($item['default_quantity'] ?? 1);
                if ($qty <= 0) {
                    $qty = 1;
                }
                $unit = trim((string) ($item['unit'] ?? 'ks'));
                if ($unit === '') {
                    $unit = 'ks';
                }
                $vat = (int) ($item['vat_rate'] ?? 21);
                if ($vat > 100) {
                    $vat = 21;
                }
                $itemId = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
                $isExisting = $itemId > 0 && isset($existing[$itemId]);

                if ($isExisting) {
                    $stamp = PriceListPricing::shouldStampPriceUpdatedAt(
                        false,
                        $existing[$itemId]['base_unit_price'],
                        $price
                    );
                    $sql = 'UPDATE tri_price_list_items SET
                                sort_order = ?, image_id = ?, designation = ?, title = ?, description = ?,
                                default_quantity = ?, unit = ?, base_unit_price = ?, vat_rate = ?';
                    if ($stamp) {
                        $sql .= ', price_updated_at = CURRENT_TIMESTAMP';
                    }
                    $sql .= ' WHERE id = ? AND price_list_id = ?';
                    $pdo->prepare($sql)->execute([
                        $sort,
                        $imageId,
                        $designation,
                        $title !== '' ? $title : $designation,
                        $description !== '' ? $description : null,
                        $qty,
                        $unit,
                        $price,
                        $vat,
                        $itemId,
                        $priceListId,
                    ]);
                    $keepIds[] = $itemId;
                } else {
                    $pdo->prepare(
                        'INSERT INTO tri_price_list_items (
                            price_list_id, sort_order, image_id, designation, title, description,
                            default_quantity, unit, base_unit_price, vat_rate, price_updated_at
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
                    )->execute([
                        $priceListId,
                        $sort,
                        $imageId,
                        $designation,
                        $title !== '' ? $title : $designation,
                        $description !== '' ? $description : null,
                        $qty,
                        $unit,
                        $price,
                        $vat,
                    ]);
                    $keepIds[] = (int) $pdo->lastInsertId();
                }
                $sort++;
            }

            if ($keepIds === []) {
                $pdo->prepare('DELETE FROM tri_price_list_items WHERE price_list_id = ?')->execute([$priceListId]);
            } else {
                $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
                $pdo->prepare(
                    "DELETE FROM tri_price_list_items WHERE price_list_id = ? AND id NOT IN ($placeholders)"
                )->execute([$priceListId, ...$keepIds]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listItems(int $priceListId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.price_list_id, i.sort_order, i.image_id, i.designation, i.title, i.description,
                    i.default_quantity, i.unit, i.base_unit_price, i.vat_rate, i.price_updated_at,
                    i.created_at, i.updated_at,
                    qi.sha256 AS image_sha256, qi.width_px AS image_width_px,
                    qi.height_px AS image_height_px, qi.size_bytes AS image_size_bytes
               FROM tri_price_list_items i
               JOIN tri_price_lists pl ON pl.id = i.price_list_id AND pl.supplier_id = ?
               LEFT JOIN tri_quote_images qi ON qi.id = i.image_id AND qi.supplier_id = ?
              WHERE i.price_list_id = ?
              ORDER BY i.sort_order, i.id'
        );
        $stmt->execute([$supplierId, $supplierId, $priceListId]);

        return array_map([$this, 'castItem'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<mixed> $items */
    private function assertImagesBelongToSupplier(array $items, int $supplierId): void
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['image_id'])) {
                $ids[] = (int) $item['image_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM tri_quote_images WHERE supplier_id = ? AND id IN ($placeholders)"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($ids);
        sort($found);
        if ($ids !== $found) {
            throw new \RuntimeException('INVALID_QUOTE_IMAGE');
        }
    }

    /** @param array<string, mixed> $row */
    private function castList(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'name'        => (string) $row['name'],
            'note'        => $row['note'] !== null ? (string) $row['note'] : null,
            'item_count'  => isset($row['item_count']) ? (int) $row['item_count'] : 0,
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function castItem(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'price_list_id'     => (int) $row['price_list_id'],
            'sort_order'        => (int) $row['sort_order'],
            'image_id'          => $row['image_id'] !== null ? (int) $row['image_id'] : null,
            'designation'       => (string) $row['designation'],
            'title'             => (string) $row['title'],
            'description'       => $row['description'] !== null ? (string) $row['description'] : null,
            'default_quantity'  => (float) $row['default_quantity'],
            'unit'              => (string) $row['unit'],
            'base_unit_price'   => (float) $row['base_unit_price'],
            'vat_rate'          => (int) $row['vat_rate'],
            'price_updated_at'  => $row['price_updated_at'] !== null ? (string) $row['price_updated_at'] : null,
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
            'image'             => $row['image_id'] !== null && $row['image_sha256'] !== null ? [
                'id'         => (int) $row['image_id'],
                'url'        => QuoteImageService::url((int) $row['image_id'], (string) $row['image_sha256']),
                'width_px'   => (int) $row['image_width_px'],
                'height_px'  => (int) $row['image_height_px'],
                'size_bytes' => (int) $row['image_size_bytes'],
            ] : null,
        ];
    }
}
