<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Activity;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobActivityRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /api/tri/jobs/{id}/activity — nový komentář uživatele. */
final class CreateJobCommentAction
{
    private const MAX_LENGTH = 10000;

    public function __construct(private readonly JobActivityRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }

        $jobId = (int) $args['id'];
        $supplierId = TriRequest::supplierId($request);
        if (!$this->repo->jobExists($jobId, $supplierId)) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $text = trim((string) ($body['body'] ?? ''));
        if ($text === '') {
            return Json::error($response, 'validation_failed', 'Text komentáře je povinný.', 400);
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return Json::error($response, 'validation_failed', 'Komentář je příliš dlouhý.', 400);
        }

        $item = $this->repo->addComment($jobId, TriRequest::userId($request), $text);
        unset($item['supplier_id']);

        return Json::ok($response, $item, 201);
    }
}
