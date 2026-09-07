<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

use PDO;

/**
 * Upsert do zrcadlových tabulek — MariaDB i SQLite (unit testy).
 */
final class MirrorWriter
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string, mixed> $row
     * @param list<string> $updateCols sloupce přepisované při konfliktu PK
     */
    public function upsert(string $table, array $row, array $updateCols): void
    {
        $cols = array_keys($row);
        $colSql = implode(', ', array_map($this->quoteIdent(...), $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pk = $cols[0];
            $sets = [];
            foreach ($updateCols as $col) {
                $ident = $this->quoteIdent($col);
                $sets[] = "{$ident} = excluded.{$ident}";
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(%s) DO UPDATE SET %s',
                $this->quoteIdent($table),
                $colSql,
                $placeholders,
                $this->quoteIdent($pk),
                implode(', ', $sets),
            );
        } else {
            $sets = [];
            foreach ($updateCols as $col) {
                $ident = $this->quoteIdent($col);
                $sets[] = "{$ident} = VALUES({$ident})";
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $this->quoteIdent($table),
                $colSql,
                $placeholders,
                implode(', ', $sets),
            );
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($row));
    }

    public function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    private function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }
}
