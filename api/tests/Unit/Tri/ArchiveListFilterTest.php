<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Action\Client\ListClientsAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tri\Action\Job\ListJobsAction;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Service\JobNumberService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ArchiveListFilterTest extends TestCase
{
    public function testAllArchivesRemovesOnlyArchiveConstraintAndPreservesSupplierScope(): void
    {
        foreach (['contacts', 'jobs'] as $kind) {
            foreach (['active', 'archived', 'all'] as $scope) {
                $queries = [];
                $statement = $this->createStub(PDOStatement::class);
                $statement->method('fetchColumn')->willReturn(0);
                $statement->method('fetchAll')->willReturn([]);
                $statement->method('fetch')->willReturn(false);
                $pdo = $this->createMock(PDO::class);
                $pdo->expects($this->atLeastOnce())->method('prepare')->willReturnCallback(function (string $sql) use (&$queries, $statement) {
                    $queries[] = $sql;
                    return $statement;
                });
                $db = $this->createStub(Connection::class);
                $db->method('pdo')->willReturn($pdo);
                if ($kind === 'contacts') {
                    (new ClientRepository($db))->list(['supplier_id' => 41, 'archived' => $scope === 'all' ? 'all' : $scope === 'archived'], 1, 25);
                } else {
                    (new JobRepository($db, $this->createStub(JobNumberService::class), $this->createStub(TagRepository::class)))->list(41, ['archive' => $scope], 1, 25);
                }
                self::assertGreaterThanOrEqual(2, count($queries));
                $alias = $kind === 'contacts' ? 'c' : 'j';
                foreach ($queries as $sql) {
                    self::assertStringContainsString("$alias.supplier_id = ?", $sql);
                    if ($scope === 'all') {
                        self::assertStringNotContainsString("$alias.archived_at IS", $sql);
                    } else {
                        self::assertStringContainsString("$alias.archived_at IS " . ($scope === 'archived' ? 'NOT NULL' : 'NULL'), $sql);
                    }
                }
            }
        }
    }

    public function testActionsForwardExplicitAllAndKeepLegacyDefaults(): void
    {
        foreach ([null, 'all'] as $scope) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/clients')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41)
                ->withQueryParams($scope ? ['filter' => ['archived' => $scope]] : []);
            $repo = $this->createMock(ClientRepository::class);
            $repo->expects($this->once())->method('list')->with($this->callback(fn (array $f) => $f['archived'] === ($scope ?? false) && $f['supplier_id'] === 41), 1, 50, 'name')->willReturn(['data' => []]);
            $config = $this->createStub(Config::class);
            $config->method('get')->willReturn(50);
            self::assertSame(200, (new ListClientsAction($repo, $config))($request, new Response())->getStatusCode());

            $jobs = $this->createMock(JobRepository::class);
            $jobs->expects($this->once())->method('list')->with(41, $this->callback(fn (array $f) => $f['archive'] === ($scope ?? 'active')), 1, 25)->willReturn(['data' => []]);
            self::assertSame(200, (new ListJobsAction($jobs))($request->withQueryParams($scope ? ['archive' => $scope] : []), new Response())->getStatusCode());
        }
    }
}
