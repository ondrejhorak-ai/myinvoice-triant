<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Service;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\PdfBranding;
use MyInvoice\Tri\Repository\JobRepository;
use MyInvoice\Tri\Repository\QuoteRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renderuje cenovou nabídku (variantu zakázky TRI) jako PDF.
 * Stejná technologie jako faktury: Twig + mPDF, samostatný TRI branding.
 * Bez cache — vždy čerstvý render z uložených dat.
 */
final class QuotePdfRenderer
{
    public function __construct(
        private readonly QuoteRepository $quotes,
        private readonly JobRepository $jobs,
        private readonly Connection $db,
        private readonly QuoteImageService $images,
    ) {}

    /**
     * Vyrendruje PDF do dočasného souboru a vrátí absolutní cestu.
     */
    public function render(int $variantId, int $supplierId): string
    {
        $variant = $this->quotes->findVariant($variantId, $supplierId);
        if ($variant === null) {
            throw new \RuntimeException("Varianta #{$variantId} nenalezena");
        }

        $job = $this->jobs->find((int) $variant['job_id'], $supplierId);
        if ($job === null) {
            throw new \RuntimeException('Zakázka nenalezena');
        }

        if (empty($variant['line_items'])) {
            throw new \RuntimeException('Nabídka nemá žádné položky');
        }

        $supplier = $this->getSupplierData($supplierId);
        $client = $this->resolveClient($variant, $job);
        $blocks = $this->buildDisplayBlocks($variant, $supplierId);
        $siteAddress = $this->formatSiteAddress($job);
        $assignees = array_map(
            static fn (array $a) => (string) $a['name'],
            $job['assignees'] ?? [],
        );
        $validUntil = $this->resolveValidUntil($variant);
        $validityDays = $this->validityDays((string) ($variant['job_date'] ?? ''), $validUntil);

        $locale = 'cs';
        $quoteCssPath = Bootstrap::rootDir() . '/styles/quote.css';
        $css = is_file($quoteCssPath) ? (string) file_get_contents($quoteCssPath) : '';

        $logoPath = PdfBranding::logoPath($supplier, $supplierId);
        $iconDir = dirname(__DIR__, 3) . '/resources/quote-icons';

        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('t', static function (string $cs, string $en) use ($locale) {
            return $locale === 'en' ? $en : $cs;
        }));

        $body = $twig->render('quote.twig', [
            'variant'        => $variant,
            'job'            => $job,
            'supplier'       => $supplier,
            'client'         => $client,
            'blocks'         => $blocks,
            'site_address'   => $siteAddress,
            'assignees'      => $assignees,
            'valid_until'    => $validUntil,
            'validity_days'  => $validityDays,
            'locale'         => $locale,
            'currency'       => 'CZK',
            'date_format'    => 'j. n. Y',
            'decimal_sep'    => ',',
            'thousand_sep'   => "\u{00A0}",
            'css'            => '',
            'logo_path'      => $logoPath,
            'logo_show_name' => $logoPath !== null && !empty($supplier['pdf_logo_show_name']),
            // Datové pole pro obrázek varianty zatím neexistuje. Připravený
            // view-model dovolí jeho pozdější doplnění bez změny layoutu.
            'variant_image_path' => null,
            'icons'          => [
                'customer' => $iconDir . '/customer.svg',
                'site'     => $iconDir . '/site.svg',
                'offer'    => $iconDir . '/offer.svg',
                'calendar' => $iconDir . '/calendar.svg',
                'clock'    => $iconDir . '/clock.svg',
                'author'   => $iconDir . '/author.svg',
                'info'     => $iconDir . '/info.svg',
                'check'    => $iconDir . '/check.svg',
            ],
        ]);

        $footer = $twig->render('quote_footer.twig', [
            'supplier' => $supplier,
            'locale'   => $locale,
        ]);

        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
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
        $mpdf->SetCreator('MyInvoice.cz');

        if ($css !== '') {
            $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->SetHTMLFooter($footer);
        $mpdf->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);

