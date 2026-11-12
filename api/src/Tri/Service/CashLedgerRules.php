<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/** Částky v haléřích. Hotovost mění zůstatek, karta ne. */
final class CashLedgerRules
{
    public const KINDS = ['cash_in', 'cash_out', 'card'];

    /** @var list<string> */
    private const AUDIT_FIELDS = [
        'kind',
        'amount',
        'occurred_on',
        'description',
        'note',
        'party_name',
        'job_label',
        'accounted',
    ];

    public static function cents(string $amount): int
    {
        $amount = trim(str_replace(["\u{00a0}", ' ', ','], ['', '', '.'], $amount));
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('INVALID_AMOUNT');
        }
        $negative = str_starts_with($amount, '-');
        $absolute = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        return ($negative ? '-' : '') . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function drawerDelta(string $kind, int $amountCents): int
    {
        return match ($kind) {
            'cash_in' => $amountCents,
            'cash_out' => -$amountCents,
            'card' => 0,
            default => throw new \InvalidArgumentException('INVALID_KIND'),
        };
    }

    /** @param array<string, mixed> $body */
    public static function normalizeMovement(array $body): array
    {
        $kind = (string) ($body['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('INVALID_KIND');
        }
        $cents = self::cents((string) ($body['amount'] ?? ''));
        if ($cents <= 0) {
            throw new \InvalidArgumentException('INVALID_AMOUNT');
        }
        $description = trim((string) ($body['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('INVALID_DESCRIPTION');
        }
        $note = trim((string) ($body['note'] ?? ''));

        return [
            'kind' => $kind,
            'amount' => self::format($cents),
            'occurred_on' => self::date((string) ($body['occurred_on'] ?? '')),
            'description' => $description,
            'note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            'party_user_id' => self::optionalId($body['party_user_id'] ?? null, 'INVALID_USER'),
            'job_id' => self::optionalId($body['job_id'] ?? null, 'INVALID_JOB'),
            'accounted' => self::flag($body['accounted'] ?? false),
            'client_token' => self::token($body['client_token'] ?? null),
        ];
    }

    /** @param list<int|string> $raw */
    public static function idList(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if (is_string($value) && !preg_match('/^\d+$/', $value)) {
                throw new \InvalidArgumentException('INVALID_IDS');
            }
            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException('INVALID_IDS');
            }
            $id = (int) $value;
            if ($id <= 0) {
                throw new \InvalidArgumentException('INVALID_IDS');
            }
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if ($ids === [] || count($ids) > 200) {
            throw new \InvalidArgumentException('INVALID_IDS');
        }

        return $ids;
    }

    public static function assertEditable(bool $accounted, bool $admin): void
    {
        if ($accounted && !$admin) {
            throw new \InvalidArgumentException('ACCOUNTED');
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from:mixed,to:mixed}>
     */
    public static function changes(array $before, array $after): array
    {
        $out = [];
        foreach (self::AUDIT_FIELDS as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if (self::same($from, $to)) {
                continue;
            }
            $out[$field] = ['from' => $from, 'to' => $to];
        }

        return $out;
    }

    /** @param array<string, array{from:mixed,to:mixed}> $changes */
    public static function auditAction(array $changes): string
    {
        if (array_keys($changes) === ['accounted']) {
            return !empty($changes['accounted']['to']) ? 'accounted' : 'unaccounted';
        }

        return 'updated';
    }

    public static function date(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !self::realDate($value)) {
            throw new \InvalidArgumentException('INVALID_DATE');
        }

        return $value;
    }

    public static function optionalId(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_string($value) && !preg_match('/^\d+$/', $value)) {
            throw new \InvalidArgumentException($error);
        }
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException($error);
        }
        $id = (int) $value;
        if ($id <= 0) {
            throw new \InvalidArgumentException($error);
        }

        return $id;
    }

    public static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    public static function token(mixed $value): ?string
    {
        $token = trim((string) ($value ?? ''));
        if ($token === '') {
            return null;
        }
        if (!preg_match('/^[0-9a-fA-F-]{8,36}$/', $token)) {
            throw new \InvalidArgumentException('INVALID_TOKEN');
        }

        return $token;
    }

    private static function same(mixed $left, mixed $right): bool
    {
        if (is_bool($left) || is_bool($right)) {
            return (bool) $left === (bool) $right;
        }

        return (string) ($left ?? '') === (string) ($right ?? '');
    }

    private static function realDate(string $value): bool
    {
        $parts = explode('-', $value);
        if (count($parts) !== 3) {
            return false;
        }

        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}
