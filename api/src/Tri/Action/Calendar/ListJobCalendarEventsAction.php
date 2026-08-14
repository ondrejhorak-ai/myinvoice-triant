<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Calendar;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CalendarEventRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListJobCalendarEventsAction
{
    public function __construct(private readonly CalendarEventRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = TriRequest::supplierId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $jobId = (int) ($args['id'] ?? 0);
        $from = (new \DateTimeImmutable('today'))->format('Y-m-d 00:00:00');

        return Json::ok($response, $this->repo->listUpcomingForJob($supplierId, $jobId, $from));
    }
}
