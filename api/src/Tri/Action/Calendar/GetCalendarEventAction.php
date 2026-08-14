<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Calendar;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\CalendarEventRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetCalendarEventAction
{
    public function __construct(private readonly CalendarEventRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $event = $this->repo->find((int) ($args['id'] ?? 0), TriRequest::supplierId($request));
        if ($event === null) {
            return Json::error($response, 'not_found', 'Událost nenalezena.', 404);
        }

        return Json::ok($response, $event);
    }
}
