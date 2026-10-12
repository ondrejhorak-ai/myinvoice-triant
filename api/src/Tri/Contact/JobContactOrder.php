<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

/**
 * Pořadí kontaktů na zakázce určuje sloupec Kontakt a výchozího odběratele.
 * První osoba je hlavní kontakt, první firma je firma ve sloupci a výchozí fakturační adresa.
 * Role zákazník a dodavatel v MyÚčtu se z pořadí nemění.
 */
final class JobContactOrder
{
    /**
     * @param array<string, mixed> $contact
     */
    public static function displayName(array $contact): string
    {
        if (self::isPerson($contact)) {
            $person = trim(trim((string) ($contact['first_name'] ?? '')) . ' ' . trim((string) ($contact['last_name'] ?? '')));
            if ($person !== '') {
                return $person;
            }
        }

        return trim((string) ($contact['company_name'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $contacts v uloženém pořadí
     */
    public static function label(array $contacts): ?string
    {
        $person = null;
        $company = null;
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $name = self::displayName($contact);
            if ($name === '') {
                continue;
            }
            if ($person === null && self::isPerson($contact)) {
                $person = $name;
            }
            if ($company === null && self::isCompany($contact)) {
                $company = $name;
            }
        }
        if ($person !== null && $company !== null) {
            return $person . ', ' . $company;
        }

        return $person ?? $company;
    }

    /**
     * Výchozí odběratel nové faktury a zálohy: první firma, jinak první osoba.
     *
     * @param list<array<string, mixed>> $contacts
     */
    public static function billingClientId(array $contacts): int
    {
        $personId = 0;
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $id = (int) ($contact['client_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (self::isCompany($contact)) {
                return $id;
            }
            if ($personId === 0 && self::isPerson($contact)) {
                $personId = $id;
            }
        }

        return $personId;
    }

    /**
     * Shoda na jméno první osoby a název první firmy, které sloupec ukazuje.
     * Pět zástupných parametrů, všechny se stejným LIKE.
     */
    public static function columnMatchSql(string $jobAlias = 'j'): string
    {
        $job = preg_replace('/[^A-Za-z0-9_]/', '', $jobAlias) ?: 'j';
        $person = self::firstOfKindSql($job, "IN ('person', 'private')");
        $company = self::firstOfKindSql($job, "= 'company'");

        return '(EXISTS (
            SELECT 1 FROM tri_job_contacts jc
            JOIN clients shown ON shown.id = jc.client_id
            WHERE jc.job_id = ' . $job . '.id
              AND shown.party_kind ' . "IN ('person', 'private')" . '
              AND ' . $person . '
              AND (
                shown.company_name LIKE ?
                OR shown.first_name LIKE ?
                OR shown.last_name LIKE ?
                OR TRIM(CONCAT(COALESCE(shown.first_name, \'\'), \' \', COALESCE(shown.last_name, \'\'))) LIKE ?
              )
        ) OR EXISTS (
            SELECT 1 FROM tri_job_contacts jc
            JOIN clients shown ON shown.id = jc.client_id
            WHERE jc.job_id = ' . $job . '.id
              AND shown.party_kind = \'company\'
              AND ' . $company . '
              AND shown.company_name LIKE ?
        ))';
    }

    /** @return list<string> */
    public static function columnMatchParams(string $like): array
    {
        return [$like, $like, $like, $like, $like];
    }

    /**
     * @param list<int|string> $jobIds
     * @return array<int, string|null>
     */
    public static function labelsForJobs(\PDO $pdo, array $jobIds): array
    {
        $ids = [];
        foreach ($jobIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = null;
        }
        if ($ids === []) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT jc.job_id, jc.client_id, c.party_kind, c.company_name, c.first_name, c.last_name
               FROM tri_job_contacts jc
               JOIN clients c ON c.id = jc.client_id
              WHERE jc.job_id IN ($placeholders)
              ORDER BY jc.job_id, jc.sort_order, jc.client_id"
        );
        $stmt->execute(array_values($ids));
        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $grouped[(int) $row['job_id']][] = $row;
        }
        foreach ($grouped as $jobId => $contacts) {
            $out[$jobId] = self::label($contacts);
        }

        return $out;
    }

    /** @param array<string, mixed> $contact */
    private static function isPerson(array $contact): bool
    {
        return ContactParty::normalize((string) ($contact['party_kind'] ?? '')) === ContactParty::PERSON;
    }

    /** @param array<string, mixed> $contact */
    private static function isCompany(array $contact): bool
    {
        return ContactParty::normalize((string) ($contact['party_kind'] ?? '')) === ContactParty::COMPANY;
    }

    private static function firstOfKindSql(string $job, string $kindPredicate): string
    {
        return 'NOT EXISTS (
            SELECT 1 FROM tri_job_contacts earlier
            JOIN clients earlier_client ON earlier_client.id = earlier.client_id
            WHERE earlier.job_id = ' . $job . '.id
              AND earlier_client.party_kind ' . $kindPredicate . '
              AND (
                earlier.sort_order < jc.sort_order
                OR (earlier.sort_order = jc.sort_order AND earlier.client_id < jc.client_id)
              )
        )';
    }
}
