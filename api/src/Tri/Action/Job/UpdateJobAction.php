<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Job;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Service\JobNumberConflictException;
use MyInvoice\Tri\Service\JobNumberFormatException;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateJobAction
{
    public function __construct(
        private readonly JobRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        $clientIds = array_map('intval', (array) ($body['client_ids'] ?? []));

        try {
            $job = $this->repo->update($id, $supplierId, $body, $clientIds);
        } catch (JobNumberConflictException) {
            return Json::error($response, 'conflict', 'Toto číslo zakázky už existuje.', 409);
        } catch (JobNumberFormatException) {
            return Json::error($response, 'validation_failed', 'Neplatný formát čísla zakázky.', 400);
        }

        if ($job === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('tri_job.updated', TriRequest::userId($request), 'tri_job', $id, [], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $job);
    }
}
