<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use MyInvoice\Repository\ClientRepository;
use PDO;

final class ContactGateway
{
    public function __construct(
        private readonly MyUctoClient $api,
        private readonly PDO $pdo,
        private readonly ClientRepository $clients,
        private readonly int $supplierId,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(array $body): array
    {
        try {
            $remote = self::unwrap($this->api->createClient(self::toClientInput($body)));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        (new ClientSync($this->api, $this->pdo, $this->supplierId))->upsert($remote);
        return $this->localByMyuctoId((int) ($remote['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(int $localId, array $body): array
    {
        $myuctoId = $this->requireMyuctoId($localId);
        try {
            $remote = self::unwrap($this->api->updateClient($myuctoId, self::toClientInput($body)));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        (new ClientSync($this->api, $this->pdo, $this->supplierId))->upsert($remote);
        return $this->localByMyuctoId((int) ($remote['id'] ?? $myuctoId));
    }

    /** @return array<string, mixed> */
    public function archive(int $localId): array
    {
        $myuctoId = $this->requireMyuctoId($localId);
        try {
            $remote = self::unwrap($this->api->archiveClient($myuctoId));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        if ($remote === [] || !isset($remote['id'])) {
            $remote = ['id' => $myuctoId, 'archived_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')];
            $existing = $this->clients->find($localId);
            if (is_array($existing)) {
                $remote = array_merge($existing, $remote, ['id' => $myuctoId]);
            }
        }
        (new ClientSync($this->api, $this->pdo, $this->supplierId))->upsert($remote);
        $row = $this->clients->find($localId);

        return is_array($row) ? $row : ['ok' => true];
    }

    /** @return array<string, mixed> */
    public function unarchive(int $localId): array
    {
        $myuctoId = $this->requireMyuctoId($localId);
        try {
            $remote = self::unwrap($this->api->unarchiveClient($myuctoId));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
        if (isset($remote['id'])) {
            $remote['archived_at'] = null;
            (new ClientSync($this->api, $this->pdo, $this->supplierId))->upsert($remote);
        } else {
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $this->pdo->prepare('UPDATE clients SET archived_at = NULL, mu_synced_at = ? WHERE id = ?')
                ->execute([$now, $localId]);
        }
        $row = $this->clients->find($localId);

        return is_array($row) ? $row : ['ok' => true];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function lookupAres(array $body): array
    {
        $ic = preg_replace('/\D/', '', (string) ($body['ic'] ?? '')) ?? '';
        if (strlen($ic) !== 8) {
            throw new MyUctoApiException('invalid_ic', 'IČO musí mít 8 číslic.', 400);
        }
        try {
            return self::unwrap($this->api->lookupAres(['ic' => $ic]));
        } catch (MyUctoApiException $e) {
            $this->mapUnavailable($e);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function toClientInput(array $body): array
    {
        $out = [
            'company_name' => trim((string) ($body['company_name'] ?? '')),
            'street' => trim((string) ($body['street'] ?? '')),
            'city' => trim((string) ($body['city'] ?? '')),
            'zip' => trim((string) ($body['zip'] ?? '')),
        ];
        foreach (['first_name', 'last_name', 'ic', 'dic', 'country_iso2', 'main_email', 'phone'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $val = $body[$key];
            if ($val === null || $val === '') {
                $out[$key] = null;
                continue;
            }
            $out[$key] = is_string($val) ? trim($val) : $val;
        }
        if (isset($body['country_iso2']) && is_string($body['country_iso2'])) {
            $out['country_iso2'] = strtoupper(substr($body['country_iso2'], 0, 2));
        }
        foreach (['is_customer', 'is_vendor'] as $flag) {
            if (array_key_exists($flag, $body)) {
                $out[$flag] = (bool) $body[$flag];
            }
        }
        if (array_key_exists('payment_due_default', $body) && $body['payment_due_default'] !== null && $body['payment_due_default'] !== '') {
            $out['payment_due_default'] = (int) $body['payment_due_default'];
        }
        if (isset($body['email_contacts']) && is_array($body['email_contacts'])) {
            $out['email_contacts'] = $body['email_contacts'];
        }

        return $out;
    }

    private function requireMyuctoId(int $localId): int
    {
        $stmt = $this->pdo->prepare('SELECT myucto_id FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$localId, $this->supplierId]);
        $id = $stmt->fetchColumn();
        if ($id === false || $id === null || (int) $id <= 0) {
            throw new MyUctoApiException(
                'not_linked',
                'Kontakt není napojený na MyÚčto (chybí myucto_id). Spusťte synchronizaci.',
                409,
            );
        }

        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function localByMyuctoId(int $myuctoId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE myucto_id = ?');
        $stmt->execute([$myuctoId]);
        $localId = (int) $stmt->fetchColumn();
        $row = $localId > 0 ? $this->clients->find($localId) : null;
        if (!is_array($row)) {
            throw new MyUctoApiException('sync_failed', 'Kontakt se v MyÚčtu založil, ale lokální zrcadlo selhalo.', 500);
        }

        return $row;
    }

    private function mapUnavailable(MyUctoApiException $e): void
    {
        if ($e->isUnavailable() || $e->errorCode === 'network') {
            throw new MyUctoApiException('network', 'MyÚčto nedostupné', 503, $e, $e->details);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function unwrap(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data']) && isset($payload['data']['id'])) {
            return $payload['data'];
        }

        return $payload;
    }
}
