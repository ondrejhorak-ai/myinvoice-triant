<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Cash;

use MyInvoice\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;

final class CashErrors
{
    public static function from(\InvalidArgumentException $e, Response $response): Response
    {
        return match ($e->getMessage()) {
            'NOT_FOUND' => Json::error($response, 'not_found', 'Pohyb nenalezen.', 404),
            'ACCOUNTED' => Json::error($response, 'accounted', 'Zaúčtovaný pohyb smí změnit jen správce.', 403),
            'DELETED' => Json::error($response, 'deleted', 'Smazaný pohyb nejdřív obnovte.', 409),
            'INVALID_JOB' => Json::error($response, 'validation_failed', 'Vyberte zakázku z tohoto Office.', 400),
            'INVALID_USER' => Json::error($response, 'validation_failed', 'Neplatný uživatel.', 400),
            'INVALID_IDS' => Json::error($response, 'validation_failed', 'Vyberte pohyby k zaúčtování.', 400),
            default => Json::error($response, 'validation_failed', $e->getMessage(), 400),
        };
    }
}
