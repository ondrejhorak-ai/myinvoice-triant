<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

final class InvoiceGateway
{
    public function __construct(
        private readonly MyUctoClient $api,
        private readonly PDO $pdo,
        private readonly int $supplierId,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createDraft(array $body, ?string $commandKey = null): array
    {
        $input = $this->toInvoiceInput($body);
        $key = $commandKey ?? hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $existing = $this->findRecentCommand('invoice.create', $key);
        if ($existing !== null) {
            $status = (string) $existing['status'];
            $myuctoId = (int) ($existing['myucto_id'] ?? 0);
            if ($status === 'done' && $myuctoId > 0) {
                return $this->getDetail($myuctoId);
            }
            if (in_array($status, ['pending', 'unknown'], true)) {
                $found = $this->reconcileDraft($input);
                if ($found !== null) {
                    $this->finishCommand((int) $existing['id'], 'done', (int) $found['id']);
                    $this->upsertRemote($found);

                    return $this->present($found);
                }
                if ($status === 'pending') {
                    throw new MyUctoApiException(
                        'in_progress',
                        'Založení konceptu už běží. Zkuste to znovu za chvíli.',
                        409,
                    );
                }
            }
        }

        $cmdId = $this->insertCommand('invoice.create', $key, $input);
        try {
            $remote = ContactGateway::unwrap($this->api->createInvoice($input));
        } catch (MyUctoApiException $e) {
            if ($e->isUnavailable() || $e->errorCode === 'network' || $e->errorCode === 'timeout') {
                $this->finishCommand($cmdId, 'unknown', null, $e->getMessage());
                $found = $this->reconcileDraft($input);
                if ($found !== null) {
                    $this->finishCommand($cmdId, 'done', (int) $found['id']);
                    $this->upsertRemote($found);

                    return $this->present($found);
                }
                throw new MyUctoApiException('network', 'MyÚčto nedostupné', 503, $e, $e->details);
            }
            $this->finishCommand($cmdId, 'failed', null, $e->getMessage());
            throw $e;
        }

        $id = (int) ($remote['id'] ?? 0);
        $this->finishCommand($cmdId, $id > 0 ? 'done' : 'failed', $id > 0 ? $id : null);
        if ($id <= 0) {
            throw new MyUctoApiException('sync_failed', 'MyÚčto vrátilo fakturu bez id.', 502);
        }
        $this->upsertRemote($remote);

        return $this->present($remote);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateDraft(int $id, array $body): array
    {
        $current = $this->requireRemote($id);
        if ((string) ($current['status'] ?? '') !== 'draft') {
            throw new MyUctoApiException('not_draft', 'Upravit lze jen koncept.', 409);
        }
        $input = $this->toInvoiceInput($body, $current);
        try {
            $remote = ContactGateway::unwrap($this->api->updateInvoice($id, $input));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        $this->upsertRemote($remote);

        return $this->present($remote);
    }

    public function deleteDraft(int $id): void
    {
        $current = $this->requireRemote($id);
        if ((string) ($current['status'] ?? '') !== 'draft') {
            throw new MyUctoApiException('not_draft', 'Smazat lze jen koncept.', 409);
        }
        try {
            $this->api->deleteInvoice($id);
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        $now = (new MirrorWriter($this->pdo))->now();
        $this->pdo->prepare('UPDATE mu_invoices SET deleted_at = ?, mu_synced_at = ? WHERE id = ?')
            ->execute([$now, $now, $id]);
    }

    /** @return array<string, mixed> */
    public function getDetail(int $id): array
    {
        try {
            $remote = ContactGateway::unwrap($this->api->getInvoice($id));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        $this->upsertRemote($remote);

        return $this->present($remote);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function previewVarsymbol(array $query = []): array
    {
        if (isset($query['client_id'])) {
            $query['client_id'] = $this->resolveClientMyuctoId((int) $query['client_id']);
        }
        try {
            return $this->api->previewVarsymbol($query);
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
    }

    /** @return array{body: string, contentType: string} */
    public function pdf(int $id): array
    {
        try {
            return $this->api->getInvoicePdf($id);
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
    }

    /**
     * @return array{vat_rates: list<array<string, mixed>>, currencies: list<array<string, mixed>>, units: list<array<string, mixed>>, branding_profiles: list<array<string, mixed>>, jobs: list<array<string, mixed>>}
     */
    public function meta(): array
    {
        $vat = $this->pdo->query(
            'SELECT id, code, name, rate, is_active, raw_json FROM mu_vat_rates ORDER BY rate DESC, id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cur = $this->pdo->query(
            'SELECT id, code, name, is_active FROM mu_currencies ORDER BY code'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $units = $this->pdo->query(
            'SELECT id, code, name FROM mu_units ORDER BY code'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $brand = $this->pdo->query(
            'SELECT id, name, is_default FROM mu_branding_profiles ORDER BY is_default DESC, name'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $jobsStmt = $this->pdo->prepare(
            'SELECT id, number, title, myucto_project_id
               FROM tri_jobs
              WHERE supplier_id = ? AND archived_at IS NULL
              ORDER BY updated_at DESC
              LIMIT 500'
        );
        $jobsStmt->execute([$this->supplierId]);
        $jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $vatRates = [];
        foreach ($vat as $row) {
            $rate = CodebookSync::vatPercent($row);
            $vatRates[] = [
                'id' => (int) $row['id'],
                'code' => $row['code'],
                'name' => CodebookSync::vatName($row) ?? $row['name'],
                'rate_percent' => $rate,
                'is_active' => (bool) $row['is_active'],
                'is_default' => abs($rate - 21) < 0.01,
                'is_reverse_charge' => CodebookSync::vatIsReverseCharge($row),
            ];
        }
        $currencies = [];
        foreach ($cur as $row) {
            $code = (string) $row['code'];
            $currencies[] = [
                'id' => (int) $row['id'],
                'code' => $code,
                'name' => $row['name'],
                'label' => $code,
                'symbol' => $code,
                'decimals' => 2,
                'is_active' => (bool) $row['is_active'],
                'is_default' => $code === 'CZK',
            ];
        }
        $unitRows = [];
        foreach ($units as $row) {
            $code = (string) ($row['code'] ?? '');
            $unitRows[] = [
                'id' => (int) $row['id'],
                'code' => $code,
                'name' => $row['name'] ?? $code,
                'label' => $code !== '' ? $code : (string) ($row['name'] ?? ''),
            ];
        }
        $jobRows = [];
        foreach ($jobs as $row) {
            $jobRows[] = [
                'id' => (int) $row['id'],
                'number' => (string) $row['number'],
                'title' => (string) $row['title'],
                'myucto_project_id' => $row['myucto_project_id'] !== null ? (int) $row['myucto_project_id'] : null,
            ];
        }

        return [
            'vat_rates' => $vatRates,
            'currencies' => $currencies,
            'units' => $unitRows,
            'branding_profiles' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'is_default' => (bool) $r['is_default'],
            ], $brand),
            'jobs' => $jobRows,
        ];
    }

    public function vatRateIdForPercent(int $percent): int
    {
        $stmt = $this->pdo->query('SELECT id, rate, raw_json FROM mu_vat_rates WHERE is_active = 1');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if ((int) round(CodebookSync::vatPercent($row)) === $percent) {
                return (int) $row['id'];
            }
        }
        foreach ($rows as $row) {
            if (abs(CodebookSync::vatPercent($row) - $percent) < 0.01) {
                return (int) $row['id'];
            }
        }
        if ($rows !== []) {
            return (int) $rows[0]['id'];
        }

        throw new MyUctoApiException('no_vat_rates', 'Chybí číselník DPH z MyÚčta. Spusťte synchronizaci.', 409);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    public function toInvoiceInput(array $body, ?array $current = null): array
    {
        $clientRaw = $body['client_id'] ?? ($current['client_id'] ?? null);
        $out = [
            'client_id' => $this->resolveClientMyuctoId((int) $clientRaw),
        ];
        $items = $body['items'] ?? ($current['items'] ?? []);
        if (!is_array($items) || $items === []) {
            throw new MyUctoApiException('validation_failed', 'Faktura musí mít položky.', 422, null, [
                'items' => ['Faktura musí mít položky.'],
            ]);
        }
        $mapped = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc === '' && (float) ($item['unit_price_without_vat'] ?? 0) === 0.0) {
                continue;
            }
            $mapped[] = [
                'description' => $desc !== '' ? $desc : 'Položka',
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price_without_vat' => (float) ($item['unit_price_without_vat'] ?? 0),
                'vat_rate_id' => (int) ($item['vat_rate_id'] ?? 0),
                'unit' => (string) ($item['unit'] ?? 'ks'),
                'order_index' => (int) ($item['order_index'] ?? $i),
            ];
        }
        if ($mapped === []) {
            throw new MyUctoApiException('validation_failed', 'Faktura musí mít položky.', 422, null, [
                'items' => ['Faktura musí mít položky.'],
            ]);
        }
        $out['items'] = $mapped;

        $type = (string) ($body['invoice_type'] ?? ($current['invoice_type'] ?? 'invoice'));
        if (!in_array($type, ['invoice', 'proforma', 'credit_note', 'payment_calendar'], true)) {
            $type = 'invoice';
        }
        $out['invoice_type'] = $type;

        if (array_key_exists('varsymbol', $body)) {
            $vs = trim((string) $body['varsymbol']);
            if ($vs !== '') {
                $out['varsymbol'] = $vs;
            }
        } elseif ($current !== null) {
            $curVs = trim((string) ($current['varsymbol'] ?? ''));
            if ($curVs !== '') {
                $out['varsymbol'] = $curVs;
            }
        }

        foreach (['issue_date', 'due_date', 'tax_date', 'note_above_items', 'note_below_items', 'supplier_order_number'] as $key) {
            if (array_key_exists($key, $body)) {
                $val = $body[$key];
                $out[$key] = $val === null || $val === '' ? null : (string) $val;
            } elseif ($current !== null && array_key_exists($key, $current)) {
                $out[$key] = $current[$key];
            }
        }
        if (array_key_exists('advance_paid_amount', $body)) {
            $out['advance_paid_amount'] = (float) $body['advance_paid_amount'];
        } elseif ($current !== null && isset($current['advance_paid_amount'])) {
            $out['advance_paid_amount'] = (float) $current['advance_paid_amount'];
        }
        if (array_key_exists('branding_profile_id', $body) && $body['branding_profile_id']) {
            $out['branding_profile_id'] = (int) $body['branding_profile_id'];
        }

        $projectId = $body['project_id'] ?? ($body['myucto_project_id'] ?? null);
        if ($projectId === null && isset($body['tri_job_id']) && (int) $body['tri_job_id'] > 0) {
            $projectId = $this->projectIdForJob((int) $body['tri_job_id']);
        }
        if ($projectId !== null && $projectId !== '') {
            $out['project_id'] = (int) $projectId;
        } elseif ($current !== null && isset($current['project_id'])) {
            $out['project_id'] = $current['project_id'] !== null ? (int) $current['project_id'] : null;
        }

        $currency = $this->resolveCurrencyCode($body, $current);
        if ($currency !== null) {
            $out['currency'] = $currency;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $remote
     * @return array<string, mixed>
     */
    public function present(array $remote): array
    {
        $id = (int) ($remote['id'] ?? 0);
        $totals = is_array($remote['totals'] ?? null) ? $remote['totals'] : [];
        $client = is_array($remote['client'] ?? null) ? $remote['client'] : [];
        $items = $remote['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $myuctoClientId = (int) ($remote['client_id'] ?? ($client['id'] ?? 0));
        $localClientId = $this->localClientId($myuctoClientId);
        $job = $this->jobForProject((int) ($remote['project_id'] ?? 0), $id);
        $currencyCode = strtoupper((string) ($remote['currency'] ?? 'CZK'));
        $currencyId = $this->currencyIdByCode($currencyCode);
        $issueDate = $this->dateOnly($remote['issue_date'] ?? null) ?? date('Y-m-d');

        $presentedItems = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $presentedItems[] = [
                'id' => isset($item['id']) ? (int) $item['id'] : null,
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit' => (string) ($item['unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($item['unit_price_without_vat'] ?? 0),
                'vat_rate_id' => (int) ($item['vat_rate_id'] ?? 0),
                'vat_rate_snapshot' => isset($item['vat_rate'])
                    ? (float) $item['vat_rate']
                    : (isset($item['vat_rate_snapshot']) ? (float) $item['vat_rate_snapshot'] : null),
                'total_without_vat' => isset($item['total_without_vat']) ? (float) $item['total_without_vat'] : null,
                'total_vat' => isset($item['total_vat']) ? (float) $item['total_vat'] : null,
                'total_with_vat' => isset($item['total_with_vat']) ? (float) $item['total_with_vat'] : null,
                'order_index' => (int) ($item['order_index'] ?? $i),
            ];
        }

        $status = (string) ($remote['status'] ?? 'draft');
        $without = (float) ($totals['without_vat'] ?? ($remote['total_without_vat'] ?? 0));
        $vat = (float) ($totals['vat'] ?? ($remote['total_vat'] ?? 0));
        $with = (float) ($totals['with_vat'] ?? ($remote['total_with_vat'] ?? 0));
        $amountToPay = (float) ($remote['amount_to_pay'] ?? $with);
        $paidTotal = (float) ($remote['paid_total'] ?? 0);

        $parentId = isset($remote['parent_invoice_id']) && $remote['parent_invoice_id'] !== null
            ? (int) $remote['parent_invoice_id']
            : null;
        $parentInvoice = is_array($remote['parent_invoice'] ?? null) ? $remote['parent_invoice'] : null;
        if ($parentInvoice === null && $parentId !== null) {
            $parentInvoice = [
                'id' => $parentId,
                'invoice_type' => 'proforma',
            ];
        }

        return [
            'id' => $id,
            'varsymbol' => $remote['varsymbol'] ?? null,
            'invoice_type' => (string) ($remote['invoice_type'] ?? 'invoice'),
            'parent_invoice_id' => $parentId,
            'parent_invoice' => $parentInvoice,
            'final_invoice' => is_array($remote['final_invoice'] ?? null) ? $remote['final_invoice'] : null,
            'client_id' => $localClientId ?? $myuctoClientId,
            'client_myucto_id' => $myuctoClientId > 0 ? $myuctoClientId : null,
            'project_id' => isset($remote['project_id']) && $remote['project_id'] !== null ? (int) $remote['project_id'] : null,
            'issue_date' => $issueDate,
            'tax_date' => $this->dateOnly($remote['tax_date'] ?? null),
            'due_date' => $this->dateOnly($remote['due_date'] ?? null) ?? $issueDate,
            'currency_id' => $currencyId,
            'currency' => $currencyCode,
            'reverse_charge' => false,
            'prices_include_vat' => false,
            'income_tax_exempt' => false,
            'income_tax_exempt_reason' => null,
            'language' => 'cs',
            'note_above_items' => $remote['note_above_items'] ?? null,
            'note_below_items' => $remote['note_below_items'] ?? null,
            'revenue_category_id' => null,
            'recurring_template_id' => null,
            'advance_paid_amount' => (float) ($remote['advance_paid_amount'] ?? 0),
            'discount_percent' => 0,
            'payment_method' => 'bank_transfer',
            'auto_send_reminders' => false,
            'amount_to_pay' => $amountToPay,
            'paid_total' => $paidTotal,
            'payment_status' => $remote['payment_status'] ?? null,
            'total_without_vat' => $without,
            'total_vat' => $vat,
            'total_with_vat' => $with,
            // FE typ Invoice čte souhrn z vnořeného `totals` (InvoiceDetail render)
            'totals' => [
                'without_vat' => $without,
                'vat' => $vat,
                'with_vat' => $with,
                'rounding' => 0,
                'advance_paid_amount' => (float) ($remote['advance_paid_amount'] ?? 0),
                'amount_to_pay' => $amountToPay,
            ],
            'rounding' => 0,
            'status' => $status,
            'approval_status' => 'none',
            'approval_token' => null,
            'public_token' => $remote['public_token'] ?? null,
            'sent_at' => $remote['sent_at'] ?? null,
            'last_reminder_at' => $remote['last_reminder_at'] ?? null,
            'reminder_count' => (int) ($remote['reminder_count'] ?? 0),
            'paid_at' => $remote['paid_at'] ?? null,
            'cancelled_at' => $status === 'cancelled' ? ($remote['updated_at'] ?? null) : null,
            'pdf_path' => null,
            'created_at' => $remote['created_at'] ?? ($remote['updated_at'] ?? null),
            'updated_at' => $remote['updated_at'] ?? null,
            'client_company_name' => $client['company_name'] ?? ($remote['client_company_name'] ?? null),
            'client_main_email' => $client['main_email'] ?? ($remote['client_main_email'] ?? null),
            'supplier_order_number' => $remote['supplier_order_number'] ?? null,
            'branding_profile_id' => isset($remote['branding_profile_id']) ? (int) $remote['branding_profile_id'] : null,
            'items' => $presentedItems,
            'vat_breakdown' => $remote['vat_breakdown'] ?? [],
            'tri_job_id' => $job['id'] ?? null,
            'tri_job_number' => $job['number'] ?? null,
            'tri_job_title' => $job['title'] ?? null,
            'myucto' => true,
        ];
    }

    /** @param array<string, mixed> $remote */
    public function upsertRemote(array $remote): void
    {
        (new InvoiceSync($this->api, $this->pdo, new MirrorWriter($this->pdo)))->upsert($remote);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRemote(int $id): array
    {
        try {
            return ContactGateway::unwrap($this->api->getInvoice($id));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
    }

    public function resolveClientMyuctoId(int $id): int
    {
        if ($id <= 0) {
            throw new MyUctoApiException('validation_failed', 'Klient je povinný.', 422, null, [
                'client_id' => ['Klient je povinný.'],
            ]);
        }
        $stmt = $this->pdo->prepare(
            'SELECT myucto_id FROM clients WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $this->supplierId]);
        $mapped = $stmt->fetchColumn();
        if ($mapped !== false && $mapped !== null && (int) $mapped > 0) {
            return (int) $mapped;
        }
        $stmt = $this->pdo->prepare(
            'SELECT myucto_id FROM clients WHERE myucto_id = ? AND supplier_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $this->supplierId]);
        $direct = $stmt->fetchColumn();
        if ($direct !== false && $direct !== null && (int) $direct > 0) {
            return (int) $direct;
        }
        throw new MyUctoApiException(
            'not_linked',
            'Kontakt není napojený na MyÚčto (chybí myucto_id).',
            409,
        );
    }

    private function localClientId(int $myuctoId): ?int
    {
        if ($myuctoId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE myucto_id = ? AND supplier_id = ?');
        $stmt->execute([$myuctoId, $this->supplierId]);
        $id = $stmt->fetchColumn();

        return $id !== false && $id !== null ? (int) $id : null;
    }

    private function projectIdForJob(int $jobId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT myucto_project_id FROM tri_jobs WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$jobId, $this->supplierId]);
        $id = $stmt->fetchColumn();

        return $id !== false && $id !== null && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @return array{id:int, number:string, title:string}|null
     */
    private function jobForProject(int $projectId, int $invoiceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.id, j.number, j.title
               FROM tri_job_invoice_overrides o
               JOIN tri_jobs j ON j.id = o.job_id
              WHERE o.invoice_id = ? AND j.supplier_id = ?
              LIMIT 1'
        );
        $stmt->execute([$invoiceId, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return [
                'id' => (int) $row['id'],
                'number' => (string) $row['number'],
                'title' => (string) $row['title'],
            ];
        }
        if ($projectId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, number, title FROM tri_jobs
              WHERE myucto_project_id = ? AND supplier_id = ?
              LIMIT 1'
        );
        $stmt->execute([$projectId, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'title' => (string) $row['title'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $current
     */
    private function resolveCurrencyCode(array $body, ?array $current): ?string
    {
        if (isset($body['currency']) && is_string($body['currency']) && $body['currency'] !== '') {
            return strtoupper(substr($body['currency'], 0, 3));
        }
        if (isset($body['currency_id']) && (int) $body['currency_id'] > 0) {
            $stmt = $this->pdo->prepare('SELECT code FROM mu_currencies WHERE id = ?');
            $stmt->execute([(int) $body['currency_id']]);
            $code = $stmt->fetchColumn();
            if (is_string($code) && $code !== '') {
                return strtoupper(substr($code, 0, 3));
            }
        }
        if ($current !== null && isset($current['currency'])) {
            return strtoupper(substr((string) $current['currency'], 0, 3));
        }

        return 'CZK';
    }

    private function currencyIdByCode(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mu_currencies WHERE code = ?');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();

        return $id !== false && $id !== null ? (int) $id : 0;
    }

    private function dateOnly(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m) === 1) {
            return $m[1];
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function reconcileDraft(array $input): ?array
    {
        $query = [
            'per_page' => 50,
            'filter' => [
                'status' => 'draft',
                'client_id' => $input['client_id'],
            ],
        ];
        if (!empty($input['project_id'])) {
            $query['filter']['project_id'] = $input['project_id'];
        }
        try {
            $payload = $this->api->listInvoices($query);
        } catch (MyUctoApiException) {
            return null;
        }
        $order = (string) ($input['supplier_order_number'] ?? '');
        $type = (string) ($input['invoice_type'] ?? 'invoice');
        $best = null;
        foreach (ApiPage::items($payload) as $row) {
            if ((string) ($row['invoice_type'] ?? '') !== $type) {
                continue;
            }
            if ($order !== '' && (string) ($row['supplier_order_number'] ?? '') !== $order) {
                continue;
            }
            $best = $row;
        }

        return is_array($best) ? $best : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecentCommand(string $kind, string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, status, myucto_id, payload_json
               FROM mu_commands
              WHERE kind = ? AND payload_json LIKE ?
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$kind, '%"key":"' . $key . '"%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $input */
    private function insertCommand(string $kind, string $key, array $input): int
    {
        $payload = json_encode(
            ['key' => $key, 'input' => $input],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $now = (new MirrorWriter($this->pdo))->now();
        $this->pdo->prepare(
            'INSERT INTO mu_commands (kind, payload_json, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$kind, $payload, 'pending', $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    private function finishCommand(int $id, string $status, ?int $myuctoId, ?string $error = null): void
    {
        $now = (new MirrorWriter($this->pdo))->now();
        $this->pdo->prepare(
            'UPDATE mu_commands SET status = ?, myucto_id = ?, error = ?, updated_at = ? WHERE id = ?'
        )->execute([$status, $myuctoId, $error, $now, $id]);
    }

    public function issue(int $id): array
    {
        return $this->mutate($id, fn () => $this->api->issueInvoice($id), true);
    }

    /**
     * Daňový doklad k přijaté záloze: proforma (paid) → nový finální doklad.
     * Vrací NOVÝ doklad (jiné id), proto ne-idempotentní (žádný retry na „already").
     *
     * @return array<string, mixed>
     */
    public function issueFinal(int $id): array
    {
        return $this->mutate($id, fn () => $this->api->issueFinal($id), false);
    }

    /** @return array<string, mixed> */
    public function recipients(int $id): array
    {
        try {
            return $this->api->getRecipients($id);
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function send(int $id, array $body): array
    {
        $payload = [];
        foreach (['to', 'cc', 'bcc', 'note', 'subject'] as $key) {
            if (array_key_exists($key, $body)) {
                $payload[$key] = $body[$key];
            }
        }
        return $this->mutate($id, fn () => $this->api->sendInvoice($id, $payload), true);
    }

    public function reminder(int $id): array
    {
        return $this->mutate($id, fn () => $this->api->sendReminder($id), true);
    }

    /** @return array<string, mixed> */
    public function publicLink(int $id): array
    {
        try {
            $remote = ContactGateway::unwrap($this->api->getPublicLink($id));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        if (isset($remote['id'])) {
            $this->upsertRemote($remote);
        }

        return $remote;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function markPaid(int $id, array $body = []): array
    {
        $payload = [];
        if (!empty($body['paid_at']) || !empty($body['paid_on'])) {
            $payload['paid_at'] = (string) ($body['paid_at'] ?? $body['paid_on']);
        }
        // Odpověď mark-paid bývá zkrácená; list-sync by pak mohl přepsat unpaid.
        // Vždy načti detail, ať mirror drží paid_at / payment_status.
        return $this->mutate($id, function () use ($id, $payload) {
            $this->api->markPaid($id, $payload);

            return $this->api->getInvoice($id);
        }, true);
    }

    public function unmarkPaid(int $id): array
    {
        return $this->mutate($id, function () use ($id) {
            $this->api->unmarkPaid($id);

            return $this->api->getInvoice($id);
        }, true);
    }

    /** @return array<string, mixed> */
    public function payments(int $id): array
    {
        try {
            return $this->api->listPayments($id);
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function addPayment(int $id, array $body): array
    {
        return $this->mutate($id, fn () => $this->api->addPayment($id, $body), true);
    }

    public function deletePayment(int $id, int $paymentId): array
    {
        return $this->mutate($id, fn () => $this->api->deletePayment($id, $paymentId), true);
    }

    public function cloneInvoice(int $id): array
    {
        return $this->mutate($id, fn () => $this->api->cloneInvoice($id), false);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function linkAdvance(int $id, array $body): array
    {
        return $this->mutate($id, fn () => $this->api->linkAdvance($id, $body), true);
    }

    /**
     * @param callable(): array<string, mixed> $call
     * @return array<string, mixed>
     */
    private function mutate(int $id, callable $call, bool $idempotent): array
    {
        try {
            $remote = ContactGateway::unwrap($call());
        } catch (MyUctoApiException $e) {
            $this->mapPeriodLocked($e);
            if ($idempotent && $this->isAlreadyDone($e)) {
                return $this->getDetail($id);
            }
            $this->mapUnavailable($e);
            throw $e;
        }
        if (!isset($remote['id'])) {
            return $this->getDetail($id);
        }
        $this->upsertRemote($remote);

        return $this->present($remote);
    }

    private function mapPeriodLocked(MyUctoApiException $e): void
    {
        $code = strtolower($e->errorCode . ' ' . $e->getMessage());
        if (
            $e->httpStatus === 403 || $e->httpStatus === 409
        ) {
            if (
                str_contains($code, 'period') || str_contains($code, 'obdob')
                || str_contains($code, 'locked') || str_contains($code, 'uzav')
                || str_contains($code, 'closed')
            ) {
                throw new MyUctoApiException(
                    'period_locked',
                    'Období je uzavřeno, kontaktujte účetní.',
                    $e->httpStatus,
                    $e,
                    $e->details,
                );
            }
        }
    }

    private function isAlreadyDone(MyUctoApiException $e): bool
    {
        $hay = strtolower($e->errorCode . ' ' . $e->getMessage());
        foreach (['already', 'already_issued', 'already_sent', 'already_paid', 'not_draft', 'invalid_status'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function mapUnavailable(MyUctoApiException $e): void
    {
        if ($e->isUnavailable() || $e->errorCode === 'network') {
            throw new MyUctoApiException('network', 'MyÚčto nedostupné', 503, $e, $e->details);
        }
    }
}
