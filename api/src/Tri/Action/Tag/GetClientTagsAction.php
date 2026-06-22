<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Tag;

use MyInvoice\Http\Json;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tri\Repository\TagRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetClientTagsAction
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly ClientRepository $clients,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $clientId = (int) $args['id'];
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }
        $supplierId = TriRequest::supplierId($request);
        if ((int) ($client['supplier_id'] ?? 0) !== $supplierId) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        return Json::ok($response, ['tags' => $this->tags->tagsForClient($clientId)]);
    }
}
