<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\PdfBranding;
use MyInvoice\Tri\Repository\PriceListRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renderuje ceník TRI jako PDF. Stejný stack jako nabídka: Twig + mPDF + quote.css.
 */
final class PriceListPdfRenderer
{
    public function __construct(
        private readonly PriceListRepository $lists,
        private readonly Connection $db,
        private readonly QuoteImageService $images,
        private readonly QuoteCalculator $calculator,
    ) {}

    public function render(int $priceListId, int $supplierId): string
    {
        $list = $this->lists->find($priceListId, $supplierId);
        if ($list === null) {
            throw new \RuntimeException("Ceník #{$priceListId} nenalezen");
        }
        $rawItems = $list['items'] ?? [];
        if ($rawItems === []) {
            throw new \RuntimeException('Ceník nemá žádné položky');
        }

        $items = [];
        $calcLines = [];
        foreach ($rawItems as $line) {
            if (!is_array($line)) {
                continue;
            }
            $items[] = $this->formatLineItem($line, $supplierId);
            $calcLines[] = [
                'quantity'        => (float) ($line['default_quantity'] ?? 1),
                'base_unit_price' => (float) ($line['base_unit_price'] ?? 0),
                'vat_rate'        => (int) ($line['vat_rate'] ?? 21),
            ];
        }
        if ($items === []) {
            throw new \RuntimeException('Ceník nemá žádné položky');
        }

        $totals = $this->calculator->calculateVariant([], $calcLines, []);
        $supplier = $this->getSupplierData($supplierId);
        $locale = 'cs';
        $quoteCssPath = Bootstrap::rootDir() . '/styles/quote.css';
        $css = is_file($quoteCssPath) ? (string) file_get_contents($quoteCssPath) : '';
        $logoPath = PdfBranding::logoPath($supplier, $supplierId);
        $iconDir = dirname(__DIR__, 3) . '/resources/quote-icons';

        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('t', static function (string $cs, string $en) use ($locale) {
            return $locale === 'en' ? $en : $cs;
        }));

        $body = $twig->render('price_list.twig', [
            'list'           => $list,
            'items'          => $items,
            'totals'         => $totals,
            'supplier'       => $supplier,
            'locale'         => $locale,
            'currency'       => 'CZK',
            'date_format'    => 'j. n. Y',
            'decimal_sep'    => ',',
            'thousand_sep'   => "\u{00A0}",
            'logo_path'      => $logoPath,
            'logo_show_name' => $logoPath !== null && !empty($supplier['pdf_logo_show_name']),
            'icons'          => [
                'offer'    => $iconDir . '/offer.svg',
                'calendar' => $iconDir . '/calendar.svg',
                'info'     => $iconDir . '/info.svg',
            ],
        ]);

        $footer = $twig->render('quote_footer.twig', [
            'supplier' => $supplier,
            'locale'   => $locale,
        ]);

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'L',
            'margin_top'    => 10,
            'margin_bottom' => 24,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_footer' => 5,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('Triant office');

        if ($css !== '') {
            $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->SetHTMLFooter($footer);
        $mpdf->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);

        $path = $tmpDir . '/' . self::safeBasename($list['name'] ?? 'cenik') . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);

        return $path;
    }

    /** @param array<string, mixed> $list */
    public function filename(array $list): string
    {
        return self::safeBasename($list['name'] ?? 'cenik') . '.pdf';
    }

    public static function safeBasename(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $name) ?? 'cenik';
        $safe = trim($safe, '_');

        return $safe !== '' ? 'Cenik-' . $safe : 'Cenik';
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public static function pricedItem(array $line): array
    {
        $qty = (float) ($line['default_quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unitPrice = (float) ($line['base_unit_price'] ?? 0);
        $vat = (int) ($line['vat_rate'] ?? 21);
        $lineTotal = QuoteCalculator::round2($qty * $unitPrice);

        return [
            'designation' => (string) ($line['designation'] ?? ''),
            'title'       => (string) ($line['title'] ?? ''),
            'description' => $line['description'] ?? null,
            'quantity'    => $qty,
            'unit'        => (string) ($line['unit'] ?? 'ks'),
            'unit_price'  => $unitPrice,
            'vat_rate'    => $vat,
            'line_total'  => $lineTotal,
            'gross_total' => QuoteCalculator::round2($lineTotal * (1 + $vat / 100)),
            'price_updated_at' => $line['price_updated_at'] ?? null,
        ];
    }

    /** @return array{float,float} */
    public static function fitImageDimensions(int $width, int $height): array
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $scale = min(36 / $width, 36 / $height, 25.4 / 300);

        return [round($width * $scale, 2), round($height * $scale, 2)];
    }

    /** @param array<string, mixed> $line */
    private function formatLineItem(array $line, int $supplierId): array
    {
        $item = self::pricedItem($line);
        $item['image_path'] = null;
        $item['image_width_mm'] = null;
        $item['image_height_mm'] = null;
        $imageId = (int) ($line['image_id'] ?? 0);
        if ($imageId > 0) {
            $image = $this->images->find($imageId, $supplierId);
            if ($image !== null) {
                $item['image_path'] = $this->images->resolvePath($image, $supplierId);
                [$item['image_width_mm'], $item['image_height_mm']] = self::fitImageDimensions(
                    (int) $image['width_px'],
                    (int) $image['height_px'],
                );
            }
        }

        return $item;
    }

    private function getSupplierData(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s
               JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function twig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/tri');

        return new Environment($loader, [
            'autoescape'       => 'html',
            'cache'            => false,
            'strict_variables' => false,
        ]);
    }
}
