<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\Invoice;
use App\Models\KsefSubmission;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Throwable;

final class KsefQrCodeService
{
    /**
     * @return array{url:string,label:string,image_data_uri:string,hash:string}|null
     */
    public function invoiceQr(Invoice $invoice): ?array
    {
        $ksefNumber = trim((string) ($invoice->ksef_number ?: data_get($invoice->metadata, 'ksef.number', '')));
        $url = trim((string) data_get($invoice->metadata, 'ksef.qr_url', ''));
        $hash = trim((string) data_get($invoice->metadata, 'ksef.invoice_hash_sha256_base64url', ''));
        $submission = null;
        $xml = '';

        if ($ksefNumber === '' && $url === '' && ! $invoice->relationLoaded('ksefSubmissions')) {
            return null;
        }

        if ($ksefNumber === '' || $url === '') {
            $submission = $this->acceptedSubmission($invoice);
            $ksefNumber = trim((string) ($ksefNumber ?: $submission?->ksef_number));
            $xml = (string) ($submission?->xml_payload ?? '');
        }

        if ($ksefNumber === '') {
            return null;
        }

        if ($url === '' && trim($xml) !== '') {
            $url = $this->invoiceVerificationUrl($invoice, $xml, $submission?->environment) ?? '';
            $hash = $this->invoiceHash($xml);
        }

        if ($url === '') {
            return null;
        }

        $svg = $this->qrSvg($url);

        if ($svg === null) {
            return null;
        }

        return [
            'url' => $url,
            'label' => $ksefNumber,
            'image_data_uri' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'hash' => $hash,
        ];
    }

    public function invoiceVerificationUrl(Invoice $invoice, string $xml, ?string $environment = null): ?string
    {
        $sellerTaxId = preg_replace('/\D+/', '', (string) data_get($invoice->seller_data, 'tax_id', ''));

        if ($sellerTaxId === '' || $invoice->issue_date === null || trim($xml) === '') {
            return null;
        }

        $issueDate = Carbon::parse($invoice->issue_date)->format('d-m-Y');
        $hash = $this->invoiceHash($xml);

        return sprintf('%s/invoice/%s/%s/%s', $this->qrBaseUrl($environment), $sellerTaxId, $issueDate, $hash);
    }

    public function invoiceHash(string $xml): string
    {
        return $this->base64Url(hash('sha256', $xml, true));
    }

    private function acceptedSubmission(Invoice $invoice): ?KsefSubmission
    {
        $invoice->loadMissing('ksefSubmissions');

        return $invoice->ksefSubmissions
            ->sortByDesc('id')
            ->first(fn (KsefSubmission $submission): bool => $submission->status === 'accepted' && filled($submission->ksef_number));
    }

    private function qrBaseUrl(?string $environment): string
    {
        return match (strtolower((string) $environment)) {
            'production', 'prod' => 'https://qr.ksef.mf.gov.pl',
            'demo' => 'https://qr-demo.ksef.mf.gov.pl',
            default => 'https://qr-test.ksef.mf.gov.pl',
        };
    }

    private function qrSvg(string $url): ?string
    {
        try {
            $renderer = new ImageRenderer(
                new RendererStyle(size: 96, margin: 1),
                new SvgImageBackEnd,
            );

            return (new Writer($renderer))->writeString($url);
        } catch (Throwable) {
            return null;
        }
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
