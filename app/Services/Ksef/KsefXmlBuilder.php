<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class KsefXmlBuilder
{
    public const FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    public const FORM_SYSTEM_CODE = 'FA (3)';

    public const SCHEMA_VERSION = '1-0E';

    public function build(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'externalOrder']);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::FA3_NAMESPACE, 'Faktura');
        $dom->appendChild($root);

        $this->addHeader($dom, $root);
        $this->addSubject($dom, $root, 'Podmiot1', $invoice->seller_data ?? [], 'Sprzedawca');
        $this->addSubject($dom, $root, 'Podmiot2', $invoice->buyer_data ?? [], 'Nabywca');
        $this->addInvoice($dom, $root, $invoice);

        return (string) $dom->saveXML();
    }

    private function addHeader(DOMDocument $dom, DOMElement $root): void
    {
        $header = $this->element($dom, $root, 'Naglowek');
        $form = $this->text($dom, $header, 'KodFormularza', 'FA');
        $form->setAttribute('kodSystemowy', self::FORM_SYSTEM_CODE);
        $form->setAttribute('wersjaSchemy', self::SCHEMA_VERSION);

        $this->text($dom, $header, 'WariantFormularza', '3');
        $this->text($dom, $header, 'DataWytworzeniaFa', now()->toISOString());
        $this->text($dom, $header, 'SystemInfo', 'Sempre ERP');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addSubject(DOMDocument $dom, DOMElement $root, string $nodeName, array $data, string $fallbackName): void
    {
        $subject = $this->element($dom, $root, $nodeName);
        $identity = $this->element($dom, $subject, 'DaneIdentyfikacyjne');

        $taxId = $this->digits((string) ($data['tax_id'] ?? ''));
        if ($taxId !== '') {
            $this->text($dom, $identity, 'NIP', $taxId);
        } else {
            $this->text($dom, $identity, 'BrakID', '1');
        }

        $this->text($dom, $identity, 'Nazwa', $this->value($data['name'] ?? null, $fallbackName));

        $address = $this->element($dom, $subject, 'Adres');
        $this->text($dom, $address, 'KodKraju', strtoupper((string) ($data['country'] ?? 'PL')) ?: 'PL');
        $this->text($dom, $address, 'AdresL1', $this->addressLine($data));

        if ($nodeName === 'Podmiot2') {
            $this->text($dom, $subject, 'JST', '2');
            $this->text($dom, $subject, 'GV', '2');
        }
    }

    private function addInvoice(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $fa = $this->element($dom, $root, 'Fa');

        $this->text($dom, $fa, 'KodWaluty', $invoice->currency);
        $this->text($dom, $fa, 'P_1', $invoice->issue_date?->toDateString() ?? now()->toDateString());
        $this->text($dom, $fa, 'P_2', $invoice->number);
        $this->text($dom, $fa, 'P_6', $invoice->sale_date?->toDateString() ?? $invoice->issue_date?->toDateString() ?? now()->toDateString());

        $this->addVatSummary($dom, $fa, $invoice);
        $this->text($dom, $fa, 'P_15', $this->money((float) $invoice->gross_total));

        $this->addAnnotations($dom, $fa);

        $invoiceType = $this->invoiceType($invoice);
        $this->text($dom, $fa, 'RodzajFaktury', $invoiceType);

        if ($invoiceType === 'KOR') {
            $this->addCorrectionData($dom, $fa, $invoice);
        }

        foreach ($invoice->lines->values() as $index => $line) {
            $this->addLine($dom, $fa, $line, $index + 1);
        }
    }

    private function addAnnotations(DOMDocument $dom, DOMElement $fa): void
    {
        $annotations = $this->element($dom, $fa, 'Adnotacje');
        $this->text($dom, $annotations, 'P_16', '2');
        $this->text($dom, $annotations, 'P_17', '2');
        $this->text($dom, $annotations, 'P_18', '2');
        $this->text($dom, $annotations, 'P_18A', '2');

        $exemption = $this->element($dom, $annotations, 'Zwolnienie');
        $this->text($dom, $exemption, 'P_19N', '1');

        $newMeansOfTransport = $this->element($dom, $annotations, 'NoweSrodkiTransportu');
        $this->text($dom, $newMeansOfTransport, 'P_22N', '1');

        $this->text($dom, $annotations, 'P_23', '2');

        $marginScheme = $this->element($dom, $annotations, 'PMarzy');
        $this->text($dom, $marginScheme, 'P_PMarzyN', '1');
    }

    private function addLine(DOMDocument $dom, DOMElement $fa, InvoiceLine $line, int $number): void
    {
        $row = $this->element($dom, $fa, 'FaWiersz');

        $this->text($dom, $row, 'NrWierszaFa', (string) $number);
        $this->text($dom, $row, 'P_7', $line->name);
        $this->text($dom, $row, 'P_8A', $line->unit);
        $this->text($dom, $row, 'P_8B', $this->quantity((float) $line->quantity));
        $this->text($dom, $row, 'P_9A', $this->money((float) $line->unit_net_price));
        $this->text($dom, $row, 'P_11', $this->money((float) $line->net_total));
        $this->text($dom, $row, 'P_12', $this->vatRate((float) $line->vat_rate));
    }

    private function invoiceType(Invoice $invoice): string
    {
        return (string) $invoice->type === 'correction' ? 'KOR' : 'VAT';
    }

    private function addCorrectionData(DOMDocument $dom, DOMElement $fa, Invoice $invoice): void
    {
        $this->text($dom, $fa, 'PrzyczynaKorekty', $this->value(
            data_get($invoice->metadata, 'correction_reason'),
            'Korekta faktury',
        ));
        $this->text($dom, $fa, 'TypKorekty', '3');

        $correctedInvoice = $this->element($dom, $fa, 'DaneFaKorygowanej');
        $this->text($dom, $correctedInvoice, 'DataWystFaKorygowanej', $this->correctedInvoiceIssueDate($invoice));
        $this->text($dom, $correctedInvoice, 'NrFaKorygowanej', $this->value(
            data_get($invoice->metadata, 'corrected_invoice_number'),
            'Brak numeru faktury pierwotnej',
        ));
    }

    private function correctedInvoiceIssueDate(Invoice $invoice): string
    {
        $date = data_get($invoice->metadata, 'corrected_invoice_issue_date')
            ?: data_get($invoice->metadata, 'original_invoice_issue_date');

        $date = trim((string) $date);

        if ($date !== '') {
            return substr($date, 0, 10);
        }

        return $invoice->sale_date?->toDateString()
            ?? $invoice->issue_date?->toDateString()
            ?? now()->toDateString();
    }

    private function addVatSummary(DOMDocument $dom, DOMElement $fa, Invoice $invoice): void
    {
        $summary = $this->summaryByVatBucket($invoice);

        foreach ([
            'standard' => ['P_13_1', 'P_14_1'],
            'reduced_first' => ['P_13_2', 'P_14_2'],
            'reduced_second' => ['P_13_3', 'P_14_3'],
        ] as $bucket => [$netField, $vatField]) {
            if (! isset($summary[$bucket])) {
                continue;
            }

            $this->text($dom, $fa, $netField, $this->money($summary[$bucket]['net']));
            $this->text($dom, $fa, $vatField, $this->money($summary[$bucket]['vat_pln']));
        }

        if (isset($summary['zero_domestic'])) {
            $this->text($dom, $fa, 'P_13_6_1', $this->money($summary['zero_domestic']['net']));
        }
    }

    /**
     * @return array<string, array{net: float, vat: float, vat_pln: float}>
     */
    private function summaryByVatBucket(Invoice $invoice): array
    {
        $summary = [];

        foreach ($invoice->lines as $line) {
            $bucket = $this->vatBucket((float) $line->vat_rate);
            $summary[$bucket] ??= ['net' => 0.0, 'vat' => 0.0, 'vat_pln' => 0.0];
            $summary[$bucket]['net'] = round($summary[$bucket]['net'] + (float) $line->net_total, 2);
            $summary[$bucket]['vat'] = round($summary[$bucket]['vat'] + (float) $line->vat_total, 2);
            $summary[$bucket]['vat_pln'] = round($summary[$bucket]['vat_pln'] + $this->vatAmountForXml($invoice, $line), 2);
        }

        return $summary;
    }

    private function vatAmountForXml(Invoice $invoice, InvoiceLine $line): float
    {
        if (strtoupper((string) $invoice->currency) === 'PLN') {
            return (float) $line->vat_total;
        }

        $rate = data_get($invoice->metadata, 'currency_conversion.rate');

        if (! is_numeric($rate)) {
            return (float) $line->vat_total;
        }

        return round((float) $line->vat_total * (float) $rate, 2);
    }

    private function vatBucket(float $rate): string
    {
        $normalized = round($rate, 2);

        return match (true) {
            in_array($normalized, [23.0, 22.0], true) => 'standard',
            in_array($normalized, [8.0, 7.0], true) => 'reduced_first',
            $normalized === 5.0 => 'reduced_second',
            $normalized === 0.0 => 'zero_domestic',
            default => throw new RuntimeException(sprintf(
                'Stawka VAT %s%% nie ma jeszcze mapowania do pól podsumowania KSeF FA(3).',
                $this->vatRate($normalized),
            )),
        };
    }

    /**
     * @return list<float>
     */
    public function unsupportedVatRates(Invoice $invoice): array
    {
        $invoice->loadMissing('lines');
        $unsupported = [];

        foreach ($invoice->lines as $line) {
            try {
                $this->vatBucket((float) $line->vat_rate);
            } catch (RuntimeException) {
                $unsupported[] = (float) $line->vat_rate;
            }
        }

        return array_values(array_unique($unsupported));
    }

    private function element(DOMDocument $dom, DOMElement $parent, string $name): DOMElement
    {
        $element = $dom->createElementNS(self::FA3_NAMESPACE, $name);
        $parent->appendChild($element);

        return $element;
    }

    private function text(DOMDocument $dom, DOMElement $parent, string $name, mixed $value): DOMElement
    {
        $element = $this->element($dom, $parent, $name);
        $element->appendChild($dom->createTextNode((string) $value));

        return $element;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addressLine(array $data): string
    {
        $parts = array_filter([
            $data['address_1'] ?? null,
            $data['address_2'] ?? null,
            trim((string) ($data['postcode'] ?? '').' '.(string) ($data['city'] ?? '')),
        ]);

        return $this->value(implode(', ', $parts), 'Brak adresu');
    }

    private function value(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function quantity(float $value): string
    {
        return rtrim(rtrim(number_format(round($value, 4), 4, '.', ''), '0'), '.') ?: '0';
    }

    private function vatRate(float $value): string
    {
        return rtrim(rtrim(number_format(round($value, 2), 2, '.', ''), '0'), '.');
    }
}
