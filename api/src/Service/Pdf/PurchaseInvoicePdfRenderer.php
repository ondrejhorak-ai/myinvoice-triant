<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;

/**
 * Render přijaté faktury jako PDF (naše verze).
 *
 * **Use case:** Když nemáme originální PDF od dodavatele (např. importováno z
 * iDoklad/Fakturoid jen metadata, nebo zadané ručně), můžeme vygenerovat naši
 * vlastní verzi PDF pro účetní archiv.
 *
 * Layout je minimalistický — pro plnohodnotný "dodavatelský look" doporučujeme
 * originál (pokud existuje v `pdf_path`).
 */
final class PurchaseInvoicePdfRenderer
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Render do PDF binary string.
     */
    public function render(int $purchaseInvoiceId, int $supplierId): string
    {
        $invoice = $this->repo->find($purchaseInvoiceId, $supplierId);
        if ($invoice === null) {
            throw new \RuntimeException("Přijatá faktura #{$purchaseInvoiceId} nenalezena.");
        }
        $vendor = $this->loadVendor((int) $invoice['vendor_id']);
        $items = $invoice['items'] ?? [];
        $totals = $invoice['totals'] ?? ['without_vat' => 0, 'vat' => 0, 'with_vat' => 0];
        $currency = $invoice['currency'] ?? 'CZK';

        $html = $this->buildHtml($invoice, $vendor, $items, $totals, $currency);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15,
            'default_font' => 'dejavusans',
            'tempDir' => Bootstrap::rootDir() . '/storage/mpdf-temp',
        ]);
        $mpdf->SetTitle('Přijatá faktura ' . ($invoice['vendor_invoice_number'] ?? '#' . $invoice['id']));
        $mpdf->SetCreator('MyInvoice.cz');
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S');
    }

    private function loadVendor(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id, c.company_name, c.street, c.city, c.zip, c.ic, c.dic,
                    c.main_email AS email, c.phone, COALESCE(cnt.iso2, 'CZ') AS country_iso2
               FROM clients c
          LEFT JOIN countries cnt ON cnt.id = c.country_id
              WHERE c.id = ?"
        );
        $stmt->execute([$vendorId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $vendor
     * @param list<array<string,mixed>> $items
     * @param array{without_vat:float, vat:float, with_vat:float} $totals
     */
    private function buildHtml(array $invoice, array $vendor, array $items, array $totals, string $currency): string
    {
        $h = fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $money = fn (float $v): string => number_format($v, 2, ',', ' ') . ' ' . $currency;
        $date = fn (?string $d): string => $d ? date('j. n. Y', strtotime($d)) : '—';

        $itemsHtml = '';
        foreach ($items as $i => $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['unit_price_without_vat'] ?? 0);
            $vatRate = (float) ($item['vat_rate_snapshot'] ?? $item['vat_rate'] ?? 0);
            $totalNoVat = (float) ($item['total_without_vat'] ?? ($qty * $price));
            $itemsHtml .= '<tr>'
                . '<td>' . ($i + 1) . '.</td>'
                . '<td>' . $h($item['description'] ?? '') . '</td>'
                . '<td class="num">' . number_format($qty, 2, ',', ' ') . ' ' . $h($item['unit'] ?? 'ks') . '</td>'
                . '<td class="num">' . $money($price) . '</td>'
                . '<td class="num">' . number_format($vatRate, 0) . '%</td>'
                . '<td class="num">' . $money($totalNoVat) . '</td>'
                . '</tr>';
        }
        if ($itemsHtml === '') {
            $itemsHtml = '<tr><td colspan="6" class="empty">Žádné položky</td></tr>';
        }

        $reverseChargeNote = '';
        if (!empty($invoice['reverse_charge'])) {
            $reverseChargeNote = '<p class="rc-note"><strong>Daň odvádí zákazník (přenesená daňová povinnost).</strong></p>';
        }

        $noteAbove = !empty($invoice['note_above_items'])
            ? '<div class="note-above">' . nl2br($h($invoice['note_above_items'])) . '</div>'
            : '';
        $noteBelow = !empty($invoice['note_below_items'])
            ? '<div class="note-below">' . nl2br($h($invoice['note_below_items'])) . '</div>'
            : '';

        $varsymbol = $invoice['varsymbol'] ?? '';
        $vendorInvNum = $invoice['vendor_invoice_number'] ?? '';
        $docKindLabel = match ($invoice['document_kind'] ?? 'invoice') {
            'receipt'     => 'Účtenka',
            'credit_note' => 'Dobropis',
            'advance'     => 'Záloha',
            default       => 'Faktura',
        };

        return <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Přijatá {$docKindLabel} {$h($vendorInvNum)}</title>
<style>
  body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #1f2937; }
  h1 { font-size: 18pt; margin: 0 0 4pt; color: #111827; }
  .header-block { width: 100%; margin-bottom: 12pt; }
  .header-block td { vertical-align: top; padding: 0; }
  .label { font-size: 7pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5pt; }
  .vendor-name { font-weight: bold; font-size: 12pt; }
  .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 12pt; }
  .meta-table td { padding: 3pt 5pt; border: 1px solid #e5e7eb; font-size: 8pt; }
  .meta-table .lbl { background: #f9fafb; color: #6b7280; width: 30%; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 8pt; }
  table.items thead { background: #f3f4f6; }
  table.items th, table.items td { padding: 4pt 6pt; border-bottom: 1px solid #e5e7eb; text-align: left; }
  table.items th { font-size: 7pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5pt; }
  table.items .num { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
  table.items td.empty { text-align: center; color: #9ca3af; padding: 12pt; }
  .totals { width: 50%; margin-left: 50%; margin-top: 8pt; border-collapse: collapse; }
  .totals td { padding: 4pt 8pt; }
  .totals .lbl { color: #6b7280; }
  .totals .num { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
  .totals tr.grand td { font-weight: bold; font-size: 11pt; border-top: 2px solid #111827; padding-top: 6pt; }
  .note-above, .note-below { margin: 6pt 0; padding: 6pt; background: #f9fafb; font-size: 8pt; }
  .rc-note { background: #fef3c7; padding: 6pt; margin: 8pt 0; border-left: 3px solid #f59e0b; font-size: 9pt; }
  .footer-note { margin-top: 12pt; padding-top: 6pt; border-top: 1px solid #e5e7eb; font-size: 7pt; color: #9ca3af; text-align: center; }
</style>
</head>
<body>

<table class="header-block">
  <tr>
    <td style="width: 60%;">
      <h1>Přijatá {$docKindLabel}</h1>
      <div class="label">Číslo dokladu od dodavatele</div>
      <div style="font-size: 13pt; font-weight: bold;">{$h($vendorInvNum)}</div>
    </td>
    <td style="width: 40%; text-align: right;">
      <div class="label">Naše interní číslo</div>
      <div style="font-size: 11pt;">{$h($varsymbol)}</div>
    </td>
  </tr>
</table>

<table class="header-block">
  <tr>
    <td style="width: 50%; padding-right: 8pt;">
      <div class="label">Dodavatel</div>
      <div class="vendor-name">{$h($vendor['company_name'] ?? '')}</div>
      <div>{$h($vendor['street'] ?? '')}</div>
      <div>{$h($vendor['zip'] ?? '')} {$h($vendor['city'] ?? '')}</div>
      <div>{$h($vendor['country_iso2'] ?? 'CZ')}</div>
HTML
        . (!empty($vendor['ic']) ? '<div>IČ: ' . $h($vendor['ic']) . '</div>' : '')
        . (!empty($vendor['dic']) ? '<div>DIČ: ' . $h($vendor['dic']) . '</div>' : '')
        . <<<HTML
    </td>
    <td style="width: 50%;">
      <table class="meta-table">
        <tr><td class="lbl">Datum vystavení</td><td>{$date($invoice['issue_date'])}</td></tr>
        <tr><td class="lbl">Datum zdanitelného plnění</td><td>{$date($invoice['tax_date'])}</td></tr>
        <tr><td class="lbl">Datum splatnosti</td><td>{$date($invoice['due_date'])}</td></tr>
        <tr><td class="lbl">Datum přijetí</td><td>{$date($invoice['received_at'])}</td></tr>
        <tr><td class="lbl">Variabilní symbol</td><td>{$h($varsymbol)}</td></tr>
        <tr><td class="lbl">Měna</td><td>{$h($currency)}</td></tr>
      </table>
    </td>
  </tr>
</table>

{$noteAbove}

{$reverseChargeNote}

<table class="items">
  <thead>
    <tr>
      <th>#</th>
      <th>Popis</th>
      <th class="num">Množství</th>
      <th class="num">Jedn. cena</th>
      <th class="num">DPH</th>
      <th class="num">Celkem bez DPH</th>
    </tr>
  </thead>
  <tbody>
    {$itemsHtml}
  </tbody>
</table>

<table class="totals">
  <tr>
    <td class="lbl">Celkem bez DPH</td>
    <td class="num">{$money((float) $totals['without_vat'])}</td>
  </tr>
  <tr>
    <td class="lbl">DPH celkem</td>
    <td class="num">{$money((float) $totals['vat'])}</td>
  </tr>
  <tr class="grand">
    <td class="lbl">Celkem s DPH</td>
    <td class="num">{$money((float) $totals['with_vat'])}</td>
  </tr>
</table>

{$noteBelow}

<div class="footer-note">
  Naše rekonstrukce přijaté faktury z dat v MyInvoice.cz. Originál od dodavatele je referenční dokument.<br>
  Vygenerováno: {$date(date('Y-m-d'))}
</div>

</body>
</html>
HTML;
    }
}
