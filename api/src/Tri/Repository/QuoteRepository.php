<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\JobNumberService;
use MyInvoice\Tri\Service\QuoteCalculator;
use MyInvoice\Tri\Service\QuoteImageService;
use PDO;

final class QuoteRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobNumberService $numbers,
        private readonly TagRepository $tags,
        private readonly QuoteCalculator $calculator,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listVariantsForJob(int $jobId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.*
               FROM tri_quote_variants v
               JOIN tri_jobs j ON j.id = v.job_id
              WHERE v.job_id = ? AND j.supplier_id = ?
              ORDER BY v.variant_code'
        );
        $stmt->execute([$jobId, $supplierId]);

        return array_map([$this, 'castVariantSummary'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findVariant(int $variantId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.*, j.number AS job_number, j.title AS job_title, j.supplier_id
               FROM tri_quote_variants v
               JOIN tri_jobs j ON j.id = v.job_id
              WHERE v.id = ? AND j.supplier_id = ?'
        );
        $stmt->execute([$variantId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $variant = $this->castVariantFull($row);
        $variant['sections'] = $this->loadSections($variantId);
        $variant['line_items'] = $this->loadLineItems($variantId, $supplierId);

        return $variant;
    }

    public function createVariant(int $jobId, int $supplierId): ?array
    {
        $job = $this->loadJobBrief($jobId, $supplierId);
        if ($job === null) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT variant_code FROM tri_quote_variants WHERE job_id = ?'
        );
        $stmt->execute([$jobId]);
        $used = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $code = $this->numbers->nextVariantCode($used);
        if ($code === null) {
            throw new \RuntimeException('NO_VARIANT_CODE_AVAILABLE');
        }

        $number = $this->numbers->formatVariantNumber((string) $job['number'], $code);
        $customerId = $job['customer_client_id'] !== null ? (int) $job['customer_client_id'] : null;

        $ins = $this->db->pdo()->prepare(
            'INSERT INTO tri_quote_variants (
                job_id, variant_code, number, job_date, status, customer_client_id
             ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$jobId, $code, $number, date('Y-m-d'), 'draft', $customerId]);
        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->findVariant($id, $supplierId);
    }

    /**
     * @param array<string, mixed> $payload sections, line_items, lock_version, ...
     */
    public function saveVariant(int $variantId, int $supplierId, array $payload): ?array
    {
        $existing = $this->findVariant($variantId, $supplierId);
        if ($existing === null) {
            return null;
        }

        $lockVersion = (int) ($payload['lock_version'] ?? 0);
        if ($lockVersion !== (int) $existing['lock_version']) {
            throw new \RuntimeException('LOCK_VERSION_CONFLICT');
        }

        $sectionsInput = (array) ($payload['sections'] ?? []);
        $linesInput = (array) ($payload['line_items'] ?? []);
        foreach ($linesInput as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = $line['quantity'] ?? null;
            $price = $line['base_unit_price'] ?? null;
            $qtyF = is_numeric($qty) ? (float) $qty : NAN;
            $priceF = is_numeric($price) ? (float) $price : NAN;
            $whole = is_finite($qtyF) && abs($qtyF - round($qtyF)) <= 0.0000001;
            if (!$whole || $qtyF < 1 || $qtyF > 999999999 || !is_finite($priceF) || $priceF < 0 || $priceF > 9999999999.99) {
                throw new \DomainException('Množství musí být celé kladné číslo a cena nezáporná.');
            }
        }
        $quoteDiscountValue = $this->decimalOrNull($payload['quote_discount_value'] ?? null);
        $quoteCommissionValue = $this->decimalOrNull($payload['quote_commission_value'] ?? null);
        foreach ($sectionsInput as &$section) {
            if (!is_array($section)) {
                continue;
            }
            $section['discount_value'] = $this->decimalOrNull($section['discount_value'] ?? null);
            $section['commission_value'] = $this->decimalOrNull($section['commission_value'] ?? null);
        }
        unset($section);
        foreach ($linesInput as &$line) {
            if (!is_array($line)) {
                continue;
            }
            $line['markup_value'] = $this->decimalOrNull($line['markup_value'] ?? null);
            $line['line_discount_value'] = $this->decimalOrNull($line['line_discount_value'] ?? null);
        }
        unset($line);
        $this->assertImagesBelongToSupplier($linesInput, $supplierId);
        $this->assertCatalogItemsBelongToSupplier($linesInput, $supplierId);
        $releaseId = !empty($payload['price_release_id']) ? (int)$payload['price_release_id'] : ($existing['price_release_id'] ?? null);
        $effectiveDate = $payload['price_effective_date'] ?? $existing['price_effective_date'] ?? null;
        $this->assertReleaseSources($supplierId, $releaseId, $effectiveDate, $linesInput);

        $calcResult = $this->calculator->calculateVariant($sectionsInput, $linesInput, [
            'quote_discount_type'    => $payload['quote_discount_type'] ?? null,
            'quote_discount_value'   => $quoteDiscountValue,
            'quote_commission_type'  => $payload['quote_commission_type'] ?? null,
            'quote_commission_value' => $quoteCommissionValue,
        ]);
        $totals = $calcResult;
        $pricedByIndex = $calcResult['lines'];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $nameRaw = $payload['name'] ?? $existing['name'] ?? null;
            $name = is_string($nameRaw) ? trim($nameRaw) : null;
            if ($name === '') {
                $name = null;
            }
            if ($name !== null && mb_strlen($name) > 120) {
                $name = mb_substr($name, 0, 120);
            }

            $stmt = $pdo->prepare(
                'UPDATE tri_quote_variants SET
                    name = ?, price_release_id = ?, price_effective_date = ?,
                    job_date = ?, valid_until = ?,
                    quote_discount_type = ?, quote_discount_value = ?,
                    quote_commission_type = ?, quote_commission_value = ?,
                    subtotal = ?, vat_base_21 = ?, vat_amount_21 = ?,
                    vat_base_12 = ?, vat_amount_12 = ?, vat_base_0 = ?,
                    total_with_vat = ?, commission_total = ?, discount_total = ?,
                    internal_notes = ?, note_above_items = ?, note_below_items = ?,
                    lock_version = lock_version + 1, version = version + 1
                 WHERE id = ? AND lock_version = ?'
            );
            $stmt->execute([
                $name,
                $releaseId,
                $effectiveDate,
                (string) ($payload['job_date'] ?? $existing['job_date']),
                $payload['valid_until'] ?? null,
                $payload['quote_discount_type'] ?? null,
                $quoteDiscountValue,
                $payload['quote_commission_type'] ?? null,
                $quoteCommissionValue,
                $totals['subtotal'],
                $totals['vat_base_21'],
                $totals['vat_amount_21'],
                $totals['vat_base_12'],
                $totals['vat_amount_12'],
                $totals['vat_base_0'],
                $totals['total_with_vat'],
                $totals['commission_total'],
                $totals['discount_total'],
                $payload['internal_notes'] ?? null,
                $payload['note_above_items'] ?? null,
                $payload['note_below_items'] ?? null,
                $variantId,
                $lockVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException('LOCK_VERSION_CONFLICT');
            }

            $pdo->prepare('DELETE FROM tri_quote_sections WHERE quote_variant_id = ?')->execute([$variantId]);
            $pdo->prepare('DELETE FROM tri_quote_line_items WHERE quote_variant_id = ?')->execute([$variantId]);

            $sectionIdMap = [];
            foreach ($sectionsInput as $i => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $sIns = $pdo->prepare(
                    'INSERT INTO tri_quote_sections (
                        quote_variant_id, sort_order, title,
                        discount_type, discount_value, commission_type, commission_value
                     ) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $sOrder = isset($section['sort_order']) ? (int) $section['sort_order'] : $i;
                $sIns->execute([
                    $variantId,
                    $sOrder,
                    (string) ($section['title'] ?? ''),
                    $section['discount_type'] ?? null,
                    $section['discount_value'] ?? null,
                    $section['commission_type'] ?? null,
                    $section['commission_value'] ?? null,
                ]);
                $newId = (int) $pdo->lastInsertId();
                $tempKey = $section['temp_id'] ?? ('s' . $i);
                $sectionIdMap[(string) $tempKey] = $newId;
                if (isset($section['id']) && is_numeric($section['id'])) {
                    $sectionIdMap[(string) $section['id']] = $newId;
                }
            }

            $lIns = $pdo->prepare(
                'INSERT INTO tri_quote_line_items (
                    quote_variant_id, quote_section_id, catalog_item_id, source_product_id, source_release_id, image_id, is_manufactured,
                    sort_order, designation, title, description,
                    quantity, unit, base_unit_price, vat_rate,
                    markup_type, markup_value, markup_amount,
                    line_discount_type, line_discount_value, line_total
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $lineCalcIndex = 0;
            foreach ($linesInput as $i => $line) {
                if (!is_array($line)) {
                    continue;
                }
                $calc = $pricedByIndex[$lineCalcIndex] ?? $this->calculator->calculateLine($line);
                $lineCalcIndex++;
                $sectionRef = $line['quote_section_id'] ?? $line['section_temp_id'] ?? null;
                $sectionId = null;
                if ($sectionRef !== null && isset($sectionIdMap[(string) $sectionRef])) {
                    $sectionId = $sectionIdMap[(string) $sectionRef];
                } elseif (isset($line['quote_section_id']) && is_numeric($line['quote_section_id'])) {
                    $sectionId = (int) $line['quote_section_id'];
                }

                $lIns->execute([
                    $variantId,
                    $sectionId,
                    !empty($line['catalog_item_id']) ? (int) $line['catalog_item_id'] : null,
                    !empty($line['source_product_id']) ? (int)$line['source_product_id'] : null,
                    !empty($line['source_release_id']) ? (int)$line['source_release_id'] : null,
                    !empty($line['image_id']) ? (int) $line['image_id'] : null,
                    $this->lineIsManufactured($line),
                    isset($line['sort_order']) ? (int) $line['sort_order'] : $i,
                    (string) ($line['designation'] ?? ''),
                    (string) ($line['title'] ?? ''),
                    $line['description'] ?? null,
                    (float) ($line['quantity'] ?? 0),
                    (string) ($line['unit'] ?? 'ks'),
                    (float) ($line['base_unit_price'] ?? 0),
                    (int) ($line['vat_rate'] ?? 21),
                    $line['markup_type'] ?? null,
                    $line['markup_value'] ?? null,
                    $calc['markup_amount'],
                    $line['line_discount_type'] ?? null,
                    $line['line_discount_value'] ?? null,
                    $calc['line_total'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->findVariant($variantId, $supplierId);
    }

    public function duplicateVariant(int $variantId, int $supplierId): ?array
    {
        $source = $this->findVariant($variantId, $supplierId);
        if ($source === null) {
            return null;
        }

        $new = $this->createVariant((int) $source['job_id'], $supplierId);
        if ($new === null) {
            return null;
        }

        $payload = [
            'lock_version'         => 1,
            'job_date'             => $source['job_date'],
            'price_release_id' => $source['price_release_id'],
            'price_effective_date' => $source['price_effective_date'],
            'status'               => 'draft',
            'valid_until'          => $source['valid_until'],
            'quote_discount_type'    => $source['quote_discount_type'],
            'quote_discount_value'   => $source['quote_discount_value'],
            'quote_commission_type'  => $source['quote_commission_type'],
            'quote_commission_value' => $source['quote_commission_value'],
            'internal_notes'       => $source['internal_notes'],
            'note_above_items'     => $source['note_above_items'],
            'note_below_items'     => $source['note_below_items'],
            'sections'             => array_map(static fn (array $s) => [
                'title'             => $s['title'],
                'temp_id'           => 'dup-' . $s['id'],
                'discount_type'     => $s['discount_type'],
                'discount_value'    => $s['discount_value'],
                'commission_type'   => $s['commission_type'],
                'commission_value'  => $s['commission_value'],
            ], $source['sections']),
            'line_items'           => array_map(static function (array $l) {
                $sectionTemp = $l['quote_section_id'] !== null ? 'dup-' . $l['quote_section_id'] : null;

                return [
                    'section_temp_id'      => $sectionTemp,
                    'designation'          => $l['designation'],
                    'title'                => $l['title'],
                    'description'          => $l['description'],
                    'quantity'             => $l['quantity'],
                    'unit'                 => $l['unit'],
                    'base_unit_price'      => $l['base_unit_price'],
                    'vat_rate'             => $l['vat_rate'],
                    'markup_type'          => $l['markup_type'],
                    'markup_value'         => $l['markup_value'],
                    'line_discount_type'   => $l['line_discount_type'],
                    'line_discount_value'  => $l['line_discount_value'],
                    'image_id'             => $l['image_id'],
                    'catalog_item_id'      => $l['catalog_item_id'],
                    'source_product_id' => $l['source_product_id'],
                    'source_release_id' => $l['source_release_id'],
                    'is_manufactured'      => $l['is_manufactured'],
                ];
            }, $source['line_items']),
        ];

        return $this->saveVariant((int) $new['id'], $supplierId, $payload);
    }

    public function updateVariantStatus(int $variantId, int $supplierId, string $status): ?array
    {
        if (!in_array($status, ['draft', 'sent', 'approved'], true)) {
            throw new \InvalidArgumentException('INVALID_STATUS');
        }

        $variant = $this->findVariant($variantId, $supplierId);
        if ($variant === null) {
            return null;
        }

        $jobId = (int) $variant['job_id'];
        $wasApproved = (string) $variant['status'] === 'approved';
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($status === 'approved') {
                $pdo->prepare(
                    "UPDATE tri_quote_variants SET status = 'sent'
                      WHERE job_id = ? AND id != ? AND status = 'approved'"
                )->execute([$jobId, $variantId]);

                $pdo->prepare(
                    "UPDATE tri_quote_variants SET status = 'approved' WHERE id = ?"
                )->execute([$variantId]);

                $pdo->prepare(
                    'UPDATE tri_jobs SET approved_variant_id = ?, status = ? WHERE id = ? AND supplier_id = ?'
                )->execute([$variantId, 'confirmed', $jobId, $supplierId]);
            } else {
                $pdo->prepare(
                    'UPDATE tri_quote_variants SET status = ? WHERE id = ?'
                )->execute([$status, $variantId]);

                if ($wasApproved) {
                    $pdo->prepare(
                        'UPDATE tri_jobs SET approved_variant_id = NULL
                          WHERE id = ? AND supplier_id = ? AND approved_variant_id = ?'
                    )->execute([$jobId, $supplierId, $variantId]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->findVariant($variantId, $supplierId);
    }

    public function approveVariant(int $variantId, int $supplierId): ?array
    {
        return $this->updateVariantStatus($variantId, $supplierId, 'approved');
    }

    /** @return array<string, mixed>|null */
    private function loadJobBrief(int $jobId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, number, customer_client_id FROM tri_jobs WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$jobId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    private function loadSections(int $variantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sort_order, title, discount_type, discount_value, commission_type, commission_value
               FROM tri_quote_sections
              WHERE quote_variant_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$variantId]);

        return array_map(static fn (array $r) => [
            'id'               => (int) $r['id'],
            'sort_order'       => (int) $r['sort_order'],
            'title'            => (string) $r['title'],
            'discount_type'    => $r['discount_type'],
            'discount_value'   => $r['discount_value'] !== null ? (float) $r['discount_value'] : null,
            'commission_type'  => $r['commission_type'],
            'commission_value' => $r['commission_value'] !== null ? (float) $r['commission_value'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string, mixed>> */
    private function loadLineItems(int $variantId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT li.*,
                    qi.sha256 AS image_sha256, qi.width_px AS image_width_px,
                    qi.height_px AS image_height_px, qi.size_bytes AS image_size_bytes
               FROM tri_quote_line_items li
          LEFT JOIN tri_quote_images qi ON qi.id = li.image_id AND qi.supplier_id = ?
              WHERE li.quote_variant_id = ? ORDER BY li.sort_order'
        );
        $stmt->execute([$supplierId, $variantId]);

        return array_map(fn (array $r) => [
            'id'                  => (int) $r['id'],
            'image_id'            => $r['image_id'] !== null ? (int) $r['image_id'] : null,
            'catalog_item_id'     => $r['catalog_item_id'] !== null ? (int) $r['catalog_item_id'] : null,
            'source_product_id' => isset($r['source_product_id']) ? (int)$r['source_product_id'] : null,
            'source_release_id' => isset($r['source_release_id']) ? (int)$r['source_release_id'] : null,
            'is_manufactured'     => (int) ($r['is_manufactured'] ?? 1) === 1,
            'quote_section_id'    => $r['quote_section_id'] !== null ? (int) $r['quote_section_id'] : null,
            'sort_order'          => (int) $r['sort_order'],
            'designation'         => (string) $r['designation'],
            'title'               => (string) $r['title'],
            'description'         => $r['description'] !== null ? (string) $r['description'] : null,
            'quantity'            => (float) $r['quantity'],
            'unit'                => (string) $r['unit'],
            'base_unit_price'     => (float) $r['base_unit_price'],
            'vat_rate'            => (int) $r['vat_rate'],
            'markup_type'         => $r['markup_type'],
            'markup_value'        => $r['markup_value'] !== null ? (float) $r['markup_value'] : null,
            'markup_amount'       => (float) $r['markup_amount'],
            'line_discount_type'  => $r['line_discount_type'],
            'line_discount_value' => $r['line_discount_value'] !== null ? (float) $r['line_discount_value'] : null,
            'line_total'          => (float) $r['line_total'],
            'image'               => $r['image_id'] !== null && $r['image_sha256'] !== null ? [
                'id'         => (int) $r['image_id'],
                'url'        => QuoteImageService::url((int) $r['image_id'], (string) $r['image_sha256']),
                'width_px'   => (int) $r['image_width_px'],
                'height_px'  => (int) $r['image_height_px'],
                'size_bytes' => (int) $r['image_size_bytes'],
            ] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<mixed> $lines */
    private function assertImagesBelongToSupplier(array $lines, int $supplierId): void
    {
        $ids = [];
        foreach ($lines as $line) {
            if (is_array($line) && !empty($line['image_id'])) {
                $ids[] = (int) $line['image_id'];
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

    /** @param list<mixed> $lines */
    private function assertCatalogItemsBelongToSupplier(array $lines, int $supplierId): void
    {
        $ids = [];
        foreach ($lines as $line) {
            if (is_array($line) && !empty($line['catalog_item_id'])) {
                $ids[] = (int) $line['catalog_item_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id
               FROM tri_price_list_items i
               JOIN tri_price_lists pl ON pl.id = i.price_list_id AND pl.supplier_id = ?
              WHERE i.id IN ($placeholders)"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($ids);
        sort($found);
        if ($ids !== $found) {
            throw new \RuntimeException('INVALID_CATALOG_ITEM');
        }
    }

    /** @param array<string, mixed> $line */
    private function assertReleaseSources(int $sid, ?int $rid, ?string $date, array $lines): void
    {
        if ($rid) {
            $stmt=$this->db->pdo()->prepare("SELECT r.id FROM tri_price_releases r JOIN tri_price_lists p ON p.id=r.price_list_id WHERE p.supplier_id=? AND p.currency='CZK' AND r.id=? AND r.status='published' AND r.valid_from<=?");
            $stmt->execute([$sid,$rid,$date]);
            // Historic references remain valid after the next release shortens valid_until.
            if (!$stmt->fetchColumn()) throw new \RuntimeException('INVALID_CATALOG_ITEM');
        }
        foreach ($lines as $line) {
            if (empty($line['source_product_id']) && empty($line['source_release_id'])) continue;
            if (!$rid || (int)($line['source_release_id']??0)!==$rid) throw new \RuntimeException('INVALID_CATALOG_ITEM');
            $stmt=$this->db->pdo()->prepare('SELECT 1 FROM tri_price_release_items WHERE release_id=? AND product_id=?');
            $stmt->execute([$rid,(int)($line['source_product_id']??0)]);
            if (!$stmt->fetchColumn()) throw new \RuntimeException('INVALID_CATALOG_ITEM');
        }
    }

    private function lineIsManufactured(array $line): int
    {
        if (!array_key_exists('is_manufactured', $line)) {
            return 1;
        }
        $value = $line['is_manufactured'];
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return 0;
        }

        return ((int) $value) ? 1 : 0;
    }

    /**
     * A cleared number input is sent as "". MariaDB STRICT rejects that for DECIMAL (22007).
     */
    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return null;
        }

        return number_format($number, 2, '.', '');
    }

    /** @param array<string, mixed> $row */
    private function castVariantSummary(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'job_id'         => (int) $row['job_id'],
            'variant_code'   => (string) $row['variant_code'],
            'name'           => isset($row['name']) && $row['name'] !== null && $row['name'] !== ''
                ? (string) $row['name'] : null,
            'number'         => (string) $row['number'],
            'status'         => (string) $row['status'],
            'subtotal'       => (float) $row['subtotal'],
            'total_with_vat' => (float) $row['total_with_vat'],
            'job_date'       => (string) $row['job_date'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function castVariantFull(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'job_id'               => (int) $row['job_id'],
            'job_number'           => (string) $row['job_number'],
            'job_title'            => (string) $row['job_title'],
            'variant_code'         => (string) $row['variant_code'],
            'name'                 => isset($row['name']) && $row['name'] !== null && $row['name'] !== ''
                ? (string) $row['name'] : null,
            'number'               => (string) $row['number'],
            'job_date'             => (string) $row['job_date'],
            'status'               => (string) $row['status'],
            'customer_client_id'   => $row['customer_client_id'] !== null ? (int) $row['customer_client_id'] : null,
            'valid_until'          => $row['valid_until'],
            'price_release_id' => isset($row['price_release_id']) ? (int)$row['price_release_id'] : null,
            'price_effective_date' => $row['price_effective_date'] ?? null,
            'quote_discount_type'    => $row['quote_discount_type'],
            'quote_discount_value'   => $row['quote_discount_value'] !== null ? (float) $row['quote_discount_value'] : null,
            'quote_commission_type'  => $row['quote_commission_type'] ?? null,
            'quote_commission_value' => isset($row['quote_commission_value']) && $row['quote_commission_value'] !== null
                ? (float) $row['quote_commission_value'] : null,
            'subtotal'               => (float) $row['subtotal'],
            'vat_base_21'          => (float) $row['vat_base_21'],
            'vat_amount_21'        => (float) $row['vat_amount_21'],
            'vat_base_12'          => (float) $row['vat_base_12'],
            'vat_amount_12'        => (float) $row['vat_amount_12'],
            'vat_base_0'           => (float) $row['vat_base_0'],
            'total_with_vat'       => (float) $row['total_with_vat'],
            'commission_total'     => (float) $row['commission_total'],
            'discount_total'       => isset($row['discount_total']) ? (float) $row['discount_total'] : 0.0,
            'version'              => (int) $row['version'],
            'lock_version'         => (int) $row['lock_version'],
            'internal_notes'       => $row['internal_notes'] !== null ? (string) $row['internal_notes'] : null,
            'note_above_items'     => $row['note_above_items'] !== null ? (string) $row['note_above_items'] : null,
            'note_below_items'     => $row['note_below_items'] !== null ? (string) $row['note_below_items'] : null,
        ];
    }
}
