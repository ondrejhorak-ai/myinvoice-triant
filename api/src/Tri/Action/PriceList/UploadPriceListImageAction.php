<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\PriceList;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Tri\Repository\PriceListRepository;
use MyInvoice\Tri\Service\QuoteImageException;
use MyInvoice\Tri\Service\QuoteImageService;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class UploadPriceListImageAction
{
    public function __construct(
        private readonly PriceListRepository $lists,
        private readonly QuoteImageService $images,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $supplierId = TriRequest::supplierId($request);
        if (!$this->lists->exists((int) ($args['id'] ?? 0), $supplierId)) {
            return Json::error($response, 'not_found', 'Ceník nenalezen.', 404);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Nebyl odeslán obrázek v poli `file`.', 400);
        }
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return Json::error($response, 'file_too_large', 'Obrázek je příliš velký (max 10 MiB).', 413);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
        }
        $reportedSize = (int) ($file->getSize() ?? 0);
        if ($reportedSize > QuoteImageService::MAX_INPUT_BYTES) {
            return Json::error($response, 'file_too_large', 'Obrázek je příliš velký (max 10 MiB).', 413);
        }

        $tmpDir = RuntimePaths::storage('tmp/tri-quote-images');
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            return Json::error($response, 'storage_failed', 'Nepodařilo se připravit dočasné úložiště.', 500);
        }
        $tmpPath = $tmpDir . '/.upload-' . bin2hex(random_bytes(8));
        try {
            $file->moveTo($tmpPath);
            $image = $this->images->process($tmpPath, $supplierId);
        } catch (QuoteImageException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::error($response, 'upload_failed', 'Obrázek se nepodařilo zpracovat.', 500);
        } finally {
            @unlink($tmpPath);
        }

        return Json::ok($response, $image, 201);
    }
}
