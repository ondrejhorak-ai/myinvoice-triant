<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/**
 * Pravidla TRI kalendáře. Stanoviště kalendáře výroby ≠ stanoviště průvodky.
 */
final class CalendarEventRules
{
    public const CALENDARS = ['shifts', 'dispatch', 'production'];
    public const STATIONS = ['konstrukce', 'vyroba', 'kompletace', 'lakovna', 'expedice', 'montaz'];
    public const STATUSES = ['planned', 'confirmed', 'in_progress', 'done'];

    /**
     * @param array<string, mixed> $body
     * @return array{
     *   calendar: string,
     *   title: string,
     *   job_id: ?int,
     *   station: ?string,
     *   starts_at: string,
     *   ends_at: ?string,
     *   all_day: int,
     *   status: string,
     *   note: ?string
     * }
     */
    public static function normalize(array $body): array
    {
        $calendar = (string) ($body['calendar'] ?? '');
        if (!in_array($calendar, self::CALENDARS, true)) {
            throw new \InvalidArgumentException('INVALID_CALENDAR');
        }
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('TITLE_REQUIRED');
        }
        if (mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('TITLE_TOO_LONG');
        }

        $allDay = self::boolish($body['all_day'] ?? 1);
        $startsAt = self::parseDateTime($body['starts_at'] ?? null, $allDay, false);
        $endsAt = self::parseDateTime($body['ends_at'] ?? null, $allDay, true);
        if ($endsAt !== null && $endsAt < $startsAt) {
            throw new \InvalidArgumentException('INVALID_RANGE');
        }

        $stationRaw = $body['station'] ?? null;
        $station = $stationRaw === null || $stationRaw === '' ? null : (string) $stationRaw;
        if ($calendar === 'production') {
            if ($station === null || !in_array($station, self::STATIONS, true)) {
                throw new \InvalidArgumentException('STATION_REQUIRED');
            }
        } else {
            $station = null;
        }

        $status = (string) ($body['status'] ?? 'planned');
        $status = self::constrainStatus($calendar, $status);

        $jobId = $body['job_id'] ?? null;
        if ($jobId === '' || $jobId === null) {
            $jobId = null;
        } elseif (!is_numeric($jobId) || (int) $jobId <= 0) {
            throw new \InvalidArgumentException('INVALID_JOB');
        } else {
            $jobId = (int) $jobId;
        }

        $note = isset($body['note']) ? trim((string) $body['note']) : '';

        return [
            'calendar'  => $calendar,
            'title'     => $title,
            'job_id'    => $jobId,
            'station'   => $station,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'all_day'   => $allDay ? 1 : 0,
            'status'    => $status,
            'note'      => $note !== '' ? $note : null,
        ];
    }

    public static function overlapsRange(string $from, string $to, string $startsAt, ?string $endsAt): bool
    {
        $start = self::timestamp($startsAt);
        $end = $endsAt !== null && $endsAt !== '' ? self::timestamp($endsAt) : $start;
        $rangeFrom = self::timestamp($from);
        $rangeTo = self::timestamp($to);

        return $start < $rangeTo && $end >= $rangeFrom;
    }

    /** @return list<string> 42 dní (Po–Ne), první buňka = pondělí týdne s 1. dnem měsíce */
    public static function monthGrid(int $year, int $month): array
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $dow = (int) $first->format('N');
        $start = $first->modify('-' . ($dow - 1) . ' days');
        $days = [];
        for ($i = 0; $i < 42; $i++) {
            $days[] = $start->modify("+{$i} days")->format('Y-m-d');
        }

        return $days;
    }

    /** @return list<string> 7 dní Po–Ne obsahujících $date */
    public static function weekDays(string $date): array
    {
        $day = new \DateTimeImmutable(substr($date, 0, 10));
        $dow = (int) $day->format('N');
        $monday = $day->modify('-' . ($dow - 1) . ' days');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $monday->modify("+{$i} days")->format('Y-m-d');
        }

        return $days;
    }

    public static function parseQueryBound(mixed $value, bool $endExclusive): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            throw new \InvalidArgumentException('INVALID_RANGE');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw . ($endExclusive ? ' 00:00:00' : ' 00:00:00');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw);
        if ($dt === false) {
            throw new \InvalidArgumentException('INVALID_RANGE');
        }

        return $dt->format('Y-m-d H:i:s');
    }

    private static function parseDateTime(mixed $value, bool $allDay, bool $optional): ?string
    {
        if ($value === null || $value === '') {
            if ($optional) {
                return null;
            }
            throw new \InvalidArgumentException('STARTS_REQUIRED');
        }
        $raw = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw . ($allDay && $optional ? ' 23:59:59' : ' 00:00:00');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw);
        if ($dt === false) {
            throw new \InvalidArgumentException('INVALID_DATETIME');
        }
        if ($allDay) {
            return $dt->format('Y-m-d') . ($optional ? ' 23:59:59' : ' 00:00:00');
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /** @return list<string> */
    public static function allowedStatuses(string $calendar): array
    {
        return match ($calendar) {
            'dispatch'   => ['planned', 'confirmed'],
            'production' => ['planned', 'in_progress', 'done'],
            default      => ['planned'],
        };
    }

    public static function nextStatus(string $calendar, string $status): ?string
    {
        return match ($calendar) {
            'dispatch'   => $status === 'planned' ? 'confirmed' : ($status === 'confirmed' ? 'planned' : null),
            'production' => $status === 'planned' ? 'in_progress' : ($status === 'in_progress' ? 'done' : null),
            default      => null,
        };
    }

    public static function constrainStatus(string $calendar, string $status): string
    {
        $allowed = self::allowedStatuses($calendar);
        if (in_array($status, $allowed, true)) {
            return $status;
        }
        if ($calendar === 'shifts') {
            return 'planned';
        }
        throw new \InvalidArgumentException('INVALID_STATUS');
    }

    private static function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === '' || $value === null) {
            return false;
        }

        return (bool) $value;
    }

    private static function timestamp(string $value): int
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException('INVALID_DATETIME');
        }

        return $ts;
    }
}
