<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\QuoteRepository;
use PDO;

/**
 * Doplní vzorová data k existujícím Zakázkám TRI: varianty, položky, faktury.
 * Idempotentní — při opakovaném běhu přeskočí již naplněné části.
 */
final class TriDemoEnricher
{
    /** @var list<array{title: string, unit: string, price: float, vat: int}> */
    private const CATALOG = [
        ['title' => 'Kuchyňská linka na míru', 'unit' => 'ks', 'price' => 185000, 'vat' => 21],
        ['title' => 'Vestavěná skříň', 'unit' => 'ks', 'price' => 72000, 'vat' => 21],
        ['title' => 'Obývací stěna', 'unit' => 'ks', 'price' => 95000, 'vat' => 21],
        ['title' => 'Jídelní stůl + židle', 'unit' => 'sada', 'price' => 48000, 'vat' => 21],
        ['title' => 'Pracovní deska — masiv dub', 'unit' => 'm²', 'price' => 8500, 'vat' => 21],
        ['title' => 'Montáž a instalace', 'unit' => 'hod', 'price' => 950, 'vat' => 21],
        ['title' => 'Doprava na místo', 'unit' => 'ks', 'price' => 4500, 'vat' => 21],
        ['title' => 'Koupelnová skříňka', 'unit' => 'ks', 'price' => 28000, 'vat' => 21],
        ['title' => 'Šatní skříň se zrcadlem', 'unit' => 'ks', 'price' => 54000, 'vat' => 21],
        ['title' => 'Recepční pult', 'unit' => 'ks', 'price' => 125000, 'vat' => 21],
        ['title' => 'Kancelářský nábytek — sestava', 'unit' => 'ks', 'price' => 67000, 'vat' => 21],
        ['title' => 'Designové úchytky', 'unit' => 'ks', 'price' => 320, 'vat' => 21],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly QuoteRepository $quotes,
        private readonly JobInvoiceBuilder $invoiceBuilder,
        private readonly JobInvoiceRepository $jobInvoices,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
    ) {}

    /**
     * @return array{jobs: int, variants_filled: int, invoices_created: int}
     */
    public function enrich(int $supplierId, int $userId): array
    {
        $pdo = $this->db->pdo();
        $jobs = $pdo->prepare(
            'SELECT id, number, title, customer_client_id, approved_variant_id
               FROM tri_jobs
              WHERE supplier_id = ? AND archived_at IS NULL
              ORDER BY id'
        );
        $jobs->execute([$supplierId]);
        $rows = $jobs->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $variantsFilled = 0;
        $invoicesCreated = 0;

        foreach ($rows as $i => $job) {
            $jobId = (int) $job['id'];
            $scenario = $i; // 0..4 podle pořadí zakázek

            if ($job['customer_client_id'] === null) {
                $cust = $this->resolveCustomerId($jobId);
                if ($cust !== null) {
                    $pdo->prepare('UPDATE tri_jobs SET customer_client_id = ? WHERE id = ?')
                        ->execute([$cust, $jobId]);
                    $pdo->prepare('UPDATE tri_quote_variants SET customer_client_id = ? WHERE job_id = ?')
                        ->execute([$cust, $jobId]);
                    $job['customer_client_id'] = $cust;
                }
            }

            $targetVariants = match ($scenario % 5) {
                0 => 3,
                1 => 3,
                2 => 2,
                3 => 3,
                default => 2,
            };

            $variantIds = $this->ensureVariants($jobId, $supplierId, $targetVariants);
            foreach ($variantIds as $vi => $variantId) {
                $lineCount = 3 + (($jobId + $vi) % 8); // 3–10
                if ($this->fillVariantIfNeeded($variantId, $supplierId, $lineCount)) {
                    $variantsFilled++;
                }
            }

            $invoicesCreated += $this->applyInvoiceScenario(
                $scenario % 5,
                $jobId,
                $supplierId,
                $userId,
                $variantIds,
                (int) ($job['customer_client_id'] ?? 0),
            );
        }

        return [
            'jobs'             => count($rows),
            'variants_filled'  => $variantsFilled,
            'invoices_created' => $invoicesCreated,
        ];
    }

