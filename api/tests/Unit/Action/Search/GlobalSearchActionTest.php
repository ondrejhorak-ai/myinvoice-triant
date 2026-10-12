<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Search;

use MyInvoice\Action\Search\GlobalSearchAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\JobRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class GlobalSearchActionTest extends TestCase
{
    public function testShortQueryDoesNotSearch(): void
    {
        $clients = $this->createMock(ClientRepository::class);
        $invoices = $this->createMock(InvoiceRepository::class);
        $jobs = $this->createMock(JobRepository::class);
        $jobInvoices = $this->createMock(JobInvoiceRepository::class);
        $clients->expects(self::never())->method('searchQuick');
        $invoices->expects(self::never())->method('searchQuick');
        $jobs->expects(self::never())->method('searchQuick');
        $jobInvoices->expects(self::never())->method('searchQuick');

        $payload = $this->invoke(new GlobalSearchAction($clients, $invoices, $jobs, $jobInvoices), 'a');

        self::assertSame(['q' => 'a', 'jobs' => [], 'clients' => [], 'invoices' => []], $payload);
    }

    public function testQueryReturnsJobsContactsAndPrefersMirroredInvoice(): void
    {
        $clients = $this->createMock(ClientRepository::class);
        $invoices = $this->createMock(InvoiceRepository::class);
        $jobs = $this->createMock(JobRepository::class);
        $jobInvoices = $this->createMock(JobInvoiceRepository::class);

        $clients->expects(self::once())->method('searchQuick')->with('260018', 3)->willReturn([
            ['id' => 70, 'company_name' => 'Ateliér', 'first_name' => null, 'last_name' => null, 'party_kind' => 'company', 'main_email' => null, 'is_customer' => true, 'is_vendor' => false],
        ]);
        $jobs->expects(self::once())->method('searchQuick')->with('260018', 3)->willReturn([
            ['id' => 18, 'number' => '260018', 'title' => 'Název', 'contact' => 'Jana'],
        ]);
        $jobInvoices->expects(self::once())->method('searchQuick')->with('260018', 3)->willReturn([
            ['id' => 9, 'varsymbol' => '260018', 'supplier_order_number' => '260018', 'invoice_type' => 'invoice', 'status' => 'issued', 'issue_date' => '2026-10-01', 'total_with_vat' => 10.0, 'currency' => 'CZK', 'company_name' => 'Ateliér'],
        ]);
        $invoices->expects(self::once())->method('searchQuick')->with('260018', 3)->willReturn([
            ['id' => 9, 'varsymbol' => 'LEGACY', 'invoice_type' => 'invoice', 'status' => 'issued', 'issue_date' => null, 'total_with_vat' => 1.0, 'currency' => 'CZK', 'company_name' => 'Stará'],
            ['id' => 8, 'varsymbol' => '260018-B', 'invoice_type' => 'invoice', 'status' => 'issued', 'issue_date' => null, 'total_with_vat' => 2.0, 'currency' => 'CZK', 'company_name' => 'Jiná'],
        ]);

        $payload = $this->invoke(new GlobalSearchAction($clients, $invoices, $jobs, $jobInvoices), '260018');

        self::assertSame('260018', $payload['jobs'][0]['number']);
        self::assertSame(70, $payload['clients'][0]['id']);
        self::assertCount(2, $payload['invoices']);
        self::assertSame('260018', $payload['invoices'][0]['varsymbol']);
        self::assertSame('260018-B', $payload['invoices'][1]['varsymbol']);
        self::assertArrayHasKey('supplier_order_number', $payload['invoices'][1]);
    }

    /** @return array<string, mixed> */
    private function invoke(GlobalSearchAction $action, string $q): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/search')
            ->withQueryParams(['q' => $q])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 3);
        $response = $action($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
