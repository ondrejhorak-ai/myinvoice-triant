<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Service\QuoteImageService;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class GetLineImageAction
{
    public function __construct(private readonly QuoteImageService $images) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        $image = $this->images->find((int) ($args['id'] ?? 0), $supplierId);
        if ($image === null) {
            return Json::error($response, 'not_found', 'Obrázek nenalezen.', 404);
        }
        $path = $this->images->resolvePath($image, $supplierId);
        if ($path === null) {
            return Json::error($response, 'not_found', 'Soubor obrázku nenalezen.', 404);
        }

        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable')
            ->withHeader('ETag', '"' . $image['sha256'] . '"')
            ->withBody(new Stream(fopen($path, 'rb')));
    }
}
