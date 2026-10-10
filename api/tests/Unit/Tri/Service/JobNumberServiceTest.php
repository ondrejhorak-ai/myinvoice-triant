<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri\Service;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\JobNumberService;
use PDO;
use PHPUnit\Framework\TestCase;

final class JobNumberServiceTest extends TestCase
{
    public function testAssignInsideOpenTransactionDoesNotStartAnother(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO tri_number_seq (supplier_id, scope_key, last_value) VALUES (1, '26', 4)");
        $pdo->beginTransaction();

        $number = $this->service()->assign($pdo, 1, '26');

        $this->assertSame('260005', $number);
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $this->assertSame(4, $this->lastValue($pdo));
    }

    public function testAssignWithoutOuterTransactionCommitsTheSequence(): void
    {
        $pdo = $this->pdo();

        $number = $this->service()->assign($pdo, 1, '26');

        $this->assertSame('260001', $number);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, $this->lastValue($pdo));
    }

    private function service(): JobNumberService
    {
        return new JobNumberService(new Connection(new Config([])));
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE tri_number_seq (
                supplier_id INTEGER NOT NULL,
                scope_key TEXT NOT NULL,
                last_value INTEGER NOT NULL,
                PRIMARY KEY (supplier_id, scope_key)
            )'
        );

        return $pdo;
    }

    private function lastValue(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT last_value FROM tri_number_seq')->fetchColumn();
    }
}
