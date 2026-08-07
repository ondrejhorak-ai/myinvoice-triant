<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Activity;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobActivityRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** PUT /api/tri/activity/{id} — úprava vlastního komentáře. */
final class UpdateJobCommentAction
{
    private const MAX_LENGTH = 10000;

    public function __construct(private readonly JobActivityRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }

        $id = (int) $args['id'];
        $item = $this->repo->findItem($id);
        if ($item === null || $item['kind'] !== 'comment' || (int) $item['supplier_id'] !== TriRequest::supplierId($request)) {
            return Json::error($response, 'not_found', 'Komentář nenalezen.', 404);
        }
        if ((int) $item['user_id'] !== TriRequest::userId($request)) {
            return Json::error($response, 'forbidden', 'Upravit lze jen vlastní komentář.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $text = trim((string) ($body['body'] ?? ''));
        if ($text === '') {
            return Json::error($response, 'validation_failed', 'Text komentáře je povinný.', 400);
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return Json::error($response, 'validation_failed', 'Komentář je příliš dlouhý.', 400);
        }

        $updated = $this->repo->updateComment($id, $text);
        assert($updated !== null);
        unset($updated['supplier_id']);

        return Json::ok($response, $updated);
    }
}
