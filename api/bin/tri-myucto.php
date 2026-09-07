<?php

declare(strict_types=1);

/**
 * CLI pro MyÚčto integraci.
 *
 *   php api/bin/tri-myucto.php ping
 *   php api/bin/tri-myucto.php status
 *   php api/bin/tri-myucto.php sync [--full]
 *   php api/bin/tri-myucto.php contract
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\SyncRunner;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$cmd     = $argv[1] ?? 'help';

$client = new MyUctoClient($config);

try {
    match ($cmd) {
        'ping' => ping($client),
        'status' => status($config, $client),
        'sync' => sync($config, $client, in_array('--full', $argv, true)),
        'contract' => contract($client),
        default => help(),
    };
} catch (MyUctoApiException $e) {
    fwrite(STDERR, sprintf("[%s] %s (HTTP %s)\n", $e->errorCode, $e->getMessage(), $e->httpStatus ?: '–'));
    exit(1);
}

function help(): void
{
    echo "tri-myucto.php ping|status|sync [--full]|contract\n";
}

function ping(MyUctoClient $client): void
{
    if (!$client->isEnabled()) {
        throw new MyUctoApiException('disabled', 'TRI_MYUCTO_ENABLED=0 nebo chybí token.', 503);
    }
    $health = $client->health();
    $me     = $client->apiMe();
    $rl     = $client->lastRateLimit();
    $supplier = is_array($me['supplier'] ?? null) ? $me['supplier'] : [];
    $token    = is_array($me['token'] ?? null) ? $me['token'] : [];
    echo sprintf(
        "MyÚčto %s  status=%s  db=%s\nFirma: %s (id %s)\nToken: %s  scope=%s\nRate-limit: remaining=%s/%s  reset=%s  api=%s\n",
        (string) ($health['version'] ?? '?'),
        (string) ($health['status'] ?? '?'),
        !empty($health['db']) ? 'ok' : 'fail',
        (string) ($supplier['company_name'] ?? '?'),
        (string) ($supplier['id'] ?? '?'),
        (string) ($token['prefix'] ?? '?'),
        (string) ($token['scope'] ?? '?'),
        $rl['remaining'] !== '' ? $rl['remaining'] : '?',
        $rl['limit'] !== '' ? $rl['limit'] : '?',
        $rl['reset'] !== '' ? $rl['reset'] : '?',
        $rl['api_version'] !== '' ? $rl['api_version'] : '?',
    );
}

function status(Config $config, MyUctoClient $client): void
{
    echo sprintf(
        "enabled=%s  base=%s  public=%s  token=%s\n",
        $client->isEnabled() ? 'yes' : 'no',
        (string) $config->get('tri.myucto.base_url', ''),
        $client->publicUrl(),
        $client->isEnabled() ? 'set' : 'missing',
    );
    if (!class_exists(SyncRunner::class, false) && !class_exists(SyncRunner::class)) {
        echo "sync: not installed yet\n";
        return;
    }
    try {
        $pdo = (new Connection($config))->pdo();
        $rows = $pdo->query('SELECT `key`, last_run_at, last_ok_at, last_error FROM mu_sync_state ORDER BY `key`')->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "sync: no runs yet\n";
            return;
        }
        foreach ($rows as $row) {
            echo sprintf(
                "  %s  last_ok=%s  error=%s\n",
                $row['key'],
                $row['last_ok_at'] ?? '–',
                $row['last_error'] ? (string) $row['last_error'] : '–',
            );
        }
    } catch (\Throwable $e) {
        echo 'sync: ' . $e->getMessage() . "\n";
    }
}

function sync(Config $config, MyUctoClient $client, bool $full): void
{
    if (!class_exists(SyncRunner::class)) {
        fwrite(STDERR, "SyncRunner ještě není nainstalovaný (fáze H2).\n");
        exit(2);
    }
    $pdo = (new Connection($config))->pdo();
    $supplierId = (int) $config->get('app.default_supplier_id', 1);
    $n = (new SyncRunner($client, $pdo, $supplierId > 0 ? $supplierId : 1))->run($full);
    echo "sync ok: {$n} záznamů\n";
}

function contract(MyUctoClient $client): void
{
    if (!$client->isEnabled()) {
        throw new MyUctoApiException('disabled', 'TRI_MYUCTO_ENABLED=0 nebo chybí token.', 503);
    }
    $checks = [
        'health' => fn () => $client->health(),
        'api-me' => fn () => $client->apiMe(),
        'clients' => fn () => $client->listClients(['per_page' => 5]),
        'projects' => fn () => $client->listProjects(['per_page' => 5]),
        'invoices' => fn () => $client->listInvoices(['per_page' => 5]),
        'vat-rates' => fn () => $client->vatRates(),
        'codebooks/units' => fn () => $client->codebook('units'),
    ];
    $failed = 0;
    foreach ($checks as $name => $fn) {
        try {
            $fn();
            echo "OK  {$name}\n";
        } catch (MyUctoApiException $e) {
            $failed++;
            echo sprintf("FAIL %s  [%s] %s\n", $name, $e->errorCode, $e->getMessage());
        }
    }
    exit($failed > 0 ? 1 : 0);
}
