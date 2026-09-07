<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

final class SyncState
{
    public function __construct(private readonly PDO $pdo) {}

    public function markRun(string $key): void
    {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->upsert($key, [
            'last_run_at' => $now,
        ]);
    }

    public function markOk(string $key, ?string $syncCursor = null): void
    {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->upsert($key, [
            'last_run_at' => $now,
            'last_ok_at' => $now,
            'sync_cursor' => $syncCursor,
            'last_error' => null,
        ]);
    }

    public function markError(string $key, string $error): void
    {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->upsert($key, [
            'last_run_at' => $now,
            'last_error' => mb_substr($error, 0, 2000),
        ]);
    }

    public function lastOkAt(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT last_ok_at FROM mu_sync_state WHERE `key` = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();

        return $val !== false && $val !== null && $val !== '' ? (string) $val : null;
    }

    public function log(string $kind, string $direction, ?int $myuctoId, ?int $httpStatus, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mu_sync_log (kind, direction, myucto_id, http_status, message) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$kind, $direction, $myuctoId, $httpStatus, mb_substr($message, 0, 500)]);
    }

    /**
     * @param array{last_run_at?:?string, last_ok_at?:?string, sync_cursor?:?string, last_error?:?string} $fields
     */
    private function upsert(string $key, array $fields): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $clearError = array_key_exists('last_error', $fields);
        if ($driver === 'sqlite') {
            $errorSql = $clearError
                ? 'last_error = excluded.last_error'
                : 'last_error = mu_sync_state.last_error';
            $stmt = $this->pdo->prepare(
                "INSERT INTO mu_sync_state (`key`, last_run_at, last_ok_at, sync_cursor, last_error)
                 VALUES (:key, :last_run_at, :last_ok_at, :sync_cursor, :last_error)
                 ON CONFLICT(`key`) DO UPDATE SET
                   last_run_at = COALESCE(excluded.last_run_at, mu_sync_state.last_run_at),
                   last_ok_at = COALESCE(excluded.last_ok_at, mu_sync_state.last_ok_at),
                   sync_cursor = COALESCE(excluded.sync_cursor, mu_sync_state.sync_cursor),
                   {$errorSql}"
            );
        } else {
            $errorSql = $clearError
                ? 'last_error = VALUES(last_error)'
                : 'last_error = last_error';
            $stmt = $this->pdo->prepare(
                "INSERT INTO mu_sync_state (`key`, last_run_at, last_ok_at, sync_cursor, last_error)
                 VALUES (:key, :last_run_at, :last_ok_at, :sync_cursor, :last_error)
                 ON DUPLICATE KEY UPDATE
                   last_run_at = COALESCE(VALUES(last_run_at), last_run_at),
                   last_ok_at = COALESCE(VALUES(last_ok_at), last_ok_at),
                   sync_cursor = COALESCE(VALUES(sync_cursor), sync_cursor),
                   {$errorSql}"
            );
        }
        $stmt->execute([
            'key' => $key,
            'last_run_at' => $fields['last_run_at'] ?? null,
            'last_ok_at' => $fields['last_ok_at'] ?? null,
            'sync_cursor' => $fields['sync_cursor'] ?? null,
            'last_error' => array_key_exists('last_error', $fields) ? $fields['last_error'] : null,
        ]);
    }
}
