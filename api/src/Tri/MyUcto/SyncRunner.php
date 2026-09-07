<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

final class SyncRunner
{
    public function __construct(
        private readonly MyUctoClient $client,
        private readonly PDO $pdo,
        private readonly int $supplierId = 1,
    ) {}

    public function run(bool $full = false): int
    {
        if (!$this->client->isEnabled()) {
            throw new MyUctoApiException('disabled', 'Integrace s MyÚčtem je vypnutá (TRI_MYUCTO_ENABLED).', 503);
        }

        $state = new SyncState($this->pdo);
        $writer = new MirrorWriter($this->pdo);
        $total = 0;

        $total += $this->step($state, 'clients', fn () => (new ClientSync($this->client, $this->pdo, $this->supplierId))->run());
        $total += $this->step($state, 'projects', fn () => (new ProjectSync($this->client, $this->pdo, $writer))->run());
        $total += $this->step($state, 'invoices', function () use ($full, $state) {
            $sync = new InvoiceSync($this->client, $this->pdo, new MirrorWriter($this->pdo));

            return $sync->run($full, $state);
        });

        $codebooksDue = $full || $this->codebooksStale($state);
        if ($codebooksDue) {
            $total += $this->step($state, 'codebooks', fn () => (new CodebookSync($this->client, $writer))->run());
        }

        return $total;
    }

    /**
     * @param callable(): int $fn
     */
    private function step(SyncState $state, string $key, callable $fn): int
    {
        $state->markRun($key);
        try {
            $n = $fn();
            $state->markOk($key, (string) $n);
            $state->log($key, 'pull', null, 200, "ok n={$n}");

            return $n;
        } catch (MyUctoApiException $e) {
            $state->markError($key, $e->getMessage());
            $state->log($key, 'pull', null, $e->httpStatus > 0 ? $e->httpStatus : null, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $state->markError($key, $e->getMessage());
            $state->log($key, 'pull', null, null, $e->getMessage());
            throw $e;
        }
    }

    private function codebooksStale(SyncState $state): bool
    {
        $last = $state->lastOkAt('codebooks');
        if ($last === null) {
            return true;
        }
        $ts = strtotime($last);

        return $ts === false || $ts < time() - 20 * 3600;
    }
}