    /** @return list<int> */
    private function ensureVariants(int $jobId, int $supplierId, int $target): array
    {
        $existing = $this->quotes->listVariantsForJob($jobId, $supplierId);
        $ids = array_map(static fn (array $v) => (int) $v['id'], $existing);

        while (count($ids) < $target) {
            $created = $this->quotes->createVariant($jobId, $supplierId);
            if ($created === null) {
                break;
            }
            $ids[] = (int) $created['id'];
        }

        return $ids;
    }

    private function fillVariantIfNeeded(int $variantId, int $supplierId, int $lineCount): bool
    {
        $variant = $this->quotes->findVariant($variantId, $supplierId);
        if ($variant === null) {
            return false;
        }
        if (count($variant['line_items'] ?? []) >= 3) {
            return false;
        }

        $lines = $this->buildLines($lineCount, (int) $variant['job_id'], (string) $variant['variant_code']);
        $this->quotes->saveVariant($variantId, $supplierId, [
            'lock_version' => (int) $variant['lock_version'],
            'sections'     => [
                ['temp_id' => 'main', 'title' => 'Dodávka nábytku'],
                ['temp_id' => 'svc', 'title' => 'Služby'],
            ],
            'line_items'   => $lines,
        ]);

        return true;
    }

    /**
     * @param list<int> $variantIds
     */
    private function applyInvoiceScenario(
        int $scenario,
        int $jobId,
        int $supplierId,
        int $userId,
        array $variantIds,
        int $customerId,
    ): int {
        if ($customerId <= 0) {
            return 0;
        }

        $existing = $this->jobInvoices->listForJob($jobId, $supplierId);

        if ($scenario === 1) {
            return 0;
        }

        $this->ensureApproved($jobId, $supplierId, $variantIds);

        $created = 0;

        switch ($scenario) {
            case 0: // záloha zaplacená + konečná faktura
                if (!$this->hasInvoiceType($existing, 'proforma')) {
                    $advId = $this->createAdvance($jobId, $supplierId, $userId, 50);
                    $this->issueInvoice($advId, true);
                    $created++;
                    $existing = $this->jobInvoices->listForJob($jobId, $supplierId);
                }
                if (!$this->hasInvoiceType($existing, 'invoice')) {
                    $finalId = $this->invoiceBuilder->buildFinalDraft($jobId, $supplierId, $userId)['invoice_id'];
                    $this->issueInvoice($finalId, false);
                    $created++;
                }
                break;

            case 2: // zálohová vystavená, nezaplacená
                if ($existing === []) {
                    $advId = $this->createAdvance($jobId, $supplierId, $userId, 40);
                    $this->issueInvoice($advId, false);
                    $created++;
                }
                break;

            case 3: // záloha zaplacená
                if ($existing === []) {
                    $advId = $this->createAdvance($jobId, $supplierId, $userId, 50);
                    $this->issueInvoice($advId, true);
                    $created++;
                }
                break;

            case 4: // záloha zaplacená + konečná vystavená
                if (!$this->hasInvoiceType($existing, 'proforma')) {
                    $advId = $this->createAdvance($jobId, $supplierId, $userId, 50);
                    $this->issueInvoice($advId, true);
                    $created++;
                    $existing = $this->jobInvoices->listForJob($jobId, $supplierId);
                }
                if (!$this->hasInvoiceType($existing, 'invoice')) {
                    $finalId = $this->invoiceBuilder->buildFinalDraft($jobId, $supplierId, $userId)['invoice_id'];
                    $this->issueInvoice($finalId, false);
                    $created++;
                }
                break;
        }

        return $created;
    }

