<?php

declare(strict_types=1);

/**
 * Doplní vzorová data k existujícím Zakázkám TRI (varianty, položky, faktury).
 *
 *   php api/bin/tri-demo-enrich.php
 *   php api/bin/tri-demo-enrich.php --yes
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\TriDemoEnricher;

$autoYes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);

$app = Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(Connection::class)->pdo();

$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
if ($adminId === 0 || $supplierId === 0) {
    fwrite(STDERR, "[tri-demo] Chybí admin nebo supplier.\n");
    exit(1);
}

echo "================================================\n";
echo "  TRI — doplnění vzorových dat k zakázkám\n";
echo "================================================\n";
echo "  Supplier: #$supplierId, Admin: #$adminId\n";
echo "================================================\n\n";

if (!$autoYes) {
    echo "Pokračovat? (ANO): ";
    if (trim((string) fgets(STDIN)) !== 'ANO') {
        echo "Zrušeno.\n";
        exit(0);
    }
}

$enricher = $container->get(TriDemoEnricher::class);
try {
    $r = $enricher->enrich($supplierId, $adminId);
} catch (\Throwable $e) {
    fwrite(STDERR, '[tri-demo] Selhalo: ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

printf(
    "Hotovo: %d zakázek, %d variant doplněno položkami, %d faktur vytvořeno.\n",
    $r['jobs'],
    $r['variants_filled'],
    $r['invoices_created'],
);
