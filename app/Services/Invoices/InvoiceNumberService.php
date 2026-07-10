<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;

final class InvoiceNumberService
{
    public function __construct(
        private readonly InvoiceSettingsService $settings,
    ) {
    }

    public function next(string $type = 'FV'): string
    {
        $numbering = $this->settings->numberingData();
        $series = $this->seriesForType($type, $numbering);
        $prefix = $series['prefix'];
        $date = now();
        $placeholder = '__SEQ__';
        $template = $this->renderNumber($prefix, 0, $date, [
            'pattern' => str_replace('{SEQ}', $placeholder, $series['pattern']),
            'padding' => $series['padding'],
        ]);
        [$numberPrefix, $numberSuffix] = array_pad(explode($placeholder, $template, 2), 2, '');

        $numbers = Invoice::query()
            ->where('number', 'like', $numberPrefix.'%')
            ->lockForUpdate()
            ->pluck('number');
        $sequence = ((int) $numbers
            ->map(fn (string $number): ?int => $this->sequenceFromNumber($number, $numberPrefix, $numberSuffix))
            ->filter()
            ->max()) + 1;

        do {
            $number = $this->renderNumber($prefix, $sequence, $date, $series);
            $sequence += 1;
        } while (Invoice::query()->where('number', $number)->exists());

        return $number;
    }

    private function sequenceFromNumber(string $number, string $prefix, string $suffix): ?int
    {
        if (! str_starts_with($number, $prefix)) {
            return null;
        }

        if ($suffix !== '' && ! str_ends_with($number, $suffix)) {
            return null;
        }

        $sequence = substr($number, strlen($prefix), strlen($number) - strlen($prefix) - strlen($suffix));

        return ctype_digit($sequence) ? (int) $sequence : null;
    }

    /**
     * @return array{prefix:string,pattern:string,padding:int}
     */
    private function seriesForType(string $type, array $numbering): array
    {
        $normalized = strtoupper($type);

        $prefix = match ($normalized) {
            'FV', 'VAT', 'SALES', 'FV_B2C', 'VAT_B2C', 'SALES_B2C', 'B2C', 'CONSUMER' => $numbering['b2c_sales_prefix'],
            'FV_B2B', 'VAT_B2B', 'SALES_B2B', 'B2B', 'COMPANY' => $numbering['b2b_sales_prefix'],
            'FK', 'CORRECTION' => $numbering['correction_prefix'],
            'PRO', 'PROFORMA' => $numbering['proforma_prefix'],
            'OSS', 'VAT_OSS', 'SALES_OSS', 'FV_OSS' => $numbering['oss_sales_prefix'],
            'FVK_OSS', 'FK_OSS', 'CORRECTION_OSS' => $numbering['oss_correction_prefix'],
            default => $type,
        };

        return [
            'prefix' => $prefix,
            'pattern' => in_array($normalized, ['OSS', 'VAT_OSS', 'SALES_OSS', 'FV_OSS', 'FVK_OSS', 'FK_OSS', 'CORRECTION_OSS'], true)
                ? $numbering['oss_pattern']
                : $numbering['pattern'],
            'padding' => in_array($normalized, ['OSS', 'VAT_OSS', 'SALES_OSS', 'FV_OSS', 'FVK_OSS', 'FK_OSS', 'CORRECTION_OSS'], true)
                ? $numbering['oss_padding']
                : $numbering['padding'],
        ];
    }

    /**
     * @param array{pattern:string,padding:int} $numbering
     */
    private function renderNumber(string $prefix, int $sequence, \DateTimeInterface $date, array $numbering): string
    {
        $sequenceValue = str_pad((string) $sequence, $numbering['padding'], '0', STR_PAD_LEFT);

        return strtr($numbering['pattern'], [
            '{PREFIX}' => trim($prefix, '/'),
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{SEQ}' => $sequenceValue,
        ]);
    }
}
