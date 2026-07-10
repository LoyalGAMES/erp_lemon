<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class InvoiceEppExportService
{
    public function exportMonth(Carbon $month): string
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $invoices = Invoice::query()
            ->with(['lines', 'externalOrder.salesChannel'])
            ->where('status', 'issued')
            ->where('type', '!=', 'proforma')
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();

        return $this->encode($this->render($invoices, $month));
    }

    /**
     * @param Collection<int, Invoice> $invoices
     */
    private function render(Collection $invoices, Carbon $month): string
    {
        $lines = [
            '[INFO]',
            $this->row(['1.12', 3, 1250, 'Sempre ERP', 'EDI', 'Eksport faktur '.$month->format('Y-m')]),
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
                $this->jpkMarkerSections($invoices),
                $this->ossSections($invoices),
            );
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @return list<mixed>
     */
    private function documentHeader(Invoice $invoice): array
    {
        $buyer = $this->party($invoice->buyer_data ?? []);
        $type = $invoice->type === 'correction' ? 'KFS' : 'FS';
        $issueDate = $this->dateTime($invoice->issue_date ?? now());
        $saleDate = $this->dateTime($invoice->sale_date ?? $invoice->issue_date ?? now());
        $paymentDueDate = $this->dateTime($invoice->payment_due_date ?? $invoice->issue_date ?? now());
        $country = $buyer['country'] ?: 'PL';

        return [
            $type,
            (int) $invoice->id,
            0,
            (int) $invoice->id,
            '',
            '',
            $type.' '.$invoice->number,
            '',
            $issueDate,
            $this->contractorCode($invoice),
            '',
            $this->contractorCode($invoice),
            $buyer['name'],
            $buyer['tax_id'],
            $buyer['postcode'],
            $buyer['city'],
            $buyer['address_1'],
            $buyer['address_2'],
            $this->category($invoice),
            '',
            'Eksport Sempre ERP '.$invoice->number,
            $issueDate,
            $saleDate,
            $paymentDueDate,
            2,
            0,
            'Detaliczna',
            $this->amount($invoice->net_total),
            $this->amount($invoice->vat_total),
            $this->amount($invoice->gross_total),
            $this->amount($invoice->gross_total),
            '',
            $this->amount(0),
            '',
            $issueDate,
            $this->amount($invoice->gross_total),
            $this->amount($invoice->gross_total),
            0,
            0,
            1,
            0,
            $this->orderReference($invoice),
            '',
            '',
            $this->amount(0),
            $this->amount(0),
            $invoice->currency ?: 'PLN',
            $this->amount(1),
            $invoice->ksef_number ?? '',
            '',
            '',
            '',
            0,
            0,
            (int) round($this->dominantVatRate($invoice)),
            $invoice->externalOrder?->salesChannel?->name ?? 'Sempre ERP',
            $this->amount($invoice->gross_total),
            $invoice->payment_method ?: 'Przelew',
            $this->amount(0),
            $this->countryName($country),
            $country,
            $buyer['tax_id'],
            $invoice->ksef_number ?? '',
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
     * @param Collection<int, Invoice> $invoices
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
                    $buyer['email'],
                    $buyer['phone'],
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
                    '',
                    '',
                    $this->countryName($country),
                    '',
                    filled($buyer['tax_id']) ? 1 : 0,
                    $country,
                ]);
            });

        return $rows;
    }

    /**
     * @param Collection<int, Invoice> $invoices
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
     * @param Collection<int, Invoice> $invoices
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
            $rows[] = $this->row(array_merge([$this->documentSymbol($invoice)], array_fill(0, 29, 0)));
        }

        return $rows;
    }

    /**
     * @param Collection<int, Invoice> $invoices
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
     * @param array<string, mixed> $party
     * @return array{name:string,tax_id:string,email:string,phone:string,address_1:string,address_2:string,postcode:string,city:string,country:string}
     */
    private function party(array $party): array
    {
        return [
            'name' => trim((string) ($party['name'] ?? '')),
            'tax_id' => trim((string) ($party['tax_id'] ?? '')),
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
            return mb_substr('NIP'.$taxId, 0, 40);
        }

        return mb_substr('OS'.$invoice->id, 0, 40);
    }

    private function documentSymbol(Invoice $invoice): string
    {
        return ($invoice->type === 'correction' ? 'KFS ' : 'FS ').$invoice->number;
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
     * @param list<mixed> $fields
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
