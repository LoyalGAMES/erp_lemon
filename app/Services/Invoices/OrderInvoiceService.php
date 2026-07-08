<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\Invoice;
use App\Models\WarehouseDocument;
use App\Services\Automation\InvoiceKsefAutomationService;
use App\Services\Orders\OrderFulfillmentStatusService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class OrderInvoiceService
{
    public function __construct(
        private readonly InvoiceNumberService $numbers,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly InvoiceTemplateService $templates,
        private readonly InvoiceSettingsService $settings,
        private readonly InvoiceKsefAutomationService $ksefAutomation,
        private readonly InvoiceCurrencyConversionService $currencyConversion,
        private readonly OssVatRateService $ossVatRates,
    ) {}

    public function createForOrder(ExternalOrder $order): Invoice
    {
        return $this->createDocumentForOrder($order, 'vat');
    }

    public function createProformaForOrder(ExternalOrder $order): Invoice
    {
        return $this->createDocumentForOrder($order, 'proforma');
    }

    private function createDocumentForOrder(ExternalOrder $order, string $type): Invoice
    {
        $createdInvoiceId = null;

        $invoice = DB::transaction(function () use ($order, $type, &$createdInvoiceId): Invoice {
            $order = ExternalOrder::query()
                ->with(['lines', 'invoices'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $existing = $order->invoices()
                ->where('type', $type)
                ->latest()
                ->first();

            if ($existing !== null) {
                return $this->ensureFiles($existing->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']));
            }

            $sellerStatus = $this->settings->sellerConfigurationStatus();

            if (! $sellerStatus['is_ready']) {
                throw new RuntimeException('Uzupełnij dane sprzedawcy przed wystawieniem faktury. '.implode(' ', $sellerStatus['errors']));
            }

            $wz = $this->postedWz($order);

            if ($type === 'vat' && $wz === null) {
                throw new RuntimeException('Najpierw zaksięguj WZ dla tego zamówienia.');
            }

            if ($order->lines->isEmpty()) {
                throw new RuntimeException('Zamówienie nie ma pozycji do faktury.');
            }

            $linePayloads = $order->lines
                ->map(fn (ExternalOrderLine $line): array => $this->linePayload($line, $order))
                ->values();
            $template = $this->templates->defaultTemplate();

            $netTotal = round($linePayloads->sum('net_total'), 2);
            $vatTotal = round($linePayloads->sum('vat_total'), 2);
            $grossTotal = round($linePayloads->sum('gross_total'), 2);

            if ($grossTotal <= 0 && (float) $order->total_gross > 0) {
                $fallbackVatRate = $this->ossVatRates->rateForOrder($order) ?? 23.0;
                $grossTotal = (float) $order->total_gross;
                $netTotal = round($grossTotal / (1 + ($fallbackVatRate / 100)), 2);
                $vatTotal = round($grossTotal - $netTotal, 2);
            }

            $metadata = [
                'source' => 'external_order',
                'external_order_id' => $order->external_id,
                'external_order_number' => $order->external_number,
                'invoice_template_code' => $template->code,
                'legal_review_required' => true,
            ];

            if ($wz !== null) {
                $metadata['warehouse_document_id'] = $wz->id;
                $metadata['warehouse_document_number'] = $wz->number;
            }

            $ossMetadata = $this->ossVatRates->metadataForOrder($order);

            if ($ossMetadata !== null) {
                $metadata['oss'] = $ossMetadata;
            }

            $invoice = Invoice::query()->create([
                'number' => $this->numbers->next($this->numberType($type, $ossMetadata !== null)),
                'type' => $type,
                'status' => 'issued',
                'external_order_id' => $order->id,
                'invoice_template_id' => $template->id,
                'issue_date' => now()->toDateString(),
                'sale_date' => ($wz?->posted_at ?? $wz?->document_date ?? $order->external_created_at ?? now())->toDateString(),
                'payment_due_date' => $this->settings->paymentDueDate(),
                'currency' => $order->currency,
                'seller_data' => $this->settings->sellerData(),
                'buyer_data' => $this->buyerData($order),
                'net_total' => $netTotal,
                'vat_total' => $vatTotal,
                'gross_total' => $grossTotal,
                'payment_method' => $this->paymentMethod($order),
                'issued_at' => now(),
                'metadata' => $metadata,
            ]);

            foreach ($linePayloads as $payload) {
                $invoice->lines()->create($payload);
            }

            $invoice = $this->currencyConversion->apply($invoice->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']));
            $createdInvoiceId = (int) $invoice->id;

            return $this->ensureFiles($invoice->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']));
        });

        if ($createdInvoiceId !== null && $type !== 'proforma') {
            $this->ksefAutomation->queueAfterInvoiceIssued($invoice);
        }

        return $invoice->refresh()->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']);
    }

    private function numberType(string $type, bool $isOss): string
    {
        if ($type === 'proforma') {
            return 'PROFORMA';
        }

        return $isOss ? 'OSS' : 'FV';
    }

    private function postedWz(ExternalOrder $order): ?WarehouseDocument
    {
        $wz = $this->fulfillmentStatus->latestWz($order);

        return $wz?->status === 'posted' ? $wz : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(ExternalOrderLine $line, ExternalOrder $order): array
    {
        $quantity = max(0.0001, (float) $line->quantity);
        $raw = $line->raw_payload ?? [];
        $ossVatRate = $this->ossVatRates->isOssB2cOrder($order)
            ? $this->ossVatRates->rateForOrder($order)
            : null;
        $fallbackVatRate = (float) ($ossVatRate ?? $line->vat_rate ?? 23.0);
        $rawHasTax = array_key_exists('total_tax', $raw) && is_numeric($raw['total_tax']);
        $netTotal = $this->numberFromRaw($raw, 'total', $line->unit_net_price !== null ? (float) $line->unit_net_price * $quantity : 0);
        $vatTotal = $this->numberFromRaw($raw, 'total_tax', 0);
        $grossTotal = round($netTotal + $vatTotal, 2);

        if ($ossVatRate !== null && $netTotal > 0 && $this->shouldApplyOssVatRate($netTotal, $vatTotal, $ossVatRate)) {
            $vatTotal = round($netTotal * ($ossVatRate / 100), 2);
            $grossTotal = round($netTotal + $vatTotal, 2);
        } elseif (! $rawHasTax && $netTotal > 0) {
            if ($line->unit_gross_price !== null) {
                $grossFromLine = round((float) $line->unit_gross_price * $quantity, 2);

                if ($grossFromLine > $netTotal) {
                    $grossTotal = $grossFromLine;
                    $vatTotal = round($grossTotal - $netTotal, 2);
                } else {
                    $vatTotal = round($netTotal * ($fallbackVatRate / 100), 2);
                    $grossTotal = round($netTotal + $vatTotal, 2);
                }
            } elseif ($fallbackVatRate > 0) {
                $vatTotal = round($netTotal * ($fallbackVatRate / 100), 2);
                $grossTotal = round($netTotal + $vatTotal, 2);
            }
        }

        if ($grossTotal <= 0 && $line->unit_gross_price !== null) {
            $grossTotal = round((float) $line->unit_gross_price * $quantity, 2);
            $netTotal = round($grossTotal / (1 + ($fallbackVatRate / 100)), 2);
            $vatTotal = round($grossTotal - $netTotal, 2);
        }

        $vatRate = $netTotal > 0
            ? round(($vatTotal / $netTotal) * 100, 2)
            : $fallbackVatRate;

        return [
            'product_id' => $line->product_id,
            'name' => $line->name,
            'sku' => $line->sku,
            'unit' => 'szt',
            'quantity' => $quantity,
            'unit_net_price' => round($netTotal / $quantity, 4),
            'net_total' => $netTotal,
            'vat_rate' => $vatRate,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'metadata' => [
                'external_line_id' => $line->external_line_id,
            ],
        ];
    }

    private function shouldApplyOssVatRate(float $netTotal, float $vatTotal, float $ossVatRate): bool
    {
        if ($vatTotal <= 0) {
            return true;
        }

        $derivedRate = round(($vatTotal / $netTotal) * 100, 2);

        return abs($derivedRate - $ossVatRate) > 0.05;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function numberFromRaw(array $raw, string $key, float $fallback): float
    {
        $value = $raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerData(ExternalOrder $order): array
    {
        $billing = $order->billing_data ?? [];

        return [
            'name' => trim((string) ($billing['company'] ?? '')) !== ''
                ? (string) $billing['company']
                : trim((string) (($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''))),
            'tax_id' => $this->buyerTaxId($order),
            'email' => (string) ($billing['email'] ?? ''),
            'phone' => (string) ($billing['phone'] ?? ''),
            'address_1' => (string) ($billing['address_1'] ?? ''),
            'address_2' => (string) ($billing['address_2'] ?? ''),
            'postcode' => (string) ($billing['postcode'] ?? ''),
            'city' => (string) ($billing['city'] ?? ''),
            'country' => (string) ($billing['country'] ?? 'PL'),
        ];
    }

    private function buyerTaxId(ExternalOrder $order): string
    {
        $billing = $order->billing_data ?? [];

        foreach (['nip', 'vat_number', 'billing_nip', 'billing_vat_number', '_billing_nip', '_lemon_erp_billing_nip'] as $key) {
            if (! empty($billing[$key])) {
                return (string) $billing[$key];
            }
        }

        foreach (($order->raw_payload['meta_data'] ?? []) as $meta) {
            $key = (string) ($meta['key'] ?? '');
            if (in_array($key, ['nip', '_billing_nip', 'billing_nip', 'vat_number', '_billing_vat_number', '_lemon_erp_billing_nip'], true)) {
                return (string) ($meta['value'] ?? '');
            }
        }

        return '';
    }

    private function paymentMethod(ExternalOrder $order): ?string
    {
        return $order->raw_payload['payment_method_title'] ?? $order->raw_payload['payment_method'] ?? null;
    }

    private function storeHtmlFile(Invoice $invoice): void
    {
        $directory = storage_path('app/invoices');
        File::ensureDirectoryExists($directory);

        $relativePath = 'invoices/'.str_replace(['/', '\\'], '-', $invoice->number).'.html';
        $absolutePath = storage_path('app/'.$relativePath);
        $html = $this->templates->renderHtml($invoice);

        File::put($absolutePath, $html);

        $invoice->files()->create([
            'type' => 'html',
            'disk' => 'local',
            'path' => $relativePath,
            'mime_type' => 'text/html',
            'size' => File::size($absolutePath),
            'sha256' => hash_file('sha256', $absolutePath),
            'metadata' => [
                'generated_at' => now()->toISOString(),
                'template_id' => $invoice->invoice_template_id,
                'template_code' => $invoice->invoiceTemplate?->code,
            ],
        ]);
    }

    public function regenerateFiles(Invoice $invoice): Invoice
    {
        $invoice = Invoice::query()
            ->with(['lines', 'files', 'externalOrder', 'invoiceTemplate'])
            ->findOrFail($invoice->id);

        $invoice = $this->currencyConversion->apply($invoice);
        $this->templates->renderHtml($invoice);

        foreach ($invoice->files->whereIn('type', ['html', 'pdf']) as $file) {
            if ($file->disk === 'local') {
                File::delete(storage_path('app/'.$file->path));
            }

            $file->delete();
        }

        return $this->ensureFiles($invoice->refresh()->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']));
    }

    public function ensureFiles(Invoice $invoice): Invoice
    {
        $invoice = $this->currencyConversion->apply($invoice->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']));

        $this->pruneMissingLocalFiles($invoice, ['html', 'pdf']);

        if (! $invoice->relationLoaded('files')) {
            $invoice->load('files');
        }

        if (! $invoice->files->contains('type', 'html')) {
            $this->storeHtmlFile($invoice);
        }

        if (! $invoice->files->contains('type', 'pdf')) {
            $this->storePdfFile($invoice->load('lines'));
        }

        return $invoice->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']);
    }

    /**
     * @param  list<string>  $types
     */
    private function pruneMissingLocalFiles(Invoice $invoice, array $types): void
    {
        $deleted = false;

        foreach ($invoice->files->whereIn('type', $types) as $file) {
            if ($file->disk !== 'local') {
                continue;
            }

            $path = storage_path('app/'.$file->path);

            if (File::exists($path) && File::size($path) > 0) {
                continue;
            }

            $file->delete();
            $deleted = true;
        }

        if ($deleted) {
            $invoice->unsetRelation('files');
        }
    }

    public function previewPdf(Invoice $invoice): string
    {
        [$pdf, $rendererMetadata] = $this->renderPdf($invoice);
        $this->assertUsablePdf($pdf, (string) ($rendererMetadata['renderer'] ?? 'unknown'));

        return $pdf;
    }

    private function storePdfFile(Invoice $invoice): void
    {
        $relativePath = 'invoices/'.str_replace(['/', '\\'], '-', $invoice->number).'.pdf';
        $absolutePath = storage_path('app/'.$relativePath);
        [$pdf, $rendererMetadata] = $this->renderPdf($invoice);
        $this->assertUsablePdf($pdf, (string) ($rendererMetadata['renderer'] ?? 'unknown'));

        File::put($absolutePath, $pdf);

        $invoice->files()->create([
            'type' => 'pdf',
            'disk' => 'local',
            'path' => $relativePath,
            'mime_type' => 'application/pdf',
            'size' => File::size($absolutePath),
            'sha256' => hash_file('sha256', $absolutePath),
            'metadata' => array_merge([
                'generated_at' => now()->toISOString(),
                'template_id' => $invoice->invoice_template_id,
                'template_code' => $invoice->invoiceTemplate?->code,
            ], $rendererMetadata),
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function renderPdf(Invoice $invoice): array
    {
        $html = $this->templates->renderHtml($invoice);

        if (! class_exists(Dompdf::class)) {
            throw new RuntimeException('Nie można wygenerować faktury PDF: na serwerze brakuje biblioteki dompdf.');
        }

        try {
            return [
                $this->renderDompdfPdf($html),
                [
                    'renderer' => 'dompdf_html_pdf',
                    'unicode_text' => true,
                    'html_layout' => true,
                ],
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Nie można wygenerować faktury PDF zgodnej z szablonem HTML: '.mb_substr($exception->getMessage(), 0, 240),
                previous: $exception,
            );
        }
    }

    private function renderDompdfPdf(string $html): string
    {
        $tempDir = storage_path('app/dompdf');
        File::ensureDirectoryExists($tempDir);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('tempDir', $tempDir);
        $options->set('chroot', [
            base_path(),
            public_path(),
            storage_path(),
        ]);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        $output = $pdf->output();
        $this->assertUsablePdf($output, 'dompdf_html_pdf');

        return $output;
    }

    private function assertUsablePdf(string $pdf, string $renderer): void
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException("Generator PDF {$renderer} zwrócił plik bez nagłówka PDF.");
        }

        if (! str_contains(substr($pdf, -2048), '%%EOF')) {
            throw new RuntimeException("Generator PDF {$renderer} zwrócił niekompletny plik PDF.");
        }

        if (strlen($pdf) < 500) {
            throw new RuntimeException("Generator PDF {$renderer} zwrócił podejrzanie mały plik PDF.");
        }
    }

    /**
     * @param  array<int, string>  $rows
     */
    private function renderBasicPdf(array $rows): string
    {
        $content = ['BT', '/F1 10 Tf', '50 790 Td', '14 TL'];

        foreach ($rows as $row) {
            $content[] = '('.$this->pdfEscape($this->pdfSafeText($row)).') Tj';
            $content[] = 'T*';
        }

        $content[] = 'ET';
        $stream = implode("\n", $content);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param  array<int, string>  $rows
     */
    private function renderRasterPdf(array $rows, string $font): string
    {
        $width = 1190;
        $height = 1684;
        $margin = 72;
        $lineHeight = 25;
        $fontSize = 16;
        $titleSize = 28;

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 17, 22, 17);
        $muted = imagecolorallocate($image, 96, 108, 96);
        imagefill($image, 0, 0, $white);

        $y = $margin;
        $maxWidth = $width - ($margin * 2);

        foreach ($rows as $index => $row) {
            $size = $index === 0 ? $titleSize : $fontSize;
            $color = $index === 0 ? $black : $muted;
            $lineSpacing = $index === 0 ? 40 : $lineHeight;

            foreach ($this->wrapText($row, $font, $size, $maxWidth) as $wrapped) {
                if ($y > $height - $margin) {
                    break 2;
                }

                imagettftext($image, $size, 0, $margin, $y, $color, $font, $wrapped);
                $y += $lineSpacing;
            }

            if ($index === 0) {
                $y += 18;
            }
        }

        $pdf = $this->pdfWithRgbImage($image, $width, $height);
        imagedestroy($image);

        return $pdf;
    }

    private function unicodeFontPath(): ?string
    {
        $paths = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed.ttf',
            '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, string $font, int $fontSize, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = trim($line.' '.$word);

            if ($candidate === '') {
                continue;
            }

            if ($this->textWidth($candidate, $font, $fontSize) <= $maxWidth) {
                $line = $candidate;

                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
            }

            if ($this->textWidth($word, $font, $fontSize) <= $maxWidth) {
                $line = $word;

                continue;
            }

            $chunks = $this->splitLongWord($word, $font, $fontSize, $maxWidth);
            array_push($lines, ...array_slice($chunks, 0, -1));
            $line = end($chunks) ?: '';
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function textWidth(string $text, string $font, int $fontSize): int
    {
        $box = imagettfbbox($fontSize, 0, $font, $text);

        if ($box === false) {
            return mb_strlen($text) * $fontSize;
        }

        return abs($box[2] - $box[0]);
    }

    /**
     * @return array<int, string>
     */
    private function splitLongWord(string $word, string $font, int $fontSize, int $maxWidth): array
    {
        $chunks = [];
        $chunk = '';
        $length = mb_strlen($word);

        for ($i = 0; $i < $length; $i++) {
            $candidate = $chunk.mb_substr($word, $i, 1);

            if ($chunk !== '' && $this->textWidth($candidate, $font, $fontSize) > $maxWidth) {
                $chunks[] = $chunk;
                $chunk = mb_substr($word, $i, 1);

                continue;
            }

            $chunk = $candidate;
        }

        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        return $chunks !== [] ? $chunks : [$word];
    }

    private function pdfWithRgbImage(\GdImage $image, int $width, int $height): string
    {
        $raw = '';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $raw .= chr(($rgb >> 16) & 0xFF).chr(($rgb >> 8) & 0xFF).chr($rgb & 0xFF);
            }
        }

        $imageStream = gzcompress($raw, 9);
        $contentStream = "q\n595 0 0 842 0 0 cm\n/Im1 Do\nQ";

        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>',
            4 => "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length ".strlen($imageStream)." >>\nstream\n{$imageStream}\nendstream",
            5 => '<< /Length '.strlen($contentStream)." >>\nstream\n{$contentStream}\nendstream",
        ]);
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function buildPdf(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return array<int, string>
     */
    private function htmlToPdfRows(string $html): array
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/h[1-6]|\/li)\b[^>]*>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R+/', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => trim(preg_replace('/\s+/', ' ', $line) ?? ''))
            ->filter()
            ->take(52)
            ->values()
            ->all();
    }

    private function pdfSafeText(string $text): string
    {
        $text = strtr($text, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
        ]);

        return substr(preg_replace('/[^\x20-\x7E]/', '', $text) ?? '', 0, 180);
    }

    private function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
