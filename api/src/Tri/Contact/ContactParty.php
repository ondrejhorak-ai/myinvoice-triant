<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

/**
 * Druh kontaktu v Office: firma, nebo osoba.
 * Osoba bez firmy je soukromý zákazník. Osoba s firmou se do MyÚčta neposílá.
 * Adresa není podmínka uložení. Do MyÚčta patří jen fakturovatelný kontakt s kompletní adresou.
 */
final class ContactParty
{
    public const COMPANY = 'company';
    /** Starší druh. Při čtení i zápisu se bere jako osoba bez firmy. */
    public const PRIVATE = 'private';
    public const PERSON = 'person';

    /** @var list<string> */
    public const KINDS = [self::COMPANY, self::PERSON];

    public static function kind(array $body, string $fallback = self::COMPANY): string
    {
        $kind = self::normalize(trim((string) ($body['party_kind'] ?? $fallback)));

        return in_array($kind, self::KINDS, true) ? $kind : '';
    }

    public static function normalize(string $kind): string
    {
        return $kind === self::PRIVATE ? self::PERSON : $kind;
    }

    public static function isBillable(string $kind, ?int $companyId = null): bool
    {
        $kind = self::normalize($kind);
        if ($kind === self::COMPANY) {
            return true;
        }

        return $kind === self::PERSON && ($companyId === null || $companyId <= 0);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, list<string>>
     */
    public static function errors(array $body, string $fallbackKind = self::COMPANY): array
    {
        $err = [];
        $kind = self::kind($body, $fallbackKind);
        if ($kind === '') {
            $err['party_kind'][] = 'Druh kontaktu musí být firma nebo osoba.';

            return $err;
        }
        $name = trim((string) ($body['company_name'] ?? ''));
        if ($name === '') {
            $err['company_name'][] = 'Jméno je povinné.';
        }
        $email = trim((string) ($body['main_email'] ?? ''));
        if ($kind === self::PERSON && $email === '') {
            $err['main_email'][] = 'E-mail osoby je povinný.';
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $err['main_email'][] = 'E-mail musí být platný.';
        }
        $phone = trim((string) ($body['phone'] ?? ''));
        if (strlen($phone) > 40) {
            $err['phone'][] = 'Telefon je příliš dlouhý.';
        }
        if ($kind === self::COMPANY) {
            $ic = IcoMatch::digits((string) ($body['ic'] ?? ''));
            if (strlen($ic) !== 8) {
                $err['ic'][] = 'IČO firmy je povinné a musí mít 8 číslic.';
            }
        }
        $companyId = (int) ($body['company_client_id'] ?? 0);
        if ($kind !== self::PERSON && $companyId > 0) {
            $err['company_client_id'][] = 'Vazbu na firmu má jen osoba.';
        }

        return $err;
    }

    /** @param array<string, mixed> $row */
    public static function billingReady(array $row): bool
    {
        $kind = (string) ($row['party_kind'] ?? self::COMPANY);
        $companyId = (int) ($row['company_client_id'] ?? 0);
        if (!self::isBillable($kind, $companyId)) {
            return false;
        }
        foreach (['company_name', 'street', 'city', 'zip'] as $key) {
            if (trim((string) ($row[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public static function blank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
