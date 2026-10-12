<?php

declare(strict_types=1);

namespace MyInvoice\Action\Search;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Tri\Repository\JobInvoiceRepository;
use MyInvoice\Tri\Repository\JobRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/search?q=...  — globální vyhledávač pod logem v sidebaru.
 *
 * Zakázky (číslo, název, kontakt), kontakty (jméno, e-mail, IČ) a faktury
 * (číslo dokladu, číslo zakázky, odběratel). Položky menu se našeptávají
 * client-side. Min. 2 znaky, jinak prázdné výsledky.
 */
final class GlobalSearchAction
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly JobRepository $jobs,
        private readonly JobInvoiceRepository $jobInvoices,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $supplierId = SupplierGuard::currentId($request);

        if (mb_strlen($q) < 2) {
            return Json::ok($response, self::emptyPayload($q));
        }

        return Json::ok($response, [
            'q'        => $q,
            'jobs'     => $this->jobs->searchQuick($q, $supplierId),
            'clients'  => $this->clients->searchQuick($q, $supplierId),
            'invoices' => $this->invoicesFor($q, $supplierId),
        ]);
    }

    /** @return array{q:string, jobs:list<empty>, clients:list<empty>, invoices:list<empty>} */
    private static function emptyPayload(string $q): array
    {
        return ['q' => $q, 'jobs' => [], 'clients' => [], 'invoices' => []];
    }

    /**
     * Doklady z MyÚčta mají přednost. Stejné id ze staré tabulky invoices se neopakuje,
     * aby klik otevřel doklad, který editor na /tri/invoices opravdu načte.
     *
     * @return list<array<string, mixed>>
     */
    private function invoicesFor(string $q, int $supplierId): array
    {
        $rows = [];
        $seen = [];
        foreach ($this->jobInvoices->searchQuick($q, $supplierId) as $row) {
            $seen[(int) $row['id']] = true;
            $rows[] = $row;
        }
        foreach ($this->invoices->searchQuick($q, $supplierId) as $row) {
            if (isset($seen[(int) $row['id']])) {
                continue;
            }
            $row['supplier_order_number'] = $row['supplier_order_number'] ?? null;
            $rows[] = $row;
            if (count($rows) >= 6) {
                break;
            }
        }

        return array_slice($rows, 0, 6);
    }
}
