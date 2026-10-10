<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

/** Částky v haléřích. Hotovost mění zásuvku, karta ne. */
final class CashLedgerRules
{
    public const FLOAT_CENTS = 500_000;

    public const MANUAL_KINDS = ['cash_in', 'cash_out', 'card'];

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
            'cash_out', 'remittance' => -$amountCents,
            'card' => 0,
            default => throw new \InvalidArgumentException('INVALID_KIND'),
        };
    }

    /**
     * @return array{difference_cents:int, remitted_cents:int, remain_cents:int}
     */
    public static function handover(int $bookCents, int $countedCents, int $keepCents): array
    {
        if ($countedCents < 0 || $keepCents < 0) {
            throw new \InvalidArgumentException('INVALID_AMOUNT');
        }
        $remitted = max(0, $countedCents - $keepCents);

        return [
            'difference_cents' => $countedCents - $bookCents,
            'remitted_cents' => $remitted,
            'remain_cents' => $countedCents - $remitted,
        ];
    }

    /** @param array<string, mixed> $body */
    public static function normalizeMovement(array $body): array
    {
        $kind = (string) ($body['kind'] ?? '');
        if (!in_array($kind, self::MANUAL_KINDS, true)) {
            throw new \InvalidArgumentException('INVALID_KIND');
        }
        $cents = self::cents((string) ($body['amount'] ?? ''));
        if ($cents <= 0) {
            throw new \InvalidArgumentException('INVALID_AMOUNT');
        }
        $date = self::date((string) ($body['occurred_on'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('INVALID_DESCRIPTION');
        }
        $note = trim((string) ($body['note'] ?? ''));

        return [
            'kind' => $kind,
            'amount' => self::format($cents),
            'occurred_on' => $date,
            'description' => $description,
            'note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            'client_token' => self::token($body['client_token'] ?? null),
        ];
    }

    /** @param array<string, mixed> $body */
    public static function normalizeHandover(array $body): array
    {
        $counted = self::cents((string) ($body['counted'] ?? ''));
        $keepRaw = $body['keep'] ?? null;
        $keep = ($keepRaw === null || $keepRaw === '')
            ? self::FLOAT_CENTS
            : self::cents((string) $keepRaw);
        if ($counted < 0 || $keep < 0) {
            throw new \InvalidArgumentException('INVALID_AMOUNT');
        }
        $note = trim((string) ($body['note'] ?? ''));

        return [
            'counted' => self::format($counted),
            'keep' => self::format($keep),
            'occurred_on' => self::date((string) ($body['occurred_on'] ?? date('Y-m-d'))),
            'note' => $note !== '' ? mb_substr($note, 0, 2000) : null,
            'client_token' => self::token($body['client_token'] ?? null),
        ];
    }

    public static function date(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('INVALID_DATE');
        }

        return $value;
    }

    public static function assertMutable(?int $handoverId, string $kind): void
    {
        if ($handoverId !== null || $kind === 'remittance') {
            throw new \InvalidArgumentException('HANDED_OVER');
        }
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
}
