<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tri\MyUcto\ContactGateway;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use PDO;

/** Uložení kontaktu jen v Office. MyÚčto se volá až u napojeného odběratele při úpravě, nebo při dokladu. */
final class OfficeContactStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClientRepository $clients,
        private readonly int $supplierId,
        private readonly ?MyUctoClient $api = null,
    ) {}

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function create(array $body): array
    {
        $errors = ContactParty::errors($body);
        if ($errors !== []) {
            throw new ContactValidationException($errors);
        }
        $kind = ContactParty::kind($body);
        $companyId = $this->companyLink($kind, (int) ($body['company_client_id'] ?? 0), null);
        $id = $this->clients->create($this->payload($body, $kind), $this->supplierId);
        $this->applyParty($id, $kind, $companyId, $body);

        return $this->require($id);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function update(int $id, array $body): array
    {
        $existing = $this->require($id);
        $fallback = (string) ($existing['party_kind'] ?? ContactParty::COMPANY);
        if (!isset($body['party_kind']) || $body['party_kind'] === '') {
            $body['party_kind'] = $fallback;
        }
        $errors = ContactParty::errors($body, $fallback);
        if ($errors !== []) {
            throw new ContactValidationException($errors);
        }
        $kind = ContactParty::kind($body, $fallback);
        $linked = (int) ($existing['myucto_id'] ?? 0) > 0;
        if ($linked && $kind === ContactParty::PERSON) {
            throw new ContactValidationException([
                'party_kind' => ['Napojeného odběratele nelze změnit na kontaktní osobu.'],
            ]);
        }
        $companyId = $this->companyLink($kind, (int) ($body['company_client_id'] ?? 0), $id);
        if ($linked && $this->api !== null && ContactParty::isBillable($kind)) {
            $gw = new ContactGateway($this->api, $this->pdo, $this->clients, $this->supplierId);
            $gw->update($id, $this->payload($body, $kind));
        } else {
            $this->clients->update($id, $this->payload($body, $kind));
        }
        $this->applyParty($id, $kind, $companyId, $body);

        return $this->require($id);
    }

    /** @return array<string, mixed> */
    public function setArchived(int $id, bool $archived): array
    {
        $existing = $this->require($id);
        $linked = (int) ($existing['myucto_id'] ?? 0) > 0
            && (string) ($existing['party_kind'] ?? ContactParty::COMPANY) !== ContactParty::PERSON;
        if ($linked && $this->api !== null) {
            $gw = new ContactGateway($this->api, $this->pdo, $this->clients, $this->supplierId);

            return $archived ? $gw->archive($id) : $gw->unarchive($id);
        }
        $this->pdo->prepare('UPDATE clients SET archived_at = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$archived ? date('Y-m-d H:i:s') : null, $id, $this->supplierId]);

        return $this->require($id);
    }

    /**
     * Návrhy při psaní. Nic není předvolené.
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(string $q, string $email, string $phone, string $ic, int $excludeId = 0): array
    {
        $q = trim($q);
        $email = trim($email);
        $phone = trim($phone);
        $ic = IcoMatch::digits($ic);
        if ($q === '' && $email === '' && $phone === '' && $ic === '') {
            return [];
        }
        $nameLike = mb_strlen($q) >= 2 ? '%' . addcslashes($q, '%_\\') . '%' : '';
        $stmt = $this->pdo->prepare(
            'SELECT id, company_name, party_kind, main_email, phone, ic, myucto_id, company_client_id
               FROM clients
              WHERE supplier_id = ? AND archived_at IS NULL AND id <> ?
                AND (
                  (? <> \'\' AND ic = ?)
                  OR (? <> \'\' AND main_email = ?)
                  OR (? <> \'\' AND phone = ?)
                  OR (? <> \'\' AND company_name LIKE ?)
                )
              ORDER BY company_name
              LIMIT 8'
        );
        $stmt->execute([
            $this->supplierId,
            $excludeId,
            $ic, $ic,
            $email, $email,
            $phone, $phone,
            $nameLike, $nameLike,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $match = 'name';
            if ($ic !== '' && IcoMatch::digits((string) ($row['ic'] ?? '')) === $ic) {
                $match = 'ic';
            } elseif ($email !== '' && strcasecmp((string) ($row['main_email'] ?? ''), $email) === 0) {
                $match = 'email';
            } elseif ($phone !== '' && (string) ($row['phone'] ?? '') === $phone) {
                $match = 'phone';
            }
            $out[] = [
                'id' => (int) $row['id'],
                'company_name' => (string) $row['company_name'],
                'party_kind' => (string) ($row['party_kind'] ?? ContactParty::COMPANY),
                'main_email' => $row['main_email'] !== null ? (string) $row['main_email'] : null,
                'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
                'ic' => $row['ic'] !== null ? (string) $row['ic'] : null,
                'myucto_id' => $row['myucto_id'] !== null ? (int) $row['myucto_id'] : null,
                'company_client_id' => $row['company_client_id'] !== null ? (int) $row['company_client_id'] : null,
                'match' => $match,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function peopleOf(int $companyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, company_name, main_email, phone
               FROM clients
              WHERE supplier_id = ? AND company_client_id = ? AND party_kind = ? AND archived_at IS NULL
              ORDER BY company_name'
        );
        $stmt->execute([$this->supplierId, $companyId, ContactParty::PERSON]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'company_name' => (string) $row['company_name'],
            'main_email' => $row['main_email'] !== null ? (string) $row['main_email'] : null,
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
        ], $rows);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function payload(array $body, string $kind): array
    {
        $ic = $kind === ContactParty::COMPANY ? ContactParty::blank($body['ic'] ?? null) : ContactParty::blank($body['ic'] ?? null);
        if ($kind === ContactParty::PERSON) {
            $ic = null;
        }
        if ($ic !== null) {
            $digits = IcoMatch::digits($ic);
            $ic = $digits !== '' ? $digits : null;
        }
        $isPerson = $kind === ContactParty::PERSON;

        return [
            'company_name' => trim((string) ($body['company_name'] ?? '')),
            'first_name' => ContactParty::blank($body['first_name'] ?? null),
            'last_name' => ContactParty::blank($body['last_name'] ?? null),
            'ic' => $ic,
            'dic' => $isPerson ? null : ContactParty::blank($body['dic'] ?? null),
            'tax_number' => $isPerson ? null : ContactParty::blank($body['tax_number'] ?? null),
            'street' => ContactParty::blank($body['street'] ?? null) ?? '',
            'city' => ContactParty::blank($body['city'] ?? null) ?? '',
            'zip' => ContactParty::blank($body['zip'] ?? null) ?? '',
            'country_iso2' => strtoupper(substr((string) ($body['country_iso2'] ?? 'CZ'), 0, 2)) ?: 'CZ',
            'main_email' => ContactParty::blank($body['main_email'] ?? null),
            'phone' => ContactParty::blank($body['phone'] ?? null),
            'language' => in_array(($body['language'] ?? 'cs'), ['cs', 'en'], true) ? $body['language'] : 'cs',
            'currency_default_id' => isset($body['currency_default_id']) ? (int) $body['currency_default_id'] : null,
            'reverse_charge' => !empty($body['reverse_charge']),
            'is_vat_payer' => array_key_exists('is_vat_payer', $body) ? (bool) $body['is_vat_payer'] : true,
            'is_customer' => $isPerson ? true : (!array_key_exists('is_customer', $body) || (bool) $body['is_customer']),
            'is_vendor' => $isPerson ? false : !empty($body['is_vendor']),
            'auto_send_reminders' => !array_key_exists('auto_send_reminders', $body) || (bool) $body['auto_send_reminders'],
            'payment_due_default' => $body['payment_due_default'] ?? null,
            'payment_due_unit' => $body['payment_due_unit'] ?? null,
            'hourly_rate' => $body['hourly_rate'] ?? 0,
            'note' => ContactParty::blank($body['note'] ?? null),
            'default_expense_category_id' => $body['default_expense_category_id'] ?? null,
            'default_revenue_category_id' => $body['default_revenue_category_id'] ?? null,
            'invoice_number_format' => $body['invoice_number_format'] ?? null,
            'proforma_number_format' => $body['proforma_number_format'] ?? null,
            'credit_note_number_format' => $body['credit_note_number_format'] ?? null,
            'invoice_number_period' => $body['invoice_number_period'] ?? null,
        ];
    }

    /** @param array<string, mixed> $body */
    private function applyParty(int $id, string $kind, ?int $companyId, array $body): void
    {
        $street = ContactParty::blank($body['street'] ?? null);
        $city = ContactParty::blank($body['city'] ?? null);
        $zip = ContactParty::blank($body['zip'] ?? null);
        $customer = $kind === ContactParty::PERSON ? 0 : null;
        $this->pdo->prepare(
            'UPDATE clients
                SET party_kind = ?, company_client_id = ?, street = ?, city = ?, zip = ?,
                    is_customer = COALESCE(?, is_customer), is_vendor = CASE WHEN ? = \'person\' THEN 0 ELSE is_vendor END
              WHERE id = ? AND supplier_id = ?'
        )->execute([$kind, $companyId, $street, $city, $zip, $customer, $kind, $id, $this->supplierId]);
    }

    private function companyLink(string $kind, int $companyId, ?int $selfId): ?int
    {
        if ($kind !== ContactParty::PERSON || $companyId <= 0) {
            return null;
        }
        if ($selfId !== null && $companyId === $selfId) {
            throw new ContactValidationException([
                'company_client_id' => ['Osoba nemůže být navázaná sama na sebe.'],
            ]);
        }
        $stmt = $this->pdo->prepare(
            'SELECT party_kind FROM clients WHERE id = ? AND supplier_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$companyId, $this->supplierId]);
        $party = $stmt->fetchColumn();
        if ($party === false || (string) $party !== ContactParty::COMPANY) {
            throw new ContactValidationException([
                'company_client_id' => ['Kontaktní osobu lze navázat jen na firmu.'],
            ]);
        }

        return $companyId;
    }

    /** @return array<string, mixed> */
    private function require(int $id): array
    {
        $row = $this->clients->find($id);
        if (!is_array($row) || (int) ($row['supplier_id'] ?? 0) !== $this->supplierId) {
            throw new ContactValidationException(['id' => ['Kontakt nenalezen.']], 'Kontakt nenalezen.');
        }

        return $row;
    }
}
