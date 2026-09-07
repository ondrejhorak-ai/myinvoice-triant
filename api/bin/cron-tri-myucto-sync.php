<?php

declare(strict_types=1);

/**
 * Pull zrcadla MyÚčta do mu_* (klienti, projekty, faktury, číselníky).
 * Docker crontab: kazdych 5 minut.
 */

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\SyncRunner;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);
$run     = CronRun::start($conn->pdo(), 'cron-tri-myucto-sync');

$client = new MyUctoClient($config);
if (!$client->isEnabled()) {
    echo "[cron-tri-myucto-sync] skipped (disabled)\n";
    $run->finish('ok', ['skipped' => 'disabled']);
    exit(0);
}

$full = in_array('--full', $argv ?? [], true);
$supplierId = (int) $config->get('app.default_supplier_id', 1);
if ($supplierId <= 0) {
    $supplierId = 1;
}

try {
    $n = (new SyncRunner($client, $conn->pdo(), $supplierId))->run($full);
    echo "[cron-tri-myucto-sync] OK n={$n}\n";
    $run->finish('ok', ['records' => $n, 'full' => $full]);
} catch (MyUctoApiException $e) {
    fwrite(STDERR, "[cron-tri-myucto-sync] FAILED: {$e->getMessage()}\n");
    $run->finish('error', ['code' => $e->errorCode], $e->getMessage(), 1);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "[cron-tri-myucto-sync] FAILED: {$e->getMessage()}\n");
    $run->finish('error', [], $e->getMessage(), 1);
    exit(1);
}