    /** @param list<int> $variantIds */
    private function ensureApproved(int $jobId, int $supplierId, array $variantIds): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT approved_variant_id FROM tri_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $approved = $stmt->fetchColumn();
        if ($approved !== null && (int) $approved > 0) {
            return;
        }
        if ($variantIds === []) {
            return;
        }
        $this->quotes->approveVariant($variantIds[0], $supplierId);
    }

    /** @param list<array<string, mixed>> $existing */
    private function hasInvoiceType(array $existing, string $type): bool
    {
        foreach ($existing as $inv) {
            if (($inv['invoice_type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    private function createAdvance(int $jobId, int $supplierId, int $userId, float $percent): int
    {
        $job = $this->db->pdo()->prepare('SELECT number FROM tri_jobs WHERE id = ?');
        $job->execute([$jobId]);
        $number = (string) $job->fetchColumn();

        return $this->invoiceBuilder->buildAdvanceDraft($jobId, $supplierId, $userId, [
            'percent' => $percent,
            'text'    => "Záloha na objednávku č.{$number}",
        ])['invoice_id'];
    }

    private function issueInvoice(int $invoiceId, bool $forcePaid): void
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || $invoice['status'] !== 'draft') {
            return;
        }

        $supplierId = (int) $invoice['supplier_id'];
        $issueDate = new \DateTimeImmutable((string) $invoice['issue_date']);
        $varsymbol = $this->varsymbol->next(
            $supplierId,
            (string) $invoice['invoice_type'],
            $issueDate,
            (int) $invoice['client_id'],
        );
        $snapshots = $this->snapshots->build(
            (int) $invoice['client_id'],
            (int) $invoice['currency_id'],
            $supplierId,
        );

        $autoPaid = $forcePaid || InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue($invoice);
        $status = $autoPaid ? 'paid' : 'issued';
        $paidAt = $autoPaid ? $invoice['issue_date'] : null;

        $this->db->pdo()->prepare(
            'UPDATE invoices SET
                varsymbol = ?, client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ?,
                status = ?, paid_at = ?
             WHERE id = ? AND status = "draft"'
        )->execute([
            $varsymbol,
            json_encode($snapshots['client'], JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $status,
            $paidAt,
            $invoiceId,
        ]);

        $this->calculator->recompute($invoiceId);
    }

    private function resolveCustomerId(int $jobId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT j.customer_client_id
               FROM tri_jobs j WHERE j.id = ?'
        );
        $stmt->execute([$jobId]);
        $cid = $stmt->fetchColumn();
        if ($cid !== false && $cid !== null) {
            return (int) $cid;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT ct.client_id
               FROM tri_job_contacts jc
               JOIN tri_client_tags ct ON ct.client_id = jc.client_id
               JOIN tri_tags t ON t.id = ct.tag_id AND t.slug = "zakaznik"
              WHERE jc.job_id = ?
              ORDER BY jc.sort_order
              LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $tagged = $stmt->fetchColumn();
        if ($tagged !== false) {
            return (int) $tagged;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT client_id FROM tri_job_contacts WHERE job_id = ? ORDER BY sort_order LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $first = $stmt->fetchColumn();

        return $first !== false ? (int) $first : null;
    }

    /** @return list<array<string, mixed>> */
    private function buildLines(int $count, int $jobId, string $variantCode): array
    {
        $catalog = self::CATALOG;
        shuffle($catalog);
        $picked = array_slice($catalog, 0, min($count, count($catalog)));
        $lines = [];
        foreach (array_values($picked) as $i => $item) {
            $qty = $item['unit'] === 'hod' ? (float) (4 + ($i % 5)) : (float) (1 + ($i % 2));
            $lines[] = [
                'section_temp_id' => $i < (int) ceil(count($picked) * 0.75) ? 'main' : 'svc',
                'designation'     => sprintf('%s.%02d', $variantCode, $i + 1),
                'title'           => $item['title'],
                'description'     => "Zakázka demo — položka {$jobId}",
                'quantity'        => $qty,
                'unit'            => $item['unit'],
                'base_unit_price' => $item['price'],
                'vat_rate'        => $item['vat'],
            ];
        }

        return $lines;
    }
}
