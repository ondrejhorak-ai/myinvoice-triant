<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TriSidebarSettingsAction
{
    private const META_KEY = 'hidden_sidebar_modules';

    private const ALLOWED_MODULE_IDS = [
        'dashboard',
        'invoices',
        'recurring',
        'clients',
        'projects',
        'approvals',
        'exports',
        'imports-issued',
        'purchase-invoices',
        'vendors',
        'purchase-export',
        'imports-purchase',
        'ai-import',
        'crm',
        'stats',
        'purchase-stats',
        'bank',
        'reports-dph',
        'reports-kh',
        'reports-dph-book',
        'reports-shv',
        'reports-income-tax',
        'reports-submissions',
        'reports-monthly-export',
        'settings',
        'codebooks',
        'integrations',
        'users',
        'email-templates',
        'activity-log',
        'cron-jobs',
        'updates',
        'api-tokens',
        'help',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        return Json::ok($response, [
            'hidden_modules' => $this->loadHiddenModules(),
        ]);
    }

    public function put(Request $request, Response $response): Response
    {
        if (!$this->guardAdmin($request, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $rawModules = $body['hidden_modules'] ?? null;
        if (!is_array($rawModules)) {
            return Json::error($response, 'validation_failed', 'hidden_modules musí být pole.', 400);
        }

        $normalized = [];
        $known = array_flip(self::ALLOWED_MODULE_IDS);
        foreach ($rawModules as $item) {
            if (!is_string($item)) {
                return Json::error($response, 'validation_failed', 'Každý modul musí být string.', 400);
            }
            if (!isset($known[$item])) {
                return Json::error($response, 'validation_failed', "Neznámý modul '$item'.", 400);
            }
            $normalized[$item] = true;
        }
        $hidden = array_keys($normalized);
        sort($hidden);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        $stmt->execute([self::META_KEY, json_encode($hidden, JSON_UNESCAPED_UNICODE)]);

        $this->log($request, 'settings.tri_sidebar.updated', ['hidden_modules' => $hidden]);

        return Json::ok($response, ['hidden_modules' => $hidden]);
    }

    /** @return string[] */
    private function loadHiddenModules(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT v FROM app_meta WHERE k = ? LIMIT 1');
        $stmt->execute([self::META_KEY]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') return [];

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded)) return [];

        $known = array_flip(self::ALLOWED_MODULE_IDS);
        $out = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) continue;
            if (!isset($known[$item])) continue;
            $out[$item] = true;
        }
        return array_keys($out);
    }

    private function guardAdmin(Request $request, Response $response, ?Response &$err): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'app_meta', null, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
