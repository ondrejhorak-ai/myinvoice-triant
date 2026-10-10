<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

use MyInvoice\Tri\MyUcto\ApiPage;
use MyInvoice\Tri\MyUcto\ClientSync;
use MyInvoice\Tri\MyUcto\ContactGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use PDO;

/**
 * Odběratele založí nebo spojí v MyÚčtu až při prvním dokladu.
 * Kontaktní osoba se neposílá. Neúplná adresa ani výpadek MyÚčta doklad nevystaví
 * a řádek v Office zůstane.
 */
final class BillingContactPublisher
{
    public function __construct(
        private readonly MyUctoClient $api,
        private readonly PDO $pdo,
        private readonly int $supplierId,
    ) {}

    public function ensure(int $id): int
    {
        if ($id <= 0) {
            throw new MyUctoApiException('validation_failed', 'Klient je povinný.', 422, null, [
                'client_id' => ['Klient je povinný.'],
            ]);
        }
        $local = $this->findId($id);
        if ($local !== null && (int) ($local['myucto_id'] ?? 0) > 0) {
            return (int) $local['myucto_id'];
        }
        if ($local !== null) {
            return $this->pushUnlinked((int) $local['id']);
        }
        $direct = $this->findByMyuctoId($id);
        if ($direct > 0) {
            return $direct;
        }
        throw new MyUctoApiException(
            'not_linked',
            'Kontakt není napojený na MyÚčto (chybí myucto_id).',
            409,
        );
    }

    private function pushUnlinked(int $localId): int
    {
        $row = $this->load($localId);
        if ($row === null) {
            throw new MyUctoApiException('not_found', 'Kontakt nenalezen.', 404);
        }
        $kind = (string) ($row['party_kind'] ?? ContactParty::COMPANY);
        if ($kind === ContactParty::PERSON) {
            throw new MyUctoApiException(
                'person_not_billable',
                'Kontaktní osoba se do MyÚčta neposílá. Na dokladu vyberte firmu nebo soukromou osobu.',
                422,
            );
        }
        if (!ContactParty::billingReady($row)) {
            throw new MyUctoApiException(
                'billing_incomplete',
                'Odběratel ještě nemá název, ulici, město a PSČ. Doklad se nevystaví.',
                422,
                null,
                [
                    'street' => ['Před dokladem doplňte ulici, město a PSČ.'],
                ],
            );
        }

        $ic = IcoMatch::digits((string) ($row['ic'] ?? ''));
        if (strlen($ic) === 8) {
            $found = $this->searchRemote($ic);
            $remoteIds = IcoMatch::exactIds($ic, $found);
            if (count($remoteIds) > 1) {
                throw new MyUctoApiException(
                    'ambiguous_ic',
                    'V MyÚčtu je víc odběratelů se stejným IČO. Vyberte kartu ručně, nic se nespojilo.',
                    409,
                );
            }
            if (count($remoteIds) === 1) {
                $match = ['id' => $remoteIds[0]];
                foreach ($found as $item) {
                    if ((int) ($item['id'] ?? 0) === $remoteIds[0]) {
                        $match = $item;
                        break;
                    }
                }

                return $this->link($localId, $match);
            }
        }

        return $this->createRemote($localId, $row);
    }

    /** @return list<array<string, mixed>> */
    private function searchRemote(string $ic): array
    {
        try {
            $payload = $this->api->listClients(['q' => $ic, 'per_page' => 50]);
        } catch (MyUctoApiException $e) {
            $this->failClosed($e);
            throw $e;
        }

        return ApiPage::items($payload);
    }

    /** @param array<string, mixed> $remote */
    private function link(int $localId, array $remote): int
    {
        $remoteId = (int) ($remote['id'] ?? 0);
        if ($remoteId <= 0) {
            throw new MyUctoApiException('sync_failed', 'MyÚčto vrátilo odběratele bez id.', 502);
        }
        $this->assignMyuctoId($localId, $remoteId);
        (new ClientSync($this->api, $this->pdo, $this->supplierId))->upsert($remote);

        return $remoteId;
    }

    /** @param array<string, mixed> $row */
    private function createRemote(int $localId, array $row): int
    {
        $input = ContactGateway::toClientInput($row);
        if (!isset($input['country_iso2']) || $input['country_iso2'] === '') {
            $input['country_iso2'] = 'CZ';
        }
        try {
            $remote = ContactGateway::unwrap($this->api->createClient($input));
        } catch (MyUctoApiException $e) {
            $this->failClosed($e);
            throw $e;
        }
        $remoteId = (int) ($remote['id'] ?? 0);
        if ($remoteId <= 0) {
            throw new MyUctoApiException('sync_failed', 'MyÚčto vrátilo odběratele bez id.', 502);
        }
        $this->assignMyuctoId($localId, $remoteId);
        (new ClientSync($this->api, $this->pdo, $this->supplierId))->upsert($remote);

        return $remoteId;
    }

    private function assignMyuctoId(int $localId, int $remoteId): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE clients SET myucto_id = ? WHERE id = ? AND supplier_id = ? AND (myucto_id IS NULL OR myucto_id = ?)'
            )->execute([$remoteId, $localId, $this->supplierId, $remoteId]);
        } catch (\PDOException) {
            // Jediné IČO už drží jiná lokální karta. Doklad jde na toho odběratele v MyÚčtu.
        }
    }

    private function failClosed(MyUctoApiException $e): void
    {
        if ($e->isUnavailable() || in_array($e->errorCode, ['network', 'disabled', 'unconfigured', 'timeout'], true)) {
            throw new MyUctoApiException('network', 'MyÚčto nedostupné', 503, $e, $e->details);
        }
    }

    /** @return array{id: int, myucto_id: int|null}|null */
    private function findId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, myucto_id FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'myucto_id' => $row['myucto_id'] !== null ? (int) $row['myucto_id'] : null,
        ];
    }

    private function findByMyuctoId(int $myuctoId): int
    {
        $stmt = $this->pdo->prepare('SELECT myucto_id FROM clients WHERE myucto_id = ? AND supplier_id = ? LIMIT 1');
        $stmt->execute([$myuctoId, $this->supplierId]);
        $id = $stmt->fetchColumn();

        return $id !== false && $id !== null ? (int) $id : 0;
    }

    /** @return array<string, mixed>|null */
    private function load(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, party_kind, company_name, first_name, last_name, ic, dic, street, city, zip,
                    main_email, phone, is_customer, is_vendor, payment_due_default, myucto_id
               FROM clients WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['country_iso2'] = 'CZ';
        $row['myucto_id'] = $row['myucto_id'] !== null ? (int) $row['myucto_id'] : null;

        return $row;
    }
}
