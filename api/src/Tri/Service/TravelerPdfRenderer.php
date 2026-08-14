<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\PdfBranding;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Repository\TravelerRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Výrobní průvodka TRI jako A4 na výšku. Bez cen — jen identifikace položky
 * a prázdná tabulka stanovišť pro ruční zápis.
 */
final class TravelerPdfRenderer
{
    public const STATION_LABELS = [
        'konstrukce'          => ['Konstrukce', 'Construction'],
        'narezove_centrum'    => ['Nářezové centrum', 'Cut centre'],
        'cnc'                 => ['CNC', 'CNC'],
        'olepovacka'          => ['Olepovačka', 'Edgebander'],
        'dyhovani_brouseni'   => ['Dýhování a broušení', 'Veneer and sanding'],
        'montaz'              => ['Montáž', 'Assembly'],
        'lakovna'             => ['Lakovna', 'Paint shop'],
        'brouseni'            => ['Broušení', 'Sanding'],
        'baleni'              => ['Balení', 'Packing'],
    ];

    public function __construct(
        private readonly TravelerRepository $travelers,
        private readonly JobRepository $jobs,
        private readonly Connection $db,
        private readonly QuoteImageService $images,
    ) {}

    public function render(int $travelerId, int $supplierId): string
    {
        $traveler = $this->travelers->find($travelerId, $supplierId);
        if ($traveler === null) {
            throw new \RuntimeException('Průvodka nenalezena');
        }
        $job = $this->jobs->find((int) $traveler['job_id'], $supplierId);
        if ($job === null) {
            throw new \RuntimeException('Zakázka nenalezena');
        }

        return $this->writePdf(
            [$this->pageContext($traveler, $job, $supplierId)],
            self::filenameForTraveler($traveler),
            $supplierId,
        );
    }

    public function renderForJob(int $jobId, int $supplierId): string
    {
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null) {
            throw new \RuntimeException('Zakázka nenalezena');
        }
        $list = $this->travelers->listForSupplier($supplierId, $jobId);
        if ($list['data'] === []) {
            throw new \RuntimeException('Zakázka nemá žádné průvodky');
        }

        $pages = [];
        foreach ($list['data'] as $row) {
            $traveler = $this->travelers->find((int) $row['id'], $supplierId);
            if ($traveler === null) {
                continue;
            }
            $pages[] = $this->pageContext($traveler, $job, $supplierId);
        }
        if ($pages === []) {
            throw new \RuntimeException('Zakázka nemá žádné průvodky');
        }

        return $this->writePdf($pages, self::filenameForJob($job), $supplierId);
    }

    /**
     * Snapshot položky bez cen — i kdyby zdrojová data ceny obsahovala.
     *
     * @param array<string, mixed> $traveler
     * @return array{designation: string, title: string, description: ?string, quantity: float, unit: string}
     */
    public static function itemView(array $traveler): array
    {
        $qty = (float) ($traveler['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = trim((string) ($traveler['unit'] ?? 'ks'));

        return [
            'designation' => (string) ($traveler['designation'] ?? ''),
            'title'       => (string) ($traveler['title'] ?? ''),
            'description' => isset($traveler['description']) && $traveler['description'] !== ''
                ? (string) $traveler['description']
                : null,
            'quantity'    => $qty,
            'unit'        => $unit !== '' ? $unit : 'ks',
        ];
    }

    /**
     * 9 stanovišť v pevném pořadí. Kolonky datum/hodiny/podpis se v PDF nechávají prázdné.
     *
     * @return list<array{station: string, label_cs: string, label_en: string}>
     */
    public static function stationRows(): array
    {
        $rows = [];
        foreach (TravelerGenerator::STATIONS as $station) {
            $labels = self::STATION_LABELS[$station] ?? [$station, $station];
            $rows[] = [
                'station'  => $station,
                'label_cs' => $labels[0],
                'label_en' => $labels[1],
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $traveler */
    public static function filenameForTraveler(array $traveler): string
    {
        return self::safeBasename((string) ($traveler['number'] ?? 'pruvodka'), 'Pruvodka') . '.pdf';
    }

    /** @param array<string, mixed> $job */
    public static function filenameForJob(array $job): string
    {
        return self::safeBasename((string) ($job['number'] ?? 'zakazka'), 'Pruvodky') . '.pdf';
    }

    public static function safeBasename(string $name, string $prefix): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $name) ?? '';
        $safe = trim($safe, '_');

        return $safe !== '' ? $prefix . '-' . $safe : $prefix;
    }

    /** @return array{float, float} */
    public static function fitImageDimensions(int $width, int $height): array
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $scale = min(48 / $width, 48 / $height, 25.4 / 300);

        return [round($width * $scale, 2), round($height * $scale, 2)];
    }

    /**
     * @param array<string, mixed> $traveler
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function pageContext(array $traveler, array $job, int $supplierId): array
    {
        $item = self::itemView($traveler);
        $item['image_path'] = null;
        $item['image_width_mm'] = null;
        $item['image_height_mm'] = null;
        $imageId = $this->travelers->lineImageId(
            $traveler['quote_line_item_id'] !== null ? (int) $traveler['quote_line_item_id'] : null,
            $supplierId,
        );
        if ($imageId !== null && $imageId > 0) {
            $image = $this->images->find($imageId, $supplierId);
            if ($image !== null) {
                $item['image_path'] = $this->images->resolvePath($image, $supplierId);
                [$item['image_width_mm'], $item['image_height_mm']] = self::fitImageDimensions(
                    (int) $image['width_px'],
                    (int) $image['height_px'],
                );
            }
        }

        return [
            'traveler' => $traveler,
            'job'      => $job,
            'client'   => $this->resolveClient($job),
            'item'     => $item,
            'stations' => self::stationRows(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $pages
     */
    private function writePdf(array $pages, string $filename, int $supplierId): string
    {
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

        $shared = [
            'supplier'       => $supplier,
            'locale'         => $locale,
            'date_format'    => 'j. n. Y',
            'decimal_sep'    => ',',
            'thousand_sep'   => "\u{00A0}",
            'logo_path'      => $logoPath,
            'logo_show_name' => $logoPath !== null && !empty($supplier['pdf_logo_show_name']),
            'icons'          => [
                'customer' => $iconDir . '/customer.svg',
                'offer'    => $iconDir . '/offer.svg',
                'info'     => $iconDir . '/info.svg',
            ],
        ];

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
            'orientation'   => 'P',
            'margin_top'    => 10,
            'margin_bottom' => 22,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_footer' => 5,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyInvoice.cz');

        if ($css !== '') {
            $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->SetHTMLFooter($footer);

        foreach ($pages as $i => $page) {
            if ($i > 0) {
                $mpdf->AddPage();
            }
            $body = $twig->render('traveler.twig', $shared + $page);
            $mpdf->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);
        }

        $stem = pathinfo($filename, PATHINFO_FILENAME) ?: 'Pruvodka';
        $path = $tmpDir . '/' . $stem . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);

        return $path;
    }

    /** @param array<string, mixed> $job */
    private function resolveClient(array $job): array
    {
        $clientId = $job['customer_client_id'] ?? null;
        if ($clientId === null) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c
               JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?'
        );
        $stmt->execute([(int) $clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
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
