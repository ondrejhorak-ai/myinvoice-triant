<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use MyInvoice\Tri\Repository\JobActivityRepository;
use Psr\Log\LoggerInterface;

/**
 * Zápis systémových událostí do feedu zakázky (tri_job_activity).
 * Selhání logování nesmí shodit hlavní operaci — chyba jde jen do app logu.
 */
final class JobActivityLogger
{
    public function __construct(
        private readonly JobActivityRepository $repo,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string, mixed> $payload */
    public function event(int $jobId, ?int $userId, string $eventType, array $payload = []): void
    {
        try {
            $this->repo->addEvent($jobId, $userId, $eventType, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('tri_job_activity event failed', [
                'job_id'     => $jobId,
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
