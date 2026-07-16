<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class InvoiceEppExportService
{
    public function exportMonth(Carbon $month): string
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        return $this->exportRange($from, $to);
    }

    public function exportRange(Carbon $from, Carbon $to): string
    {
        $invoices = Invoice::query()
            ->with(['lines', 'externalOrder.salesChannel'])
            ->where('status', 'issued')
            ->where('type', '!=', 'proforma')
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();

        return $this->encode($this->render($invoices, $from, $to));
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function render(Collection $invoices, Carbon $from, Carbon $to): string
    {
        $lines = [
            '[INFO]',
            $this->row($this->fileHeader($invoices, $from, $to)),
        ];

        foreach ($invoices as $invoice) {
            $lines[] = '[NAGLOWEK]';
            $lines[] = $this->row($this->documentHeader($invoice));
            $lines[] = '[ZAWARTOSC]';

            foreach ($this->vatRows($invoice) as $row) {
                $lines[] = $this->row($row);
            }
        }

        if ($invoices->isNotEmpty()) {
            $lines = array_merge(
                $lines,
                $this->contractorSections($invoices),
                $this->completionDateSections($invoices),
                $this->correctionSections($invoices),
                $this->jpkMarkerSections($invoices),
                $this->ossSections($invoices),
            );
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * The EDI++ [INFO] record always consists of the 24 fields from table 2 of
     * the specification. Importers reject abbreviated, otherwise plausible
     * looking headers before attempting to read any document sections.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return list<mixed>
     */
    private function fileHeader(Collection $invoices, Carbon $from, Carbon $to): array
    {
        $seller = $this->party($invoices->first()?->seller_data ?? []);
        $country = $seller['country'] ?: 'PL';
        $name = $seller['name'] ?: 'Sempre ERP';
        $taxId = preg_replace('/[^A-Za-z0-9]+/', '', $seller['tax_id']) ?? '';

        return [
            '1.11',
            0, // communication intended for an accounting office
            1250,
            'Sempre ERP',
            mb_substr($taxId !== '' ? $taxId : 'SEMPRE', 0, 20),
            mb_substr($name, 0, 40),
            mb_substr($name, 0, 80),
            mb_substr($seller['city'], 0, 30),
            mb_substr($seller['postcode'], 0, 6),
            mb_substr(trim($seller['address_1'].' '.$seller['address_2']), 0, 50),
            mb_substr($taxId, 0, 13),
            '',
            '',
            'Eksport faktur '.$from->toDateString().' - '.$to->toDateString(),
            '',
            1,
            $this->dateTime($from),
            $this->dateTime($to),
            'Sempre ERP',
            $this->dateTime(now()),
            $this->countryName($country),
            $country,
            mb_substr($taxId, 0, 20),
            $this->isEuCountry($country) ? 1 : 0,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function documentHeader(Invoice $invoice): array
    {
        $buyer = $this->party($invoice->buyer_data ?? []);
        $seller = $this->party($invoice->seller_data ?? []);
        $type = $invoice->type === 'correction' ? 'KFS' : 'FS';
        $issueDate = $this->dateTime($invoice->issue_date ?? now());
        $saleDate = $this->dateTime($invoice->sale_date ?? $invoice->issue_date ?? now());
        $paymentDueDate = $this->dateTime($invoice->payment_due_date ?? $invoice->issue_date ?? now());
        $country = $buyer['country'] ?: 'PL';
        $correctedNumber = $invoice->type === 'correction'
            ? (string) data_get($invoice->metadata, 'corrected_invoice_number', '')
            : '';
        $correctedDate = $invoice->type === 'correction'
            && filled(data_get($invoice->metadata, 'corrected_invoice_issue_date'))
                ? $this->dateTime(data_get($invoice->metadata, 'corrected_invoice_issue_date'))
                : '';

        return [
            $type,
            1,
            0,
            (int) $invoice->id,
            '',
            '',
            $this->documentSymbol($invoice),
            $correctedNumber !== '' ? 'FS '.$correctedNumber : '',
            $correctedDate,
            mb_substr($this->orderReference($invoice), 0, 30),
            '',
            $this->contractorCode($invoice),
            mb_substr($buyer['name'], 0, 40),
            mb_substr($buyer['name'], 0, 255),
            mb_substr($buyer['city'], 0, 30),
            $buyer['postcode'],
            mb_substr(trim($buyer['address_1'].' '.$buyer['address_2']), 0, 50),
            mb_substr($buyer['tax_id'], 0, 20),
            $this->category($invoice),
            '',
            mb_substr($seller['city'], 0, 30),
            $issueDate,
            $saleDate,
            $issueDate,
            $invoice->lines->count(),
            1,
            'Detaliczna',
            $this->amount($invoice->net_total),
            $this->amount($invoice->vat_total),
            $this->amount($invoice->gross_total),
            $this->amount(0),
            '',
            $this->amount(0),
            mb_substr((string) ($invoice->payment_method ?: 'Przelew'), 0, 30),
            $paymentDueDate,
            $this->amount(0),
            $this->amount($invoice->gross_total),
            0,
            0,
            0,
            0,
            '',
            '',
            '',
            $this->amount(0),
            $this->amount(0),
            $invoice->currency ?: 'PLN',
            $this->amount($this->currencyRate($invoice)),
            $this->documentNotes($invoice),
            mb_substr($invoice->externalOrder?->salesChannel?->name ?? 'Sempre ERP', 0, 50),
            '',
            '',
            '',
            0,
            $this->transactionType($invoice),
            '',
            $this->amount(0),
            '',
            $this->amount(0),
            $this->countryName($country),
            $this->isEuCountry($country) ? $country : '',
            $this->isEuCountry($country) ? 1 : 0,
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function vatRows(Invoice $invoice): array
    {
        return $invoice->lines
            ->groupBy(fn (InvoiceLine $line): string => number_format((float) $line->vat_rate, 2, '.', ''))
            ->map(function (Collection $lines, string $rate): array {
                $net = $lines->sum(fn (InvoiceLine $line): float => (float) $line->net_total);
                $vat = $lines->sum(fn (InvoiceLine $line): float => (float) $line->vat_total);
                $gross = $lines->sum(fn (InvoiceLine $line): float => (float) $line->gross_total);

                return [
                    rtrim(rtrim($rate, '0'), '.'),
                    $this->amount((float) $rate),
                    $this->amount($net),
                    $this->amount($vat),
                    $this->amount($gross),
                    $this->amount($net),
                    $this->amount($vat),
                    $this->amount($gross),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                    $this->amount(0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<string>
     */
    private function contractorSections(Collection $invoices): array
    {
        $rows = [
            '[NAGLOWEK]',
            $this->row(['KONTRAHENCI']),
            '[ZAWARTOSC]',
        ];

        $invoices
            ->unique(fn (Invoice $invoice): string => $this->contractorCode($invoice))
            ->each(function (Invoice $invoice) use (&$rows): void {
                $buyer = $this->party($invoice->buyer_data ?? []);
                $country = $buyer['country'] ?: 'PL';

                $rows[] = $this->row([
                    filled($buyer['tax_id']) ? 2 : 4,
                    $this->contractorCode($invoice),
                    $buyer['name'],
                    $buyer['name'],
                    $buyer['city'],
                    $buyer['postcode'],
                    trim($buyer['address_1'].' '.$buyer['address_2']),
                    $buyer['tax_id'],
                    $buyer['regon'],
                    $buyer['phone'],
                    '',
                    '',
                    $buyer['email'],
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $this->countryName($country),
                    $this->isEuCountry($country) ? $country : '',
                    $this->isEuCountry($country) ? 1 : 0,
                    $country,
                ]);
            });

        return $rows;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<string>
     */
    private function completionDateSections(Collection $invoices): array
    {
        $rows = [
            '[NAGLOWEK]',
            $this->row(['DATYZAKONCZENIA']),
            '[ZAWARTOSC]',
        ];

        foreach ($invoices as $invoice) {
            $rows[] = $this->row([
                $this->documentSymbol($invoice),
                $this->dateTime($invoice->sale_date ?? $invoice->issue_date ?? now()),
            ]);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<string>
     */
    private function correctionSections(Collection $invoices): array
    {
        $corrections = $invoices->filter(fn (Invoice $invoice): bool => $invoice->type === 'correction');

        if ($corrections->isEmpty()) {
            return [];
        }

        $rows = [
            '[NAGLOWEK]',
            $this->row(['PRZYCZYNYKOREKT']),
            '[ZAWARTOSC]',
        ];

        foreach ($corrections as $invoice) {
            $rows[] = $this->row([
                $this->documentSymbol($invoice),
                1,
                mb_substr((string) data_get($invoice->metadata, 'correction_reason', 'Korekta faktury'), 0, 255),
            ]);
        }

        $rows[] = '[NAGLOWEK]';
        $rows[] = $this->row(['DATYUJECIAKOREKT']);
        $rows[] = '[ZAWARTOSC]';

        foreach ($corrections as $invoice) {
            $rows[] = $this->row([
                $this->documentSymbol($invoice),
                2,
                $this->dateTime($invoice->issue_date ?? now()),
            ]);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<string>
     */
    private function jpkMarkerSections(Collection $invoices): array
    {
        $rows = [
            '[NAGLOWEK]',
            $this->row(['DOKUMENTYZNACZNIKIJPKVAT']),
            '[ZAWARTOSC]',
        ];

        foreach ($invoices as $invoice) {
            $markers = array_fill(0, 30, 0);

            if (is_array(data_get($invoice->metadata, 'oss'))) {
                $markers[28] = 1; // WSTO_EE in the 1.11 layout
            }

            $rows[] = $this->row(array_merge([$this->documentSymbol($invoice)], $markers));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<string>
     */
    private function ossSections(Collection $invoices): array
    {
        $ossInvoices = $invoices->filter(fn (Invoice $invoice): bool => is_array(data_get($invoice->metadata, 'oss')));

        if ($ossInvoices->isEmpty()) {
            return [];
        }

        $rows = [
            '[NAGLOWEK]',
            $this->row(['INFORMACJEWSTO']),
            '[ZAWARTOSC]',
        ];

        foreach ($ossInvoices as $invoice) {
            $buyer = $this->party($invoice->buyer_data ?? []);
            $country = $buyer['country'] ?: (string) data_get($invoice->metadata, 'oss.buyer_country', 'PL');
            $rows[] = $this->row([
                $this->documentSymbol($invoice),
                'Polska',
                'PL',
                'PL',
                $this->countryName($country),
                $country,
                $country,
                '',
            ]);
        }

        $rows[] = '[NAGLOWEK]';
        $rows[] = $this->row(['SPECYFIKACJATOWAROWAWSTO']);
        $rows[] = '[ZAWARTOSC]';

        foreach ($ossInvoices as $invoice) {
            foreach ($invoice->lines as $line) {
                $rows[] = $this->row([
                    $this->documentSymbol($invoice),
                    mb_substr($line->name, 0, 50),
                    $this->amount($line->quantity),
                    mb_substr($line->unit, 0, 10),
                ]);
            }
        }

        $rows[] = '[NAGLOWEK]';
        $rows[] = $this->row(['STAWKIVATZAGRANICZNE']);
        $rows[] = '[ZAWARTOSC]';

        $ossInvoices
            ->flatMap(fn (Invoice $invoice): Collection => $invoice->lines->map(fn (InvoiceLine $line): array => [
                'rate' => (float) $line->vat_rate,
                'country' => $this->party($invoice->buyer_data ?? [])['country'] ?: (string) data_get($invoice->metadata, 'oss.buyer_country', 'PL'),
            ]))
            ->unique(fn (array $row): string => $row['country'].'|'.number_format($row['rate'], 2, '.', ''))
            ->each(function (array $row) use (&$rows): void {
                $country = (string) $row['country'];
                $rate = (float) $row['rate'];
                $rows[] = $this->row([
                    rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%',
                    $this->amount($rate),
                    0,
                    $this->countryName($country),
                    $country,
                    $country,
                ]);
            });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array{name:string,tax_id:string,regon:string,email:string,phone:string,address_1:string,address_2:string,postcode:string,city:string,country:string}
     */
    private function party(array $party): array
    {
        return [
            'name' => trim((string) ($party['name'] ?? '')),
            'tax_id' => trim((string) ($party['tax_id'] ?? '')),
            'regon' => trim((string) ($party['regon'] ?? '')),
            'email' => trim((string) ($party['email'] ?? '')),
            'phone' => trim((string) ($party['phone'] ?? '')),
            'address_1' => trim((string) ($party['address_1'] ?? '')),
            'address_2' => trim((string) ($party['address_2'] ?? '')),
            'postcode' => trim((string) ($party['postcode'] ?? '')),
            'city' => trim((string) ($party['city'] ?? '')),
            'country' => strtoupper(trim((string) ($party['country'] ?? 'PL'))) ?: 'PL',
        ];
    }

    private function contractorCode(Invoice $invoice): string
    {
        $buyer = $this->party($invoice->buyer_data ?? []);
        $taxId = preg_replace('/[^A-Za-z0-9]+/', '', $buyer['tax_id']) ?? '';

        if ($taxId !== '') {
            return mb_substr('NIP'.$taxId, 0, 20);
        }

        return mb_substr('OS'.$invoice->id, 0, 20);
    }

    private function documentSymbol(Invoice $invoice): string
    {
        return mb_substr(($invoice->type === 'correction' ? 'KFS ' : 'FS ').$invoice->number, 0, 30);
    }

    private function category(Invoice $invoice): string
    {
        if (is_array(data_get($invoice->metadata, 'oss'))) {
            $country = strtoupper((string) data_get($invoice->metadata, 'oss.buyer_country', data_get($invoice->buyer_data, 'country', 'UE')));

            return 'SPRZEDAZ_OSS_'.$country;
        }

        return filled(data_get($invoice->buyer_data, 'tax_id')) ? 'SPRZEDAZ_FIRMY' : 'SPRZEDAZ_DETAL';
    }

    private function orderReference(Invoice $invoice): string
    {
        return (string) (data_get($invoice->metadata, 'external_order_number') ?: $invoice->externalOrder?->external_number ?: '');
    }

    private function currencyRate(Invoice $invoice): float
    {
        if (strtoupper((string) $invoice->currency) === 'PLN') {
            return 1.0;
        }

        $rate = data_get($invoice->metadata, 'currency_conversion.rate');

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw ValidationException::withMessages([
                'month' => 'Faktura '.$invoice->number.' w walucie '.$invoice->currency.' nie ma zapisanego kursu waluty. Eksport EPP został przerwany, aby nie przekazać błędnych kwot do księgowości.',
            ]);
        }

        return (float) $rate;
    }

    private function documentNotes(Invoice $invoice): string
    {
        $notes = array_filter([
            filled($invoice->ksef_number) ? 'KSeF: '.$invoice->ksef_number : null,
            filled($this->orderReference($invoice)) ? 'Zamówienie: '.$this->orderReference($invoice) : null,
        ]);

        return mb_substr(implode('; ', $notes), 0, 255);
    }

    private function transactionType(Invoice $invoice): int
    {
        if (is_array(data_get($invoice->metadata, 'oss'))) {
            return 23;
        }

        $buyer = $this->party($invoice->buyer_data ?? []);

        if ($buyer['country'] === 'PL') {
            return 0;
        }

        return $this->isEuCountry($buyer['country']) ? 2 : 1;
    }

    private function isEuCountry(string $country): bool
    {
        return in_array(strtoupper($country), [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'GR',
            'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE',
            'SI', 'SK',
        ], true);
    }

    private function dominantVatRate(Invoice $invoice): float
    {
        $line = $invoice->lines
            ->sortByDesc(fn (InvoiceLine $line): float => abs((float) $line->gross_total))
            ->first();

        return $line instanceof InvoiceLine ? (float) $line->vat_rate : 0.0;
    }

    private function dateTime(mixed $date): string
    {
        return Carbon::parse($date)->format('Ymd').'000000';
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    /**
     * @param  list<mixed>  $fields
     */
    private function row(array $fields): string
    {
        return implode(',', array_map(fn (mixed $field): string => $this->field($field), $fields));
    }

    private function field(mixed $field): string
    {
        if ($field === null) {
            return '';
        }

        if (is_int($field)) {
            return (string) $field;
        }

        if (is_float($field)) {
            return $this->amount($field);
        }

        $value = (string) $field;

        if ($value === '') {
            return '';
        }

        if (preg_match('/^-?\d+\.\d{4}$/', $value) === 1 || preg_match('/^-?\d+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    private function encode(string $content): string
    {
        $encoded = iconv('UTF-8', 'WINDOWS-1250//TRANSLIT', $content);

        if ($encoded === false) {
            return $content;
        }

        return $encoded;
    }

    private function countryName(string $country): string
    {
        return [
            'AT' => 'Austria',
            'BE' => 'Belgia',
            'BG' => 'Bułgaria',
            'CY' => 'Cypr',
            'CZ' => 'Czechy',
            'DE' => 'Niemcy',
            'DK' => 'Dania',
            'EE' => 'Estonia',
            'EL' => 'Grecja',
            'ES' => 'Hiszpania',
            'FI' => 'Finlandia',
            'FR' => 'Francja',
            'HR' => 'Chorwacja',
            'HU' => 'Węgry',
            'IE' => 'Irlandia',
            'IT' => 'Włochy',
            'LT' => 'Litwa',
            'LU' => 'Luksemburg',
            'LV' => 'Łotwa',
            'MT' => 'Malta',
            'NL' => 'Holandia',
            'PL' => 'Polska',
            'PT' => 'Portugalia',
            'RO' => 'Rumunia',
            'SE' => 'Szwecja',
            'SI' => 'Słowenia',
            'SK' => 'Słowacja',
        ][strtoupper($country)] ?? strtoupper($country);
    }
}
