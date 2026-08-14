<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Traveler;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\TravelerRepository;
use MyInvoice\Tri\Service\TravelerHours;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateTravelerOperationsAction
{
    public function __construct(private readonly TravelerRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Průvodka nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $operations = [];
        if (array_key_exists('operations', $body)) {
            try {
                $operations = TravelerHours::normalizeOperations($body['operations']);
            } catch (\InvalidArgumentException $e) {
                return Json::error($response, 'validation_failed', self::message($e->getMessage()), 400);
            }
        }

        $status = null;
        if (array_key_exists('status', $body) && $body['status'] !== null && $body['status'] !== '') {
            $status = (string) $body['status'];
            if (!in_array($status, ['open', 'done'], true)) {
                return Json::error($response, 'validation_failed', 'Neplatný stav průvodky.', 400);
            }
        }

        $updated = $this->repo->updateOperations($id, $supplierId, $operations, $status);
        if ($updated === null) {
            return Json::error($response, 'not_found', 'Průvodka nenalezena.', 404);
        }

        return Json::ok($response, $updated);
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'INVALID_STATION' => 'Neznámé stanoviště.',
            'DUPLICATE_STATION' => 'Stanoviště je v požadavku vícekrát.',
            'INVALID_HOURS' => 'Hodiny musí být číslo 0–9999,99.',
            'NOTE_TOO_LONG' => 'Poznámka je příliš dlouhá.',
            default => 'Neplatné stanoviště nebo hodiny.',
        };
    }
}
