<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

/** Role osoby na zakázce. Odběratel faktury je samostatné pole. */
final class JobContacts
{
    public const CUSTOMER = 'customer';
    public const ARCHITECT = 'architect';
    public const CONTACT = 'contact';

    /** @var list<string> */
    public const ROLES = [self::CUSTOMER, self::ARCHITECT, self::CONTACT];

    public static function role(mixed $role): string
    {
        $role = (string) $role;

        return in_array($role, self::ROLES, true) ? $role : self::CONTACT;
    }

    /**
     * @param list<mixed> $raw
     * @param list<mixed> $fallbackIds
     * @return list<array{client_id: int, role: string}>
     */
    public static function normalize(array $raw, array $fallbackIds): array
    {
        $source = $raw;
        if ($source === []) {
            $source = [];
            foreach ($fallbackIds as $id) {
                $source[] = ['client_id' => $id, 'role' => self::CONTACT];
            }
        }
        $out = [];
        $seen = [];
        foreach ($source as $row) {
            if (is_array($row)) {
                $id = (int) ($row['client_id'] ?? $row['id'] ?? 0);
                $role = self::role($row['role'] ?? self::CONTACT);
            } else {
                $id = (int) $row;
                $role = self::CONTACT;
            }
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['client_id' => $id, 'role' => $role];
        }

        return $out;
    }
}
