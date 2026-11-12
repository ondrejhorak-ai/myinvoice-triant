<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Cash;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CashLedgerRepository;
use MyInvoice\Tri\Service\CashLedgerRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListCashAction
{
    public function __construct(private readonly CashLedgerRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $admin = TriRequest::userRole($request) === 'admin';
        $q = $request->getQueryParams();
        try {
            $filters = $this->filters($q, $admin);
        } catch (\InvalidArgumentException $e) {
            return CashErrors::from($e, $response);
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($q['per_page'] ?? 30)));

        return Json::ok($response, $this->repo->list($supplierId, $filters, $page, $perPage));
    }

    /**
     * @param array<string, mixed> $q
     * @return array{q:string,kind:string,accounted:string,date_from:string,date_to:string,party_user_id:int,trash:bool}
     */
    private function filters(array $q, bool $admin): array
    {
        $accounted = (string) ($q['accounted'] ?? 'all');
        if (!in_array($accounted, ['open', 'done', 'all'], true)) {
            $accounted = 'all';
        }
        $from = trim((string) ($q['date_from'] ?? ''));
        $to = trim((string) ($q['date_to'] ?? ''));
        if ($from !== '') {
            $from = CashLedgerRules::date($from);
        }
        if ($to !== '') {
            $to = CashLedgerRules::date($to);
        }
        $partyRaw = trim((string) ($q['party_user_id'] ?? ''));
        $party = 0;
        if ($partyRaw !== '') {
            $party = CashLedgerRules::optionalId($partyRaw, 'INVALID_USER') ?? 0;
        }
        $trash = $admin && CashLedgerRules::flag($q['trash'] ?? false);

        return [
            'q' => isset($q['q']) ? trim((string) $q['q']) : '',
            'kind' => isset($q['kind']) ? (string) $q['kind'] : '',
            'accounted' => $accounted,
            'date_from' => $from,
            'date_to' => $to,
            'party_user_id' => $party,
            'trash' => $trash,
        ];
    }
}
