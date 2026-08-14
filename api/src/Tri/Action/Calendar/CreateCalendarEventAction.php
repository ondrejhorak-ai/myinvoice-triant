<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Calendar;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CalendarEventRepository;
use MyInvoice\Tri\Service\CalendarEventRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateCalendarEventAction
{
    public function __construct(private readonly CalendarEventRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        try {
            $data = CalendarEventRules::normalize((array) ($request->getParsedBody() ?? []));
            $created = $this->repo->create($supplierId, $data);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', CalendarError::message($e->getMessage()), 400);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'JOB_NOT_FOUND') {
                return Json::error($response, 'not_found', 'Zakázka nenalezena.', 400);
            }
            throw $e;
        }

        return Json::ok($response, $created, 201);
    }
}
