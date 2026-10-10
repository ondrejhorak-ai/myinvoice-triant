<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Pricing;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\PricingRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PricingAction
{
    public function __construct(private readonly PricingRepository $repo)
    {
    }
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $method = $request->getMethod();
        if ($method !== 'GET' && TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $sid = TriRequest::supplierId($request);
        $body = (array)($request->getParsedBody() ?? []);
        $id = (int)($args['id'] ?? 0);
        $rid = (int)($args['release'] ?? 0);
        $path = $request->getUri()->getPath();
        try {
            if ($method === 'GET' && str_ends_with($path, '/prices')) {
                $data = ['data' => $this->repo->productPrices($sid, $id)];
            } elseif ($method === 'GET' && str_contains($path, '/releases/')) {
                $data = $this->repo->release($sid, $rid);
            } elseif (str_contains($path, '/products')) {
                $data = $method === 'GET' ? $this->repo->catalog($sid, $request->getQueryParams()) : $this->repo->product($sid, $id, $body);
            } elseif ($method === 'GET') {
                $data = $id ? $this->repo->detail($sid, $id, (int)($request->getQueryParams()['release'] ?? 0)) : ['data' => $this->repo->lists($sid, !empty($request->getQueryParams()['include_archived']))];
            } elseif (str_ends_with($path, '/archive')) {
                $data = $this->repo->archive($sid, $id, (bool)($body['archived'] ?? true));
            } elseif (str_ends_with($path, '/draft')) {
                $data = $this->repo->draft($sid, $id);
            } elseif (str_ends_with($path, '/publish')) {
                $data = $this->repo->publish($sid, $id, $rid, (int)($body['lock_version'] ?? -1), TriRequest::userId($request));
            } elseif ($rid) {
                $data = $this->repo->save($sid, $id, $rid, $body);
            } else {
                $data = $this->repo->detail($sid, $this->repo->create($sid, $body));
            }
            return Json::ok($response, $data);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\UnexpectedValueException $e) {
            return Json::error($response, 'conflict', $e->getMessage(), 409);
        } catch (\DomainException $e) {
            return Json::error($response,'validation_failed',$e->getMessage(),422);
        }
    }
}
