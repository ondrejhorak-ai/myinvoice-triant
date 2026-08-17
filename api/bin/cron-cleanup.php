<?php

declare(strict_types=1);

/**
 * Denní cleanup — login_attempts (>24h), expirované a staré odvolané sessions,
 * použité password_resets, login_otps + trusted_devices, WebAuthn flow/proofy
 * a log files >90 dní.
 *
 * POZN: PDF se NEMAŽE. Aktivní cache může pominout (renderer ji znovu vytvoří),
 * ale archivovaná historie (storage/invoices/sup-N/_archive) obsahuje verze
 * skutečně odeslané klientovi a je důkazem fakturace.
 *
 * Použití (Windows Task Scheduler):
 *   php api/bin/cron-cleanup.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$pdo     = (new Connection($config))->pdo();

$run = CronRun::start($pdo, 'cron-cleanup');
$startedAt = microtime(true);
$report = [];

// 1) login_attempts — drop záznamy starší 24 hodin
$n = $pdo->exec("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 24 HOUR");
$report['login_attempts'] = (int) $n;

// 2) sessions — TIMESTAMP expires_at porovnáváme s CURRENT_TIMESTAMP, aby
// MariaDB správně zohlednila session timezone. UTC DATETIME revoked_at naopak
// porovnáváme s UTC_TIMESTAMP. Nahrazené generace bez revoked_at držíme jako
// logout tombstones až do jejich absolutní expirace.
$n = $pdo->exec('DELETE FROM sessions WHERE expires_at < CURRENT_TIMESTAMP(6)');
$report['expired_sessions'] = (int) $n;
$n = $pdo->exec(
    'DELETE FROM sessions
      WHERE revoked_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY'
);
$report['revoked_sessions'] = (int) $n;

// 2b) WebAuthn ceremonies a MFA proofy — po expiraci/spotřebování drž nejvýše 1 den.
$n = $pdo->exec(
    'DELETE FROM webauthn_ceremonies
      WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY
         OR used_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY'
);
$report['webauthn_ceremonies'] = (int) $n;
$n = $pdo->exec(
    'DELETE FROM mfa_step_up_proofs
      WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY
         OR used_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY'
);
$report['mfa_step_up_proofs'] = (int) $n;

// 3) password_resets — použité nebo expirované >7 dní
$n = $pdo->exec("DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW() - INTERVAL 7 DAY");
$report['password_resets'] = (int) $n;

// 3b) login_otps (e-mailové 2FA kódy) — použité/expirované >1 den
$n = $pdo->exec("DELETE FROM login_otps WHERE used_at < NOW() - INTERVAL 1 DAY OR expires_at < NOW() - INTERVAL 1 DAY");
$report['login_otps'] = (int) $n;

// 3c) trusted_devices — expirovaná „zapamatovaná zařízení"
$n = $pdo->exec("DELETE FROM trusted_devices WHERE expires_at < NOW()");
$report['trusted_devices'] = (int) $n;

// 4) ARES/VIES cache — starší 30 dní
$n = $pdo->exec("DELETE FROM ares_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['ares_cache'] = (int) $n;
$n = $pdo->exec("DELETE FROM vies_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['vies_cache'] = (int) $n;

// 5) Log files — Monolog rotuje, ale když je config zapnutý max_files, držíme se ho
$logDir = (string) $config->get('logging.path', $rootDir . '/log/app.log');
$logDir = dirname($logDir);
$maxFiles = (int) $config->get('logging.max_files', 90);
$logDeleted = 0;
if (is_dir($logDir)) {
    $files = glob($logDir . '/*.log') ?: [];
    if (count($files) > $maxFiles) {
        usort($files, fn ($a, $b) => filemtime($a) - filemtime($b));
        $toDel = array_slice($files, 0, count($files) - $maxFiles);
        foreach ($toDel as $f) if (@unlink($f)) $logDeleted++;
    }
}
$report['log_files'] = $logDeleted;

// 6) Měsíční exporty — smaž dokončené/neúspěšné/zrušené joby starší 7 dní
//    vč. jejich ZIP souboru (retence: do ručního smazání, jinak reaper po 7 dnech).
$exportBase = ($config->dataDir() ?? $rootDir) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'monthly-exports';
$stmt = $pdo->query(
    "SELECT id, result_path FROM import_jobs
      WHERE source = 'monthly_export'
        AND status IN ('completed', 'failed', 'cancelled')
        AND COALESCE(finished_at, created_at) < NOW() - INTERVAL 7 DAY"
);
$exportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$exportFilesDeleted = 0;
$exportIds = [];
foreach ($exportRows as $r) {
    $exportIds[] = (int) $r['id'];
    $rel = (string) ($r['result_path'] ?? '');
    if ($rel === '') continue;
    $abs = realpath($exportBase . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
    $baseReal = realpath($exportBase);
    // Path-traversal guard — maž jen v rámci storage/monthly-exports.
    if ($abs !== false && $baseReal !== false && is_file($abs)
        && str_starts_with(strtolower($abs), strtolower($baseReal) . DIRECTORY_SEPARATOR)) {
        if (@unlink($abs)) $exportFilesDeleted++;
    }
}
if ($exportIds !== []) {
    $in = implode(',', array_fill(0, count($exportIds), '?'));
    $pdo->prepare("DELETE FROM import_jobs WHERE id IN ($in)")->execute($exportIds);
}
$report['monthly_export_jobs']  = count($exportIds);
$report['monthly_export_files'] = $exportFilesDeleted;

// 7) Nepoužité náhledy položek nabídek. Prodleva chrání čerstvě nahrané
// obrázky, které uživatel ještě nepřipojil běžným uložením varianty.
$quoteImagesDeleted = 0;
$quoteImageFilesDeleted = 0;
try {
    $rows = $pdo->query(
        "SELECT qi.id, qi.supplier_id, qi.stored_name
           FROM tri_quote_images qi
      LEFT JOIN tri_quote_line_items li ON li.image_id = qi.id
          WHERE li.id IS NULL AND qi.last_uploaded_at < NOW() - INTERVAL 24 HOUR"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $delete = $pdo->prepare(
        'DELETE qi FROM tri_quote_images qi
          LEFT JOIN tri_quote_line_items li ON li.image_id = qi.id
         WHERE qi.id = ? AND li.id IS NULL AND qi.last_uploaded_at < NOW() - INTERVAL 24 HOUR'
    );
    foreach ($rows as $row) {
        $delete->execute([(int) $row['id']]);
        if ($delete->rowCount() !== 1) continue;
        $quoteImagesDeleted++;
        $name = (string) $row['stored_name'];
        if (!preg_match('/\A[a-f0-9]{64}\.jpg\z/', $name)) continue;
        $base = realpath(RuntimePaths::storage('tri-quote-images') . '/sup-' . (int) $row['supplier_id']);
        $path = realpath(RuntimePaths::storage('tri-quote-images') . '/sup-' . (int) $row['supplier_id'] . '/' . $name);
        if ($base !== false && $path !== false && is_file($path)
            && str_starts_with(strtolower($path), strtolower($base) . DIRECTORY_SEPARATOR)
            && @unlink($path)) {
            $quoteImageFilesDeleted++;
        }
    }

    // Druhá vrstva uklidí osiřelé soubory po případném pádu mezi zápisem
    // souboru a DB nebo po dřívějším neúspěšném unlinku.
    $known = [];
    foreach ($pdo->query('SELECT supplier_id, stored_name FROM tri_quote_images')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $known[(int) $row['supplier_id'] . '/' . (string) $row['stored_name']] = true;
    }
    $storageRoot = RuntimePaths::storage('tri-quote-images');
    foreach (glob($storageRoot . '/sup-*/*.jpg') ?: [] as $path) {
        if (!preg_match('~[/\\]sup-([0-9]+)[/\\]([a-f0-9]{64}\.jpg)\z~', $path, $m)) continue;
        if (isset($known[(int) $m[1] . '/' . $m[2]])) continue;
        if ((int) @filemtime($path) >= time() - 86400) continue;
        $rootReal = realpath($storageRoot);
        $pathReal = realpath($path);
        if ($rootReal !== false && $pathReal !== false && is_file($pathReal)
            && str_starts_with(strtolower($pathReal), strtolower($rootReal) . DIRECTORY_SEPARATOR)
            && @unlink($pathReal)) {
            $quoteImageFilesDeleted++;
        }
    }
} catch (PDOException) {
    // Instalace před migrací 9011 — cleanup nesmí zablokovat ostatní úlohy.
}
$report['tri_quote_images'] = $quoteImagesDeleted;
$report['tri_quote_image_files'] = $quoteImageFilesDeleted;

// Pročisti cron_runs — drž max 500 posledních záznamů na skript.
$report['cron_runs_purged'] = CronRun::purgeOld($pdo, 500);

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "[" . date('Y-m-d H:i:s') . "] cron-cleanup ({$ms} ms): " . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";

// Audit do activity_log
$pdo->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.cleanup', ?)"
)->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish('ok', $report);
