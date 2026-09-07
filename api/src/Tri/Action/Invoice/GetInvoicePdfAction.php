<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\MyUcto\InvoiceGateway;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use MyInvoice\Tri\MyUcto\MyUctoClient;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class GetInvoicePdfAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MyUctoClient $api,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $gw = new InvoiceGateway($this->api, $this->db->pdo(), TriRequest::supplierId($request));
        try {
            $pdf = $gw->pdf((int) ($args['id'] ?? 0));
        } catch (MyUctoApiException $e) {
            return InvoiceError::fromException($response, $e);
        }

        $download = !empty($request->getQueryParams()['download']);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return Json::error($response, 'pdf_failed', 'Nelze otevřít stream.', 500);
        }
        fwrite($stream, $pdf['body']);
        rewind($stream);

        $filename = 'faktura-' . (int) ($args['id'] ?? 0) . '.pdf';
        $disp = ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"';

        return $response
            ->withBody(new Stream($stream))
            ->withHeader('Content-Type', $pdf['contentType'] ?: 'application/pdf')
            ->withHeader('Content-Disposition', $disp)
            ->withHeader('Cache-Control', 'private, max-age=60');
    }
}
