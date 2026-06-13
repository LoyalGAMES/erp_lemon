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
        $prefix = $this->prefixForType($type, $numbering);
        $date = now();
        $placeholder = '__SEQ__';
        $template = $this->renderNumber($prefix, 0, $date, [
            'pattern' => str_replace('{SEQ}', $placeholder, $numbering['pattern']),
            'padding' => $numbering['padding'],
        ]);
        $numberPrefix = explode($placeholder, $template)[0] ?? '';

        $last = Invoice::query()
            ->where('number', 'like', $numberPrefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('number');

        $sequence = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        do {
            $number = $this->renderNumber($prefix, $sequence, $date, $numbering);
            $sequence += 1;
        } while (Invoice::query()->where('number', $number)->exists());

        return $number;
    }

    /**
     * @param array{sales_prefix: string, correction_prefix: string, pattern: string, padding: int, payment_due_days: int} $numbering
     */
    private function prefixForType(string $type, array $numbering): string
    {
        return match (strtoupper($type)) {
            'FV', 'VAT', 'SALES' => $numbering['sales_prefix'],
            'FK', 'CORRECTION' => $numbering['correction_prefix'],
            default => $type,
        };
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
