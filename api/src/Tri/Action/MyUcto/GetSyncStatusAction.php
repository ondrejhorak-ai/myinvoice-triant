<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\MyUcto;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\Support\TriRequest;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetSyncStatusAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly MyUctoClient $client,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (TriRequest::userRole($request) !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $pdo = $this->db->pdo();
        $health = null;
        $healthError = null;
        if ($this->client->isEnabled()) {
            try {
                $health = $this->client->health();
            } catch (MyUctoApiException $e) {
                $healthError = $e->getMessage();
            }
        }

        $state = [];
        try {
            $state = $pdo->query('SELECT `key`, last_run_at, last_ok_at, sync_cursor, last_error FROM mu_sync_state ORDER BY `key`')
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $state = [];
        }

        $log = [];
        try {
            $log = $pdo->query('SELECT id, kind, direction, myucto_id, http_status, message, created_at FROM mu_sync_log ORDER BY id DESC LIMIT 50')
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $log = [];
        }

        $count = static function (PDO $pdo, string $sql): int {
            try {
                $v = $pdo->query($sql)->fetchColumn();
                return $v !== false ? (int) $v : 0;
            } catch (\Throwable) {
                return 0;
            }
        };

        return Json::ok($response, [
            'enabled' => $this->client->isEnabled(),
            'base_url' => (string) $this->config->get('tri.myucto.base_url', ''),
            'public_url' => $this->client->publicUrl(),
            'health' => $health,
            'health_error' => $healthError,
            'rate_limit' => $this->client->lastRateLimit(),
            'sync_state' => $state,
            'recent_log' => $log,
            'counts' => [
                'clients' => $count($pdo, 'SELECT COUNT(*) FROM clients WHERE myucto_id IS NOT NULL AND archived_at IS NULL'),
                'projects' => $count($pdo, 'SELECT COUNT(*) FROM mu_projects WHERE deleted_at IS NULL'),
                'invoices' => $count($pdo, 'SELECT COUNT(*) FROM mu_invoices WHERE deleted_at IS NULL'),
                'vat_rates' => $count($pdo, 'SELECT COUNT(*) FROM mu_vat_rates'),
                'currencies' => $count($pdo, 'SELECT COUNT(*) FROM mu_currencies'),
                'units' => $count($pdo, 'SELECT COUNT(*) FROM mu_units'),
            ],
        ]);
    }
}
