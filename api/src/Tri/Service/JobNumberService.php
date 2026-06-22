<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class JobNumberService
{
    public function __construct(private readonly Connection $db) {}

    public function getYearSuffix(?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));

        return substr($date->format('Y'), -2);
    }

    public function formatJobNumber(string $yearSuffix, int $sequence): string
    {
        return $yearSuffix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function formatVariantNumber(string $jobNumber, string $variantCode): string
    {
        return $jobNumber . '-' . $variantCode;
    }

    public function isValidJobNumberFormat(string $number): bool
    {
        return (bool) preg_match('/^\d{2}\d{4}$/', $number);
    }

    /** @return array{yearSuffix: string, sequence: int} */
    public function parseJobNumber(string $number): array
    {
        if (!preg_match('/^(\d{2})(\d{4})$/', $number, $m)) {
            throw new \InvalidArgumentException('INVALID_JOB_NUMBER_FORMAT');
        }

        return ['yearSuffix' => $m[1], 'sequence' => (int) $m[2]];
    }

    public function peekNext(int $supplierId, string $yearSuffix): string
    {
        $pdo = $this->db->pdo();
        $fromSeq = $this->readSequence($pdo, $supplierId, $yearSuffix) + 1;
        $maxFromJobs = $this->maxSequenceFromJobs($pdo, $supplierId, $yearSuffix);
        $next = max($fromSeq, $maxFromJobs + 1);

        return $this->formatJobNumber($yearSuffix, $next);
    }

    /**
     * @throws JobNumberConflictException
     * @throws JobNumberFormatException
     */
    public function assign(PDO $pdo, int $supplierId, string $yearSuffix, ?string $desiredNumber = null): string
    {
        if ($desiredNumber === null || $desiredNumber === '') {
            return $this->reserveNext($pdo, $supplierId, $yearSuffix);
        }

        if (!$this->isValidJobNumberFormat($desiredNumber)) {
            throw new JobNumberFormatException();
        }

        $parsed = $this->parseJobNumber($desiredNumber);
        if ($parsed['yearSuffix'] !== $yearSuffix) {
            throw new JobNumberFormatException();
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM tri_jobs WHERE supplier_id = ? AND number = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $desiredNumber]);
        if ($stmt->fetchColumn() !== false) {
            throw new JobNumberConflictException();
        }

        $this->syncSequence($pdo, $supplierId, $parsed['yearSuffix'], $parsed['sequence']);

        return $desiredNumber;
    }

    private function reserveNext(PDO $pdo, int $supplierId, string $yearSuffix): string
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT last_value FROM tri_number_seq WHERE supplier_id = ? AND scope_key = ? FOR UPDATE'
            );
            $stmt->execute([$supplierId, $yearSuffix]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $next = 1;
                $ins = $pdo->prepare(
                    'INSERT INTO tri_number_seq (supplier_id, scope_key, last_value) VALUES (?, ?, ?)'
                );
                $ins->execute([$supplierId, $yearSuffix, $next]);
            } else {
                $next = (int) $row['last_value'] + 1;
                $upd = $pdo->prepare(
                    'UPDATE tri_number_seq SET last_value = ? WHERE supplier_id = ? AND scope_key = ?'
                );
                $upd->execute([$next, $supplierId, $yearSuffix]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->formatJobNumber($yearSuffix, $next);
    }

    private function readSequence(PDO $pdo, int $supplierId, string $yearSuffix): int
    {
        $stmt = $pdo->prepare(
            'SELECT last_value FROM tri_number_seq WHERE supplier_id = ? AND scope_key = ?'
        );
        $stmt->execute([$supplierId, $yearSuffix]);
        $v = $stmt->fetchColumn();

        return $v === false ? 0 : (int) $v;
    }

    private function maxSequenceFromJobs(PDO $pdo, int $supplierId, string $yearSuffix): int
    {
        $stmt = $pdo->prepare(
            'SELECT number FROM tri_jobs WHERE supplier_id = ? AND number LIKE ?'
        );
        $stmt->execute([$supplierId, $yearSuffix . '%']);
        $max = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $num = (string) $row['number'];
            if (!$this->isValidJobNumberFormat($num)) {
                continue;
            }
            try {
                $parsed = $this->parseJobNumber($num);
                if ($parsed['yearSuffix'] === $yearSuffix) {
                    $max = max($max, $parsed['sequence']);
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $max;
    }

    private function syncSequence(PDO $pdo, int $supplierId, string $yearSuffix, int $sequenceValue): void
    {
        $stmt = $pdo->prepare(
            'SELECT last_value FROM tri_number_seq WHERE supplier_id = ? AND scope_key = ?'
        );
        $stmt->execute([$supplierId, $yearSuffix]);
        $existing = $stmt->fetchColumn();
        if ($existing === false) {
            $ins = $pdo->prepare(
                'INSERT INTO tri_number_seq (supplier_id, scope_key, last_value) VALUES (?, ?, ?)'
            );
            $ins->execute([$supplierId, $yearSuffix, $sequenceValue]);

            return;
        }
        if ($sequenceValue > (int) $existing) {
            $upd = $pdo->prepare(
                'UPDATE tri_number_seq SET last_value = ? WHERE supplier_id = ? AND scope_key = ?'
            );
            $upd->execute([$sequenceValue, $supplierId, $yearSuffix]);
        }
    }

    /** @return list<string> */
    public static function variantCodes(): array
    {
        return str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    public function nextVariantCode(array $usedCodes): ?string
    {
        $used = array_flip($usedCodes);
        foreach (self::variantCodes() as $code) {
            if (!isset($used[$code])) {
                return $code;
            }
        }

        return null;
    }
}

final class JobNumberConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('JOB_NUMBER_CONFLICT');
    }
}

final class JobNumberFormatException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('INVALID_JOB_NUMBER_FORMAT');
    }
}
