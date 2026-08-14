<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Traveler;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Service\TravelerPdfRenderer;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class JobPdfAction
{
    public function __construct(
        private readonly TravelerPdfRenderer $renderer,
        private readonly JobRepository $jobs,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $supplierId = TriRequest::supplierId($request);
        $jobId = (int) ($args['id'] ?? 0);
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $q = $request->getQueryParams();
        $download = !empty($q['download']);

        ob_start();
        try {
            $path = $this->renderer->renderForJob($jobId, $supplierId);
        } catch (\RuntimeException $e) {
            ob_end_clean();
            if (str_contains($e->getMessage(), 'žádné průvodky')) {
                return Json::error($response, 'empty', $e->getMessage(), 400);
            }
            if (str_contains($e->getMessage(), 'nenalezena')) {
                return Json::error($response, 'not_found', $e->getMessage(), 404);
            }

            return Json::error($response, 'pdf_failed', $e->getMessage(), 500);
        } catch (\Throwable $e) {
            ob_end_clean();

            return Json::error($response, 'pdf_failed', $e->getMessage(), 500);
        }
        ob_end_clean();

        return PdfAction::pdfResponse($response, $path, TravelerPdfRenderer::filenameForJob($job), $download);
    }
}
