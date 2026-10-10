<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

/**
 * Druh kontaktu v Office: odběratel (firma nebo soukromá osoba) a kontaktní osoba.
 * Adresa není podmínka uložení. Do MyÚčta patří jen odběratel s kompletní adresou.
 */
final class ContactParty
{
    public const COMPANY = 'company';
    public const PRIVATE = 'private';
    public const PERSON = 'person';

    /** @var list<string> */
    public const KINDS = [self::COMPANY, self::PRIVATE, self::PERSON];

    /** @var list<string> */
    public const BILLABLE = [self::COMPANY, self::PRIVATE];

    public static function kind(array $body, string $fallback = self::COMPANY): string
    {
        $kind = trim((string) ($body['party_kind'] ?? $fallback));

        return in_array($kind, self::KINDS, true) ? $kind : '';
    }

    public static function isBillable(string $kind): bool
    {
        return in_array($kind, self::BILLABLE, true);
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
            $err['party_kind'][] = 'Druh kontaktu musí být firma, soukromá osoba nebo kontaktní osoba.';

            return $err;
        }
        $name = trim((string) ($body['company_name'] ?? ''));
        if ($name === '') {
            $err['company_name'][] = 'Jméno je povinné.';
        }
        $email = trim((string) ($body['main_email'] ?? ''));
        if ($kind === self::PERSON && $email === '') {
            $err['main_email'][] = 'E-mail kontaktní osoby je povinný.';
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $err['main_email'][] = 'E-mail musí být platný.';
        }
        $phone = trim((string) ($body['phone'] ?? ''));
        if (strlen($phone) > 40) {
            $err['phone'][] = 'Telefon je příliš dlouhý.';
        }
        if ($kind === self::COMPANY) {
            $ic = IcoMatch::digits((string) ($body['ic'] ?? ''));
            if ($ic !== '' && strlen($ic) !== 8) {
                $err['ic'][] = 'IČO musí mít 8 číslic.';
            }
        }
        $companyId = (int) ($body['company_client_id'] ?? 0);
        if ($kind !== self::PERSON && $companyId > 0) {
            $err['company_client_id'][] = 'Vazbu na firmu má jen kontaktní osoba.';
        }

        return $err;
    }

    /** @param array<string, mixed> $row */
    public static function billingReady(array $row): bool
    {
        if (!self::isBillable((string) ($row['party_kind'] ?? self::COMPANY))) {
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