        $safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $variant['number']);
        $path = $tmpDir . '/Nabidka-' . $safeNumber . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);

        return $path;
    }

    public function filename(array $variant): string
    {
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $variant['number']);

        return "Nabidka-{$safeNumber}.pdf";
    }

    /** @return list<array<string, mixed>> */
    private function buildDisplayBlocks(array $variant, int $supplierId): array
    {
        $sections = $variant['sections'] ?? [];
        $lines = $variant['line_items'] ?? [];

        usort($sections, static fn (array $a, array $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        usort($lines, static fn (array $a, array $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        $sectionBlocks = [];
        $topLevel = [];

        foreach ($sections as $section) {
            $id = (int) $section['id'];
            $sectionBlocks[$id] = [
                'kind'     => 'section',
                'title'    => (string) $section['title'],
                'items'    => [],
                'subtotal' => 0.0,
                'gross_subtotal' => 0.0,
            ];
            $topLevel[] = ['sort' => (int) $section['sort_order'], 'block' => &$sectionBlocks[$id]];
        }

        foreach ($lines as $line) {
            $item = $this->formatLineItem($line, $supplierId);
            $sid = $line['quote_section_id'] ?? null;
            if ($sid !== null && isset($sectionBlocks[(int) $sid])) {
                $sectionBlocks[(int) $sid]['items'][] = $item;
                $sectionBlocks[(int) $sid]['subtotal'] += (float) $item['line_total'];
                $sectionBlocks[(int) $sid]['gross_subtotal'] += (float) $item['gross_total'];
            } else {
                $topLevel[] = [
                    'sort'  => (int) ($line['sort_order'] ?? 0),
                    'block' => ['kind' => 'item', 'item' => $item],
                ];
            }
        }

        foreach ($sectionBlocks as &$sb) {
            $sb['subtotal'] = (float) round($sb['subtotal'], 0);
            $sb['gross_subtotal'] = (float) round($sb['gross_subtotal'], 0);
        }
        unset($sb);

        usort($topLevel, static fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $entry) => $entry['block'], $topLevel);
    }

    /** @param array<string, mixed> $line */
    private function formatLineItem(array $line, int $supplierId): array
    {
        $qty = (float) ($line['quantity'] ?? 0);
        $lineTotal = (float) round((float) ($line['line_total'] ?? 0), 0);
        $unitPrice = $qty != 0.0 ? (float) round($lineTotal / $qty, 0) : 0.0;

        $imagePath = null;
        $imageWidthMm = null;
        $imageHeightMm = null;
        $imageId = (int) ($line['image_id'] ?? 0);
        if ($imageId > 0) {
            $image = $this->images->find($imageId, $supplierId);
            if ($image !== null) {
                $imagePath = $this->images->resolvePath($image, $supplierId);
                [$imageWidthMm, $imageHeightMm] = $this->fitImageDimensions(
                    (int) $image['width_px'],
                    (int) $image['height_px'],
                );
            }
        }

        return [
            'designation' => (string) ($line['designation'] ?? ''),
            'title'       => (string) ($line['title'] ?? ''),
            'description' => $line['description'] ?? null,
            'quantity'    => $qty,
            'unit'        => (string) ($line['unit'] ?? 'ks'),
            'unit_price'  => $unitPrice,
            'vat_rate'    => (int) ($line['vat_rate'] ?? 21),
            'line_total'  => $lineTotal,
            'gross_total' => (float) round($lineTotal * (1 + ((int) ($line['vat_rate'] ?? 21) / 100)), 0),
            'image_path'  => $imagePath,
            'image_width_mm' => $imageWidthMm,
            'image_height_mm' => $imageHeightMm,
        ];
    }

    /** @return array{float,float} */
    private function fitImageDimensions(int $width, int $height): array
    {
        $width = max(1, $width);
        $height = max(1, $height);
        // Nezvětšovat malé zdroje nad 300 DPI; běžný 640px náhled se přitom
        // stále vejde do limitu 36 mm s rezervou pro Retina/zoom PDF.
        $scale = min(36 / $width, 36 / $height, 25.4 / 300);

        return [round($width * $scale, 2), round($height * $scale, 2)];
    }

    /**
     * Výchozí platnost nabídky = 30 dní od data vystavení (job_date).
     *
     * @param array<string, mixed> $variant
     */
    private function resolveValidUntil(array $variant): ?string
    {
        $stored = trim((string) ($variant['valid_until'] ?? ''));
        if ($stored !== '') {
            try {
                return (new \DateTimeImmutable($stored))->format('Y-m-d');
            } catch (\Exception) {
                // Neplatná historická hodnota nesmí zablokovat vygenerování PDF.
            }
        }

        $jobDate = (string) ($variant['job_date'] ?? '');
        if ($jobDate === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($jobDate);
        } catch (\Exception) {
            return null;
        }

        return $dt->modify('+30 days')->format('Y-m-d');
    }

    private function validityDays(string $issuedAt, ?string $validUntil): ?int
    {
        if ($issuedAt === '' || $validUntil === null) {
            return null;
        }
        try {
            $issued = new \DateTimeImmutable($issuedAt);
            $valid = new \DateTimeImmutable($validUntil);
        } catch (\Exception) {
            return null;
        }

        $days = (int) $issued->diff($valid)->format('%r%a');

        return $days >= 0 ? $days : null;
    }

    /** @param array<string, mixed> $variant @param array<string, mixed> $job */
    private function resolveClient(array $variant, array $job): array
    {
        $clientId = $variant['customer_client_id'] ?? $job['customer_client_id'] ?? null;
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

    /** @param array<string, mixed> $job */
    private function formatSiteAddress(array $job): ?string
    {
        $parts = array_filter([
            trim((string) ($job['site_street'] ?? '')),
            trim(trim((string) ($job['site_zip'] ?? '')) . ' ' . trim((string) ($job['site_city'] ?? ''))),
        ]);

        return $parts !== [] ? implode(', ', $parts) : null;
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
