<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\QuoteRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SaveVariantAction
{
    public function __construct(private readonly QuoteRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $variant = $this->repo->saveVariant((int) $args['id'], TriRequest::supplierId($request), $body);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'LOCK_VERSION_CONFLICT') {
                return Json::error($response, 'conflict', 'Varianta byla mezitím upravena jiným uživatelem.', 409);
            }
            if ($e->getMessage() === 'INVALID_QUOTE_IMAGE') {
                return Json::error($response, 'invalid_image', 'Vybraný obrázek není dostupný.', 400);
            }
            throw $e;
        } catch (\PDOException $e) {
            // SQLSTATE 22001 = string data right truncation (too long for column)
            if ($e->getCode() === '22001' || str_contains($e->getMessage(), '22001')) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Některý text je příliš dlouhý (název položky, označení nebo sekce).',
                    400
                );
            }
            throw $e;
        }
        if ($variant === null) {
            return Json::error($response, 'not_found', 'Varianta nenalezena.', 404);
        }

        return Json::ok($response, $variant);
    }
}
