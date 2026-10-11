<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Invoice;

/**
 * Odběratel dokladu je klient zvolený na faktuře, když ho zakázka nemá uloženého.
 */
final class JobInvoiceClient
{
    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $invoice
     */
    public static function id(array $job, array $invoice): int
    {
        $fromJob = (int) ($job['customer_client_id'] ?? 0);
        if ($fromJob > 0) {
            return $fromJob;
        }

        return (int) ($invoice['client_id'] ?? 0);
    }
}
