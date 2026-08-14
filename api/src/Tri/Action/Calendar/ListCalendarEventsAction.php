<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Calendar;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CalendarEventRepository;
use MyInvoice\Tri\Service\CalendarEventRules;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListCalendarEventsAction
{
    public function __construct(private readonly CalendarEventRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $q = $request->getQueryParams();
        $calendar = (string) ($q['calendar'] ?? '');
        if (!in_array($calendar, CalendarEventRules::CALENDARS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný kalendář.', 400);
        }
        try {
            $from = CalendarEventRules::parseQueryBound($q['from'] ?? '', false);
            $to = CalendarEventRules::parseQueryBound($q['to'] ?? '', true);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'validation_failed', 'Rozsah from/to je povinný.', 400);
        }

        return Json::ok($response, $this->repo->listInRange($supplierId, $calendar, $from, $to));
    }
}
