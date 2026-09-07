<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\MyUcto;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\MyUcto\SyncRunner;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RunSyncAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly MyUctoClient $client,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (TriRequest::userRole($request) !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        if (!$this->client->isEnabled()) {
            return Json::error($response, 'disabled', 'Integrace s MyÚčtem je vypnutá.', 503);
        }

        $body = (array) $request->getParsedBody();
        $full = !empty($body['full']);
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId <= 0) {
            $supplierId = (int) $this->config->get('app.default_supplier_id', 1);
        }

        try {
            $n = (new SyncRunner($this->client, $this->db->pdo(), $supplierId))->run($full);
        } catch (MyUctoApiException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus >= 400 ? $e->httpStatus : 502);
        }

        return Json::ok($response, ['ok' => true, 'records' => $n, 'full' => $full]);
    }
}
