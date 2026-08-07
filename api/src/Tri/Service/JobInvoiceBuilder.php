<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Repository\QuoteRepository;

final class JobInvoiceBuilder
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly QuoteRepository $quotes,
        private readonly JobInvoiceRepository $jobInvoices,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceDefaults $defaults,
    ) {}

    /**
     * @param array{percent?: float|null, amount?: float|null, text?: string|null} $options
     * @return array{invoice_id: int, invoice: array<string, mixed>}
     */
    public function buildAdvanceDraft(int $jobId, int $supplierId, int $userId, array $options = []): array
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

        $vatPercent = $this->dominantVatRate($variant['line_items'] ?? []);
        $vatRateId = $this->vatRateIdForPercent($vatPercent);
        $netUnit = round($targetGross / (1 + $vatPercent / 100) / 1, 2);

        $today = date('Y-m-d');
        $payload = $this->defaults->resolve([
            'invoice_type' => 'proforma',
            'client_id'    => (int) $job['customer_client_id'],
            'issue_date'   => $today,
            'due_date'     => $this->defaultDueDate((int) $job['customer_client_id'], $today),
        ]);

        $invoiceId = $this->invoices->createDraft($payload, $userId);
        $this->invoices->replaceItems($invoiceId, [[
            'description'            => $text,
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => $netUnit,
            'vat_rate_id'            => $vatRateId,
            'order_index'            => 0,
        ]]);
        $this->calculator->recompute($invoiceId);
        $this->jobInvoices->link($invoiceId, $jobId, $supplierId);

        $invoice = $this->invoices->find($invoiceId);
        assert($invoice !== null);

        return ['invoice_id' => $invoiceId, 'invoice' => $invoice];
    }

    /**
     * @return array{invoice_id: int, invoice: array<string, mixed>}
     */
    public function buildFinalDraft(int $jobId, int $supplierId, int $userId): array
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

        $lineItems = $variant['line_items'] ?? [];
        if ($lineItems === []) {
            throw new \RuntimeException('Varianta nemá žádné položky.');
        }

        $linesSubtotal = 0.0;
        foreach ($lineItems as $line) {
            $linesSubtotal += (float) ($line['line_total'] ?? 0);
        }
        $linesSubtotal = round($linesSubtotal, 2);
        $variantSubtotal = (float) $variant['subtotal'];
        $ratio = $linesSubtotal > 0 ? $variantSubtotal / $linesSubtotal : 1.0;

        $invoiceItems = [];
        foreach (array_values($lineItems) as $i => $line) {
            $qty = (float) ($line['quantity'] ?? 1);
            if ($qty <= 0) {
                continue;
            }
            $lineTotal = round((float) ($line['line_total'] ?? 0) * $ratio, 2);
            $unitNet = round($lineTotal / $qty, 2);
            $desc = $this->formatLineDescription($line);
            $vatPercent = (int) ($line['vat_rate'] ?? 21);

            $invoiceItems[] = [
                'description'            => $desc,
                'quantity'               => $qty,
                'unit'                   => (string) ($line['unit'] ?? 'ks'),
                'unit_price_without_vat' => $unitNet,
                'vat_rate_id'            => $this->vatRateIdForPercent($vatPercent),
                'order_index'            => $i,
            ];
        }

        if ($invoiceItems === []) {
            throw new \RuntimeException('Varianta nemá fakturovatelné položky.');
        }

        $advancePaid = $this->jobInvoices->paidAdvancesTotal($jobId, $supplierId);
        $today = date('Y-m-d');

        $payload = $this->defaults->resolve([
            'invoice_type'        => 'invoice',
            'client_id'           => (int) $job['customer_client_id'],
            'issue_date'          => $today,
            'tax_date'            => $today,
            'due_date'            => $this->defaultDueDate((int) $job['customer_client_id'], $today),
            'advance_paid_amount' => $advancePaid,
            'note_above_items'    => $variant['note_above_items'] ?? null,
            'note_below_items'    => $variant['note_below_items'] ?? null,
        ]);

        $invoiceId = $this->invoices->createDraft($payload, $userId);
        $this->invoices->replaceItems($invoiceId, $invoiceItems);
        $this->calculator->recompute($invoiceId);
        $this->jobInvoices->link($invoiceId, $jobId, $supplierId);

        $invoice = $this->invoices->find($invoiceId);
        assert($invoice !== null);

        return ['invoice_id' => $invoiceId, 'invoice' => $invoice];
    }

    /** @param list<array<string, mixed>> $lines */
    private function dominantVatRate(array $lines): int
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
    private function formatLineDescription(array $line): string
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

    private function vatRateIdForPercent(int $percent): int
    {
        $map = $this->invoices->vatRateMap();
        foreach ($map as $id => $rate) {
            if ((int) round((float) $rate) === $percent) {
                return (int) $id;
            }
        }
        foreach ($map as $id => $rate) {
            if (abs((float) $rate - $percent) < 0.01) {
                return (int) $id;
            }
        }

        return (int) (array_key_first($map) ?: 1);
    }

    private function defaultDueDate(int $clientId, string $issueDate): string
    {
        $resolved = $this->defaults->resolve([
            'client_id'  => $clientId,
            'issue_date' => $issueDate,
        ]);

        return (string) ($resolved['due_date'] ?? $issueDate);
    }
}
