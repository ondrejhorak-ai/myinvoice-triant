<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Service\QuotePdfRenderer;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class PdfAction
{
    public function __construct(
        private readonly QuotePdfRenderer $renderer,
        private readonly QuoteRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $supplierId = TriRequest::supplierId($request);
        $id = (int) ($args['id'] ?? 0);

        $variant = $this->repo->findVariant($id, $supplierId);
        if ($variant === null) {
            return Json::error($response, 'not_found', 'Varianta nenalezena.', 404);
        }

        $q = $request->getQueryParams();
        $download = !empty($q['download']);

        ob_start();
        try {
            $path = $this->renderer->render($id, $supplierId);
        } catch (\Throwable $e) {
            ob_end_clean();
            return Json::error($response, 'pdf_failed', $e->getMessage(), 500);
        }
        ob_end_clean();

        $filename = $this->renderer->filename($variant);
        $disposition = $download ? "attachment; filename=\"{$filename}\"" : "inline; filename=\"{$filename}\"";

        $stream = new Stream(fopen($path, 'rb'));
        $response = $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);

        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $response;
    }
}
