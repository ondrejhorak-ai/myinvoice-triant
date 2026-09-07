<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tri\MyUcto\InvoiceGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InvoiceCycleAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MyUctoClient $api,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $paymentId = (int) ($args['paymentId'] ?? 0);
        $role = TriRequest::userRole($request);
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        $body = (array) ($request->getParsedBody() ?? []);
        $gw = new InvoiceGateway($this->api, $this->db->pdo(), TriRequest::supplierId($request));

        $isPay = str_contains($path, '/payments') || str_contains($path, '/mark-paid') || str_contains($path, '/unmark-paid');
        $isMutate = $method !== 'GET';
        if ($isMutate && $role === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        if ($isPay && $isMutate && !in_array($role, ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Platby smí měnit jen účetní nebo admin.', 403);
        }

        try {
            $data = $this->dispatch($gw, $path, $method, $id, $paymentId, $body);
        } catch (MyUctoApiException $e) {
            return InvoiceError::fromException($response, $e);
        }

        if ($isMutate) {
            $op = basename($path);
            $userId = TriRequest::userId($request);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('tri.invoice.' . $op, $userId, 'invoice', $id, [
                'path' => $path,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        return Json::ok($response, $data);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function dispatch(
        InvoiceGateway $gw,
        string $path,
        string $method,
        int $id,
        int $paymentId,
        array $body,
    ): array {
        if (str_ends_with($path, '/issue-final')) {
            return $gw->issueFinal($id);
        }
        if (str_ends_with($path, '/issue')) {
            return $gw->issue($id);
        }
        if (str_ends_with($path, '/recipients')) {
            return $gw->recipients($id);
        }
        if (str_ends_with($path, '/send')) {
            return $gw->send($id, $body);
        }
        if (str_ends_with($path, '/reminder')) {
            return $gw->reminder($id);
        }
        if (str_ends_with($path, '/public-link')) {
            return $gw->publicLink($id);
        }
        if (str_ends_with($path, '/clone')) {
            return $gw->cloneInvoice($id);
        }
        if (str_ends_with($path, '/mark-paid')) {
            return $gw->markPaid($id, $body);
        }
        if (str_ends_with($path, '/unmark-paid')) {
            return $gw->unmarkPaid($id);
        }
        if (str_ends_with($path, '/link-advance')) {
            return $gw->linkAdvance($id, $body);
        }
        if (str_contains($path, '/payments')) {
            if ($method === 'GET') {
                return $gw->payments($id);
            }
            if ($method === 'POST') {
                return $gw->addPayment($id, $body);
            }
            if ($method === 'DELETE') {
                return $gw->deletePayment($id, $paymentId);
            }
        }

        throw new MyUctoApiException('not_found', 'Neznámá operace.', 404);
    }
}
