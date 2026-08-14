<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

final class ComplaintRules
{
    public const STATUSES = ['open', 'closed'];
    public const MAX_TITLE = 255;
    public const MAX_BODY = 10000;

    /**
     * @param array<string, mixed> $body
     * @return array{job_id: int, title: string, description: ?string}
     */
    public static function normalizeCreate(array $body): array
    {
        $jobId = $body['job_id'] ?? null;
        if (!is_numeric($jobId) || (int) $jobId <= 0) {
            throw new \InvalidArgumentException('INVALID_JOB');
        }

        return [
            'job_id'      => (int) $jobId,
            'title'       => self::title($body['title'] ?? ''),
            'description' => self::description($body['description'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{title: string, description: ?string}
     */
    public static function normalizeUpdate(array $body): array
    {
        return [
            'title'       => self::title($body['title'] ?? ''),
            'description' => self::description($body['description'] ?? null),
        ];
    }

    /**
     * @return array{status: string, closed_at: ?string}
     */
    public static function applyStatus(string $status, ?string $now = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('INVALID_STATUS');
        }
        if ($status === 'closed') {
            return [
                'status'    => 'closed',
                'closed_at' => $now ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
        }

        return ['status' => 'open', 'closed_at' => null];
    }

    public static function normalizeComment(mixed $body): string
    {
        $text = trim((string) $body);
        if ($text === '') {
            throw new \InvalidArgumentException('COMMENT_REQUIRED');
        }
        if (mb_strlen($text) > self::MAX_BODY) {
            throw new \InvalidArgumentException('COMMENT_TOO_LONG');
        }

        return $text;
    }

    public static function eventTypeForTransition(string $from, string $to): ?string
    {
        if ($from === $to) {
            return null;
        }
        if ($to === 'closed') {
            return 'complaint_closed';
        }
        if ($from === 'closed' && $to === 'open') {
            return 'complaint_reopened';
        }

        return null;
    }

    private static function title(mixed $value): string
    {
        $title = trim((string) $value);
        if ($title === '') {
            throw new \InvalidArgumentException('TITLE_REQUIRED');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new \InvalidArgumentException('TITLE_TOO_LONG');
        }

        return $title;
    }

    private static function description(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
