<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Contact\BillingContactPublisher;
use MyInvoice\Tri\Contact\JobContactOrder;
use MyInvoice\Tri\MyUcto\InvoiceGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
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
     * @param array{percent?: float|null, amount?: float|null, text?: string|null, client_id?: int|null} $options
     * @return array{invoice_id: int, invoice: array<string, mixed>}
     */
    public function buildAdvanceDraft(int $jobId, int $supplierId, int $userId, array $options = []): array
    {
        $ctx = $this->context($jobId, $supplierId, self::requestedClientId($options));
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
        $this->rememberJob((int) $invoice['id'], $jobId, $supplierId);

        return ['invoice_id' => (int) $invoice['id'], 'invoice' => $invoice];
    }

    /**
     * @param array{client_id?: int|null} $options
     * @return array{invoice_id: int, invoice: array<string, mixed>}
     */
    public function buildFinalDraft(int $jobId, int $supplierId, int $userId, array $options = []): array
    {
        $ctx = $this->context($jobId, $supplierId, self::requestedClientId($options));
        $variant = $ctx['variant'];
        $job = $ctx['job'];
        $lineItems = $variant['line_items'] ?? [];
        if ($lineItems === []) {
            throw new \RuntimeException('Varianta nemá žádné položky.');
        }

        $gw = $this->invoices($supplierId);
        $variantNet = isset($variant['subtotal']) ? (float) $variant['subtotal'] : null;
        $items = self::finalItems(
            $lineItems,
            fn (int $percent) => $gw->vatRateIdForPercent($percent),
            $variantNet,
        );
        if ($items === []) {
            throw new \RuntimeException('Varianta nemá fakturovatelné položky.');
        }

        $advances = $this->jobInvoices->paidAdvances($jobId, $supplierId);
        $advancePaid = $advances['total'];
        $today = date('Y-m-d');
        $noteAbove = trim((string) ($variant['note_above_items'] ?? ''));
        if (!str_contains($noteAbove, 'TRI-SCEN')) {
            $noteAbove = trim('TRI-SCEN S5 doplatek — obj. ' . $job['number'] . ($noteAbove !== '' ? "\n" . $noteAbove : ''));
        }
        $input = self::finalInput(
            clientId: (int) $job['customer_client_id'],
            projectId: (int) $job['myucto_project_id'],
            orderNumber: (string) $job['number'],
            issueDate: $today,
            dueDate: $this->dueDateFromIssue($today, $job),
            items: $items,
            advancePaid: $advancePaid,
            noteAbove: $noteAbove !== '' ? $noteAbove : null,
            noteBelow: $variant['note_below_items'] ?? null,
        );
        $keySuffix = $variantNet !== null ? (string) round($variantNet, 2) : '0';
        $keySuffix .= ':adv:' . (string) round($advancePaid, 2);
        $invoice = $gw->createDraft($input, 'job:' . $jobId . ':final:' . $keySuffix);
        $invoiceId = (int) $invoice['id'];
        $this->rememberJob($invoiceId, $jobId, $supplierId);
        $parentId = self::settlementParentId($advances['ids']);
        if ($parentId > 0) {
            try {
                $invoice = $gw->linkAdvance($invoiceId, ['advance_id' => $parentId]);
            } catch (MyUctoApiException) {
                // MyÚčto spojí zálohu jen se stejným odběratelem. Částka odpočtu
                // na konceptu už je. Vystavená záloha se nemění.
            }
        }

        return ['invoice_id' => $invoiceId, 'invoice' => $invoice];
    }

    /**
     * @param list<array<string, mixed>> $lineItems
     * @param callable(int): int $vatRateIdForPercent
     * @return list<array<string, mixed>>
     */
    public static function finalItems(array $lineItems, callable $vatRateIdForPercent, ?float $variantNetTotal = null): array
    {
        $linesSubtotal = 0.0;
        foreach ($lineItems as $line) {
            $linesSubtotal += (float) ($line['line_total'] ?? 0);
        }
        $linesSubtotal = round($linesSubtotal, 2);
        $targetNet = $variantNetTotal !== null ? round($variantNetTotal, 2) : $linesSubtotal;
        $ratio = ($linesSubtotal > 0 && $targetNet >= 0) ? ($targetNet / $linesSubtotal) : 1.0;

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
        $input = [
            'invoice_type' => 'proforma',
            'client_id' => $clientId,
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
        if ($projectId > 0) {
            $input['project_id'] = $projectId;
        }

        return $input;
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
        $input = [
            'invoice_type' => 'invoice',
            'client_id' => $clientId,
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
        if ($projectId > 0) {
            $input['project_id'] = $projectId;
        }

        return $input;
    }

    /**
     * MyÚčto má na vyúčtování jednoho rodiče (parent_invoice_id = záloha).
     * Více zaplacených záloh se sečte do částky, ale jedna z nich se rodičem nestane.
     *
     * @param list<int> $advanceIds
     */
    public static function settlementParentId(array $advanceIds): int
    {
        $ids = [];
        foreach ($advanceIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return count($ids) === 1 ? (int) array_key_first($ids) : 0;
    }

    private function rememberJob(int $invoiceId, int $jobId, int $supplierId): void
    {
        if ($invoiceId <= 0 || $jobId <= 0) {
            return;
        }
        $this->jobInvoices->link($invoiceId, $jobId, $supplierId);
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
    private function context(int $jobId, int $supplierId, ?int $requestedClientId = null): array
    {
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null) {
            throw new \RuntimeException('Zakázka nenalezena.');
        }
        if (empty($job['approved_variant_id'])) {
            throw new \RuntimeException('Zakázka nemá odsouhlasenou variantu.');
        }
        if ($requestedClientId !== null && $requestedClientId > 0) {
            $this->jobs->appendContact($jobId, $supplierId, $requestedClientId);
            $job = $this->jobs->find($jobId, $supplierId);
            if ($job === null) {
                throw new \RuntimeException('Zakázka nenalezena.');
            }
        }
        $clientId = self::resolveBillingClientId($job, $requestedClientId);
        if ($clientId <= 0) {
            throw new \RuntimeException('Vyberte odběratele.');
        }
        $job['customer_client_id'] = $clientId;
        $variant = $this->quotes->findVariant((int) $job['approved_variant_id'], $supplierId);
        if ($variant === null) {
            throw new \RuntimeException('Odsouhlasená varianta nenalezena.');
        }
        (new BillingContactPublisher($this->api, $this->db->pdo(), $supplierId))
            ->ensure($clientId);
        $job = $this->projectForChosenClient($job, $supplierId, $clientId);

        return ['job' => $job, 'variant' => $variant];
    }

    /**
     * Projekt zakázky jde na doklad jen když patří stejnému odběrateli.
     * Jinak se nepošle. Uložené myucto_project_id se kvůli tomu nemění.
     * Když zakázka projekt ještě nemá, založí se pro zvolený kontakt.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function projectForChosenClient(array $job, int $supplierId, int $clientId): array
    {
        $storedProjectId = (int) ($job['myucto_project_id'] ?? 0);
        if ($storedProjectId > 0) {
            $job['myucto_project_id'] = self::projectIdForBilling(
                $storedProjectId,
                $this->projectClientMyuctoId($storedProjectId),
                $this->localClientMyuctoId($clientId, $supplierId),
            );

            return $job;
        }

        $job = $this->projects($supplierId)->ensureProjectForJob($job);
        if ((int) ($job['myucto_project_id'] ?? 0) <= 0) {
            throw new \RuntimeException('Zakázku se nepodařilo napojit na projekt MyÚčta.');
        }

        return $job;
    }

    private function projectClientMyuctoId(int $projectId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT client_myucto_id FROM mu_projects WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$projectId]);
        $id = $stmt->fetchColumn();

        return $id === false || $id === null ? 0 : (int) $id;
    }

    private function localClientMyuctoId(int $clientId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT myucto_id FROM clients WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$clientId, $supplierId]);
        $id = $stmt->fetchColumn();

        return $id === false || $id === null ? 0 : (int) $id;
    }

    /** @param array{client_id?: int|null} $options */
    private static function requestedClientId(array $options): ?int
    {
        $id = (int) ($options['client_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Výchozí odběratel nové faktury a zálohy: první firma, jinak první osoba.
     * Uložené customer_client_id se použije jen když zakázka žádný kontakt nemá.
     * Nemění řádek zakázky ani už vystavený doklad.
     *
     * @param array<string, mixed> $job
     */
    public static function billingClientId(array $job): int
    {
        return self::resolveBillingClientId($job, null);
    }

    /**
     * Zvolený kontakt z okna má přednost. Jinak první firma, jinak první osoba.
     * Uložené customer_client_id se použije jen když zakázka žádný kontakt nemá
     * a okno nikoho nevybralo.
     *
     * @param array<string, mixed> $job
     */
    public static function resolveBillingClientId(array $job, ?int $requestedClientId): int
    {
        if ($requestedClientId !== null && $requestedClientId > 0) {
            return $requestedClientId;
        }
        $contacts = is_array($job['contacts'] ?? null) ? $job['contacts'] : [];
        $fromContacts = JobContactOrder::billingClientId($contacts);
        if ($fromContacts > 0) {
            return $fromContacts;
        }

        return (int) ($job['customer_client_id'] ?? 0);
    }

    /**
     * Číslo projektu na doklad, jen když patří stejnému odběrateli v MyÚčtu.
     * Nula znamená projekt na doklad nepřidávat.
     */
    public static function projectIdForBilling(int $jobProjectId, int $projectClientMyuctoId, int $billingMyuctoId): int
    {
        if ($jobProjectId <= 0) {
            return 0;
        }
        if ($projectClientMyuctoId > 0 && $billingMyuctoId > 0 && $projectClientMyuctoId === $billingMyuctoId) {
            return $jobProjectId;
        }

        return 0;
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
