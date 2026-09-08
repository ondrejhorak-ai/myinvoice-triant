<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * iDoklad bankovní pohyby — po P2 (smazání bankovního párování) no-op.
 * Celý import se maže v P12.
 */
final class IdokladBankTransactionImporter
{
    /** @return array{created:int,skipped:int,matched:int,unmapped:int,document_links:int} */
    public function import(int $supplierId, bool $dryRun, bool $incremental = false): array
    {
        unset($supplierId, $dryRun, $incremental);

        return ['created' => 0, 'skipped' => 0, 'matched' => 0, 'unmapped' => 0, 'document_links' => 0];
    }
}
