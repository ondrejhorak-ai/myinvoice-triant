<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Pricing;

use MyInvoice\Http\Json;
use MyInvoice\Tri\Repository\PricingRepository;
use MyInvoice\Tri\Service\PriceReleaseRules;
use MyInvoice\Tri\Support\TriRequest;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Read-only preview; applying the result still uses the draft's optimistic save. */
final class ImportPreviewAction
{
    public function __construct(private readonly PricingRepository $repo)
    {
    }
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (TriRequest::userRole($request) === 'readonly') {
            return Json::error($response, 'forbidden', 'Nedostatečná oprávnění.', 403);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK || $file->getSize() > 5 * 1024 * 1024) {
            return Json::error($response, 'validation_failed', 'Vyberte XLS nebo XLSX soubor do 5 MB.', 422);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tri-prices-');
        try {
            $list = $this->repo->detail(TriRequest::supplierId($request), (int)$args['id']);
            $file->moveTo($tmp);
            $type = IOFactory::identify($tmp, ['Xlsx','Xls']);
            $reader = IOFactory::createReader($type);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class () implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 10002 && in_array($columnAddress, ['A','B','C','D'], true);
                }
            });
            $book = $reader->load($tmp);
            $sheet = $book->getSheet(0);
            if ($sheet->getHighestDataRow() > 10001) {
                throw new \DomainException('Soubor má příliš mnoho řádků.');
            }
            // Stable interchange header avoids locale-dependent column guessing.
            $headers = array_map(fn ($col) => strtolower(trim((string)$sheet->getCell($col.'1')->getValue())), ['A','B','C','D']);
            if ($headers !== ['code','unit_price','unit','currency']) {
                throw new \DomainException('Hlavička musí být: code, unit_price, unit, currency.');
            }
            $rows = [];
            $seen = [];
            for ($n = 2;$n <= $sheet->getHighestDataRow();$n++) {
                $v = array_map(fn ($col) => trim((string)$sheet->getCell($col.$n)->getValue()), ['A','B','C','D']);
                if (implode('', $v) === '') {
                    continue;
                }
                [$code,$price,$unit,$currency] = $v;
                $error = '';
                try {
                    $price = PriceReleaseRules::money($price);
                    if ($price === null) {
                        throw new \DomainException('Chybí cena.');
                    }
                } catch (\DomainException $e) {
                    $error = $e->getMessage();
                }
                if ($code === '') {
                    $error = 'Chybí kód.';
                }
                if (isset($seen[$code])) {
                    $error = 'Duplicitní kód.';
                    $rows[$seen[$code]]['error'] = $error;
                }
                $product = array_find($list['items'], fn (array $i): bool => $i['code'] === $code);
                if (!$product) {
                    $error = 'Kód není v tomto vydání.';
                } elseif ($product['unit'] !== $unit) {
                    $error = 'Jednotka neodpovídá položce.';
                }
                if ($currency !== $list['currency']) {
                    $error = 'Měna neodpovídá ceníku.';
                }
                $seen[$code] = count($rows);
                $rows[] = ['code' => $code,'unit_price' => $price ?? '','unit' => $unit,'currency' => $currency,'error' => $error];
            }
            $book->disconnectWorksheets();
            return Json::ok($response, ['rows' => $rows]);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', $e instanceof \DomainException ? $e->getMessage() : 'Soubor se nepodařilo přečíst.', 422);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
