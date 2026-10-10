<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tri;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tri\Action\Pricing\ImportPreviewAction;
use MyInvoice\Tri\Repository\PricingRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

final class ImportPreviewActionTest extends TestCase
{
    public function testPreviewValidatesWithoutWritingPrices(): void
    {
        $repo = $this->createMock(PricingRepository::class);
        $repo->method('detail')->willReturn(['currency' => 'CZK','items' => [['code' => 'TEST-PRODUCT','unit' => 'ks']]]);
        $repo->expects($this->never())->method('save');
        $repo->expects($this->never())->method('product');
        foreach (['valid','duplicate','unknown','currency','unit','formula'] as $case) {
            $book = new Spreadsheet();
            $row = [$case === 'unknown' ? 'MISSING-PRODUCT' : 'TEST-PRODUCT',$case === 'formula' ? '=1+1' : 222,$case === 'unit' ? 'm' : 'ks',$case === 'currency' ? 'EUR' : 'CZK'];
            $rows = [['code','unit_price','unit','currency'],$row];
            if ($case === 'duplicate') {
                $rows[] = $row;
            }
            $book->getActiveSheet()->fromArray($rows);
            $path = tempnam(sys_get_temp_dir(), 'tri-preview-test-');
            try {
                (new Xlsx($book))->save($path);
                $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/tri/pricing/lists/1/import-preview')
                    ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
                    ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin'])
                    ->withUploadedFiles(['file' => new UploadedFile($path, 'synthetic.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', filesize($path), UPLOAD_ERR_OK)]);
                $response = (new ImportPreviewAction($repo))($request, new Response(), ['id' => 1]);
                self::assertSame(200, $response->getStatusCode(), $case);
                $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($case === 'valid', $data['rows'][0]['error'] === '', $case);
                if ($case === 'duplicate') {
                    self::assertNotEmpty($data['rows'][1]['error']);
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
                $book->disconnectWorksheets();
            }
        }
    }

    public function testReadonlyCannotUpload(): void
    {
        $repo = $this->createMock(PricingRepository::class);
        $repo->expects($this->never())->method('detail');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/tri/pricing/lists/1/import-preview')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'readonly']);
        self::assertSame(403, (new ImportPreviewAction($repo))($request, new Response(), ['id' => 1])->getStatusCode());
    }
}
