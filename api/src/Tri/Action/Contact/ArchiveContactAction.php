<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Contact;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tri\MyUcto\ContactGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ArchiveContactAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $clients,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $gw = new ContactGateway($this->api, $this->db->pdo(), $this->clients, $supplierId);
        $unarchive = str_ends_with($request->getUri()->getPath(), '/unarchive');
        try {
            $row = $unarchive
                ? $gw->unarchive((int) ($args['id'] ?? 0))
                : $gw->archive((int) ($args['id'] ?? 0));
        } catch (MyUctoApiException $e) {
            return ContactError::fromException($response, $e);
        }

        return Json::ok($response, $row);
    }
}
