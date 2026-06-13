<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class InvoiceCurrencyConversionService
{
    /**
     * @return array<string, mixed>
     */
    public function buildMetadata(Invoice $invoice, bool $force = false): array
    {
        $invoice->loadMissing('lines');

        $metadata = $invoice->metadata ?? [];
        $currency = strtoupper((string) $invoice->currency);

        if ($currency === 'PLN') {
            unset($metadata['currency_conversion']);

            return $metadata;
        }

        $basisDate = $this->basisDate($invoice);
        $existing = (array) data_get($metadata, 'currency_conversion', []);

        if (
            ! $force
            && ($existing['currency'] ?? null) === $currency
            && ($existing['basis_date'] ?? null) === $basisDate
            && isset($existing['rate'])
        ) {
            data_set($metadata, 'currency_conversion', $this->withVatPln($invoice, $existing));

            return $metadata;
        }

        if (abs((float) $invoice->vat_total) < 0.005) {
            data_set($metadata, 'currency_conversion', $this->withVatPln($invoice, [
                'source' => 'not_required',
                'currency' => $currency,
                'basis_date' => $basisDate,
                'rate' => null,
                'rate_date' => null,
                'table_no' => null,
                'note' => 'Kwota VAT wynosi 0,00, więc przeliczenie VAT na PLN nie jest wymagane.',
                'updated_at' => now()->toISOString(),
            ]));

            return $metadata;
        }

        try {
            $rate = $this->nbpRate($currency, $basisDate);
        } catch (Throwable $exception) {
            data_set($metadata, 'currency_conversion', [
                'source' => 'nbp',
                'currency' => $currency,
                'basis_date' => $basisDate,
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 240),
                'updated_at' => now()->toISOString(),
            ]);

            return $metadata;
        }

        data_set($metadata, 'currency_conversion', $this->withVatPln($invoice, [
            'source' => 'nbp',
            'currency' => $currency,
            'basis_date' => $basisDate,
            'rate' => $rate['rate'],
            'rate_date' => $rate['effective_date'],
            'table_no' => $rate['table_no'],
            'status' => 'ready',
            'updated_at' => now()->toISOString(),
        ]));

        return $metadata;
    }

    public function apply(Invoice $invoice, bool $force = false): Invoice
    {
        $metadata = $this->buildMetadata($invoice, $force);
        $invoice->forceFill(['metadata' => $metadata])->save();

        return $invoice->refresh()->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withVatPln(Invoice $invoice, array $payload): array
    {
        $rate = isset($payload['rate']) && is_numeric($payload['rate']) ? (float) $payload['rate'] : null;
        $vatSummary = [];

        foreach ($invoice->lines as $line) {
            $key = $this->vatRateKey((float) $line->vat_rate);
            $vatSummary[$key] ??= 0.0;
            $vatSummary[$key] = round($vatSummary[$key] + (float) $line->vat_total, 2);
        }

        $vatSummaryPln = [];

        foreach ($vatSummary as $key => $amount) {
            $vatSummaryPln[$key] = $rate !== null ? round($amount * $rate, 2) : 0.0;
        }

        $payload['vat_total_pln'] = $rate !== null ? round((float) $invoice->vat_total * $rate, 2) : 0.0;
        $payload['vat_summary_pln'] = $vatSummaryPln;

        return $payload;
    }

    private function basisDate(Invoice $invoice): string
    {
        return ($invoice->sale_date ?? $invoice->issue_date ?? now())->toDateString();
    }

    /**
     * @return array{rate: float, effective_date: string, table_no: string}
     */
    private function nbpRate(string $currency, string $basisDate): array
    {
        $basis = CarbonImmutable::parse($basisDate);
        $end = $basis->subDay();
        $start = $end->subDays(10);
        $url = sprintf(
            'https://api.nbp.pl/api/exchangerates/rates/a/%s/%s/%s/',
            strtolower($currency),
            $start->toDateString(),
            $end->toDateString(),
        );

        $response = Http::acceptJson()
            ->timeout(8)
            ->get($url, ['format' => 'json']);

        if (! $response->successful()) {
            throw new RuntimeException("NBP nie zwrócił kursu {$currency} dla daty bazowej {$basisDate}.");
        }

        $rates = collect($response->json('rates') ?? [])
            ->filter(fn (array $rate): bool => isset($rate['mid'], $rate['effectiveDate']))
            ->sortByDesc('effectiveDate')
            ->values();

        $rate = $rates->first();

        if (! is_array($rate) || ! isset($rate['mid'], $rate['effectiveDate'])) {
            throw new RuntimeException("Brak tabeli NBP dla waluty {$currency} przed datą {$basisDate}.");
        }

        return [
            'rate' => round((float) $rate['mid'], 6),
            'effective_date' => (string) $rate['effectiveDate'],
            'table_no' => (string) ($rate['no'] ?? ''),
        ];
    }

    private function vatRateKey(float $rate): string
    {
        return number_format(round($rate, 2), 2, '.', '');
    }
}
