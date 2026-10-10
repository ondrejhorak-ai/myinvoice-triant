<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

final class PriceReleaseRules
{
    public static function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 9999999999.99) {
            throw new \DomainException('Cena musí být nezáporné konečné číslo.');
        }
        return number_format((float) $value, 2, '.', '');
    }

    public static function date(mixed $value, bool $required = false): ?string
    {
        if (($value === null || $value === '') && !$required) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException('Neplatné datum platnosti.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \DomainException('Neplatné datum platnosti.');
        }
        return $value;
    }

    public static function publication(array $draft, array $published, string $today): void
    {
        $from = self::date($draft['valid_from'] ?? null, true);
        $until = self::date($draft['valid_until'] ?? null);
        if ($from < $today || ($until && $until < $from)) {
            throw new \DomainException('Platnost nesmí začínat v minulosti ani končit před začátkem.');
        }
        foreach ($published as $release) {
            // A current release may be shortened; scheduled releases never get overwritten.
            if ($release['valid_from'] >= $from || ($release['valid_from'] > $today && (!$until || $release['valid_from'] <= $until))) {
                throw new \DomainException('Kolize s publikovaným nebo naplánovaným vydáním.');
            }
        }
    }
}
