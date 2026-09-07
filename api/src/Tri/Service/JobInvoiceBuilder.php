<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\InvoiceGateway;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\ProjectGateway;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Repository\QuoteRepository;

final class JobInvoiceBuilder
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly QuoteRepository $quotes,
        private readonly JobInvoiceRepository $jobInvoices,
        private readonly MyUctoClient $api,
        private readonly Connection $db,
    ) {}

    private function invoices(int $supplierId): InvoiceGateway
    {
        return new InvoiceGateway($this->api, $this->db->pdo(), $supplierId);
    }

    private function projects(int $supplierId): ProjectGateway
    {
        return new ProjectGateway($this->api, $this->db->pdo(), $supplierId);
    }

    /**
     * @param array{percent?: float|null, amount?: float|null, text?: string|null} $options
     * @return array{invoice_id: int, invoice: array<string, mixed>}
     */
    public function buildAdvanceDraft(int $jobId, int $supplierId, int $userId, array $options = []): array
    {
        $ctx = $this->context($jobId, $supplierId);
        $variant = $ctx['variant'];
        $job = $ctx['job'];

        $variantTotal = (float) $variant['total_with_vat'];
        if ($variantTotal <= 0) {
            throw new \RuntimeException('Varianta má nulovou cenu.');
        }

        $percent = isset($options['percent']) && $options['percent'] !== null && $options['percent'] !== ''
            ? (float) $options['percent']
            : null;
        $amount = isset($options['amount']) && $options['amount'] !== null && $options['amount'] !== ''
            ? (float) $options['amount']
            : null;

        if ($amount !== null && $amount > 0) {
            $targetGross = $amount;
        } elseif ($percent !== null && $percent > 0) {
            $targetGross = round($variantTotal * $percent / 100, 2);
        } else {
            $targetGross = round($variantTotal * 0.5, 2);
        }
        if ($targetGross <= 0) {
            throw new \RuntimeException('Částka zálohy musí být kladná.');
        }

        $text = trim((string) ($options['text'] ?? ''));
        if ($text === '') {
            $text = 'Záloha na objednávku č.' . $job['number'];
        }

        $vatPercent = self::dominantVatRate($variant['line_items'] ?? []);
        $gw = $this->invoices($supplierId);
        $vatRateId = $gw->vatRateIdForPercent($vatPercent);
        $netUnit = round($targetGross / (1 + $vatPercent / 100), 2);
        $today = date('Y-m-d');

        $input = self::advanceInput(
            clientId: (int) $job['customer_client_id'],
            projectId: (int) $job['myucto_project_id'],
            orderNumber: (string) $job['number'],
            dueDate: $this->dueDateFromIssue($today, $job),
            issueDate: $today,
            text: $text,
            netUnit: $netUnit,
            vatRateId: $vatRateId,
        );
        $invoice = $gw->createDraft($input, 'job:' . $jobId . ':advance:' . $targetGross);

        return ['invoice_id' => (int) $invoice['id'], 'invoice' => $invoice];
    }

    /**
     * @return array{invoice_id: int, invoice: array<string, mixed>}
     */
    public function buildFinalDraft(int $jobId, int $supplierId, int $userId): array
    {
        $ctx = $this->context($jobId, $supplierId);
        $variant = $ctx['variant'];
        $job = $ctx['job'];
        $lineItems = $variant['line_items'] ?? [];
        if ($lineItems === []) {
            throw new \RuntimeException('Varianta nemá žádné položky.');
        }

        $gw = $this->invoices($supplierId);
        $items = self::finalItems($lineItems, fn (int $percent) => $gw->vatRateIdForPercent($percent));
        if ($items === []) {
            throw new \RuntimeException('Varianta nemá fakturovatelné položky.');
        }

        $advancePaid = $this->jobInvoices->paidAdvancesTotal($jobId, $supplierId);
        $today = date('Y-m-d');
        $input = self::finalInput(
            clientId: (int) $job['customer_client_id'],
            projectId: (int) $job['myucto_project_id'],
            orderNumber: (string) $job['number'],
            issueDate: $today,
            dueDate: $this->dueDateFromIssue($today, $job),
            items: $items,
            advancePaid: $advancePaid,
            noteAbove: $variant['note_above_items'] ?? null,
            noteBelow: $variant['note_below_items'] ?? null,
        );
        $invoice = $gw->createDraft($input, 'job:' . $jobId . ':final');

        return ['invoice_id' => (int) $invoice['id'], 'invoice' => $invoice];
    }

    /**
     * @param list<array<string, mixed>> $lineItems
     * @param callable(int): int $vatRateIdForPercent
     * @return list<array<string, mixed>>
     */
    public static function finalItems(array $lineItems, callable $vatRateIdForPercent): array
    {
        $linesSubtotal = 0.0;
        foreach ($lineItems as $line) {
            $linesSubtotal += (float) ($line['line_total'] ?? 0);
        }
        $linesSubtotal = round($linesSubtotal, 2);
        $variantSubtotal = 0.0;
        foreach ($lineItems as $line) {
            $variantSubtotal += (float) ($line['line_total'] ?? 0);
        }
        $ratio = $linesSubtotal > 0 ? $linesSubtotal / $linesSubtotal : 1.0;
        unset($variantSubtotal);

        $invoiceItems = [];
        foreach (array_values($lineItems) as $i => $line) {
            $qty = (float) ($line['quantity'] ?? 1);
            if ($qty <= 0) {
                continue;
            }
            $lineTotal = round((float) ($line['line_total'] ?? 0) * $ratio, 2);
            $unitNet = round($lineTotal / $qty, 2);
            $vatPercent = (int) ($line['vat_rate'] ?? 21);
            $invoiceItems[] = [
                'description' => self::formatLineDescription($line),
                'quantity' => $qty,
                'unit' => (string) ($line['unit'] ?? 'ks'),
                'unit_price_without_vat' => $unitNet,
                'vat_rate_id' => $vatRateIdForPercent($vatPercent),
                'order_index' => $i,
            ];
        }

        return $invoiceItems;
    }

    /**
     * @return array<string, mixed>
     */
    public static function advanceInput(
        int $clientId,
        int $projectId,
        string $orderNumber,
        string $dueDate,
        string $issueDate,
        string $text,
        float $netUnit,
        int $vatRateId,
    ): array {
        return [
            'invoice_type' => 'proforma',
            'client_id' => $clientId,
            'project_id' => $projectId,
            'supplier_order_number' => $orderNumber,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'currency' => 'CZK',
            'items' => [[
                'description' => $text,
                'quantity' => 1,
                'unit' => 'ks',
                'unit_price_without_vat' => $netUnit,
                'vat_rate_id' => $vatRateId,
                'order_index' => 0,
            ]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function finalInput(
        int $clientId,
        int $projectId,
        string $orderNumber,
        string $issueDate,
        string $dueDate,
        array $items,
        float $advancePaid,
        mixed $noteAbove,
        mixed $noteBelow,
    ): array {
        return [
            'invoice_type' => 'invoice',
            'client_id' => $clientId,
            'project_id' => $projectId,
            'supplier_order_number' => $orderNumber,
            'issue_date' => $issueDate,
            'tax_date' => $issueDate,
            'due_date' => $dueDate,
            'currency' => 'CZK',
            'advance_paid_amount' => $advancePaid,
            'note_above_items' => $noteAbove,
            'note_below_items' => $noteBelow,
            'items' => $items,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    public static function dominantVatRate(array $lines): int
    {
        $weights = [];
        foreach ($lines as $line) {
            $rate = (int) ($line['vat_rate'] ?? 21);
            $weights[$rate] = ($weights[$rate] ?? 0) + (float) ($line['line_total'] ?? 0);
        }
        if ($weights === []) {
            return 21;
        }
        arsort($weights);

        return (int) array_key_first($weights);
    }

    /** @param array<string, mixed> $line */
    public static function formatLineDescription(array $line): string
    {
        $designation = trim((string) ($line['designation'] ?? ''));
        $title = trim((string) ($line['title'] ?? ''));
        $description = trim((string) ($line['description'] ?? ''));

        $main = $designation !== '' && $title !== ''
            ? $designation . ' ' . $title
            : ($title !== '' ? $title : $designation);

        if ($description !== '' && $description !== $main) {
            return $main . "\n" . $description;
        }

        return $main !== '' ? $main : 'Položka';
    }

    /**
     * @return array{job: array<string, mixed>, variant: array<string, mixed>}
     */
    private function context(int $jobId, int $supplierId): array
    {
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null) {
            throw new \RuntimeException('Zakázka nenalezena.');
        }
        if (empty($job['approved_variant_id'])) {
            throw new \RuntimeException('Zakázka nemá odsouhlasenou variantu.');
        }
        if (empty($job['customer_client_id'])) {
            throw new \RuntimeException('Zakázka nemá přiřazeného zákazníka.');
        }
        $variant = $this->quotes->findVariant((int) $job['approved_variant_id'], $supplierId);
        if ($variant === null) {
            throw new \RuntimeException('Odsouhlasená varianta nenalezena.');
        }
        $job = $this->projects($supplierId)->ensureProjectForJob($job);
        if ((int) ($job['myucto_project_id'] ?? 0) <= 0) {
            throw new \RuntimeException('Zakázku se nepodařilo napojit na projekt MyÚčta.');
        }

        return ['job' => $job, 'variant' => $variant];
    }

    /** @param array<string, mixed> $job */
    private function dueDateFromIssue(string $issueDate, array $job): string
    {
        $days = 14;
        $clientId = (int) ($job['customer_client_id'] ?? 0);
        if ($clientId > 0) {
            // payment_due_default is not on job; ProjectGateway already used it for the project.
            $days = 14;
        }
        try {
            $d = new \DateTimeImmutable($issueDate);

            return $d->modify('+' . $days . ' days')->format('Y-m-d');
        } catch (\Exception) {
            return $issueDate;
        }
    }
}
