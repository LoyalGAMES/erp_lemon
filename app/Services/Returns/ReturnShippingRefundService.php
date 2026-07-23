<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use RuntimeException;

final class ReturnShippingRefundService
{
    private const DECISION_METADATA_KEY = 'shipping_refund_decision';

    public function __construct(
        private readonly ReturnSettingsService $settings,
    ) {}

    /**
     * Calculates the shipping refund from current order data and configuration.
     * This method does not persist the result; use snapshot() at approval time.
     *
     * @return array{
     *     configured_gross_amount:float,
     *     configured_currency:string,
     *     configured_gross_amount_in_refund_currency:float,
     *     refund_currency:string,
     *     conversion_rate:?float,
     *     original_shipping_gross_amount:float,
     *     gross_amount:float,
     *     net_amount:float,
     *     vat_amount:float,
     *     vat_rate:float,
     *     original_line:InvoiceLine
     * }|null
     */
    public function details(ReturnCase $returnCase, ?Invoice $originalInvoice = null): ?array
    {
        if ($this->hasDecision($returnCase)) {
            return $this->detailsFromDecision($returnCase, $originalInvoice);
        }

        return $this->calculateDetails($returnCase, $originalInvoice);
    }

    /**
     * Freezes the shipping refund decision so that a later configuration change
     * cannot make the WooCommerce refund differ from the ERP correction.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(ReturnCase $returnCase, ?Invoice $originalInvoice = null): ?array
    {
        if ($this->hasDecision($returnCase)) {
            return $this->detailsFromDecision($returnCase, $originalInvoice);
        }

        $details = $this->calculateDetails($returnCase, $originalInvoice);
        $decision = [
            'decided' => true,
            'eligible' => $details !== null,
            'decided_at' => now()->toISOString(),
        ];

        if ($details !== null) {
            $decision += [
                'configured_gross_amount' => $details['configured_gross_amount'],
                'configured_currency' => $details['configured_currency'],
                'configured_gross_amount_in_refund_currency' => $details['configured_gross_amount_in_refund_currency'],
                'refund_currency' => $details['refund_currency'],
                'conversion_rate' => $details['conversion_rate'],
                'original_shipping_gross_amount' => $details['original_shipping_gross_amount'],
                'gross_amount' => $details['gross_amount'],
                'net_amount' => $details['net_amount'],
                'vat_amount' => $details['vat_amount'],
                'vat_rate' => $details['vat_rate'],
                'original_invoice_id' => $details['original_line']->invoice_id,
                'original_invoice_line_id' => $details['original_line']->id,
                'external_line_id' => data_get($details['original_line']->metadata, 'external_line_id'),
            ];
        }

        $metadata = $returnCase->metadata ?? [];
        data_set($metadata, self::DECISION_METADATA_KEY, $decision);
        $returnCase->forceFill(['metadata' => $metadata])->save();

        return $details;
    }

    /**
     * @return array{
     *     gross_amount:float,
     *     net_amount:float,
     *     tax_amount:float,
     *     vat_rate:float,
     *     currency:string,
     *     wc_order_item_id:mixed
     * }|null
     */
    public function payloadForStore(ReturnCase $returnCase): ?array
    {
        $returnCase->loadMissing(['correctionInvoice.lines', 'externalOrder']);

        if ($returnCase->correctionInvoice instanceof Invoice) {
            $shippingLine = $returnCase->correctionInvoice->lines->first(
                fn (InvoiceLine $line): bool => data_get($line->metadata, 'line_type') === 'shipping'
                    && (float) $line->gross_total < -0.005,
            );

            if (! $shippingLine instanceof InvoiceLine) {
                return null;
            }

            return [
                'gross_amount' => round(abs((float) $shippingLine->gross_total), 2),
                'net_amount' => round(abs((float) $shippingLine->net_total), 2),
                'tax_amount' => round(abs((float) $shippingLine->vat_total), 2),
                'vat_rate' => (float) $shippingLine->vat_rate,
                'currency' => strtoupper((string) $returnCase->correctionInvoice->currency),
                'wc_order_item_id' => data_get($shippingLine->metadata, 'external_line_id'),
            ];
        }

        $details = $this->details($returnCase);

        if ($details === null) {
            return null;
        }

        return [
            'gross_amount' => $details['gross_amount'],
            'net_amount' => $details['net_amount'],
            'tax_amount' => $details['vat_amount'],
            'vat_rate' => $details['vat_rate'],
            'currency' => $details['refund_currency'],
            'wc_order_item_id' => data_get($details['original_line']->metadata, 'external_line_id'),
        ];
    }

    public function amountForStore(ReturnCase $returnCase): float
    {
        return round((float) ($this->payloadForStore($returnCase)['gross_amount'] ?? 0), 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function calculateDetails(ReturnCase $returnCase, ?Invoice $originalInvoice = null): ?array
    {
        $returnCase->loadMissing([
            'lines.externalOrderLine',
            'externalOrder.invoices.lines',
        ]);
        $settings = $this->settings->data();
        $configuredGross = (float) ($settings['refundable_shipping_cost'] ?? 0);
        $configuredCurrency = strtoupper((string) ($settings['refundable_shipping_cost_currency'] ?? 'PLN'));
        $originalInvoice ??= $this->originalInvoice($returnCase);

        if ($configuredGross <= 0
            || ! $originalInvoice instanceof Invoice
            || ! $this->isFullOrderReturn($returnCase, $originalInvoice)
            || $this->shippingWasRefundedForAnotherReturn($returnCase)) {
            return null;
        }

        $shippingLines = $originalInvoice->lines
            ->filter(fn (InvoiceLine $line): bool => data_get($line->metadata, 'line_type') === 'shipping'
                && (float) $line->gross_total > 0.005)
            ->values();

        if ($shippingLines->isEmpty()) {
            return null;
        }

        /** @var InvoiceLine $originalShippingLine */
        $originalShippingLine = $shippingLines->first();
        $refundCurrency = strtoupper((string) $originalInvoice->currency);
        [$configuredInRefundCurrency, $conversionRate] = $this->configuredAmountInInvoiceCurrency(
            $configuredGross,
            $configuredCurrency,
            $originalInvoice,
        );
        $originalShippingGross = round($shippingLines->sum(fn (InvoiceLine $line): float => (float) $line->gross_total), 2);
        $refundGross = round(min($configuredInRefundCurrency, $originalShippingGross), 2);

        if ($refundGross <= 0) {
            return null;
        }

        $vatRate = (float) $originalShippingLine->vat_rate;
        $refundNet = $vatRate > -100
            ? round($refundGross / (1 + ($vatRate / 100)), 2)
            : $refundGross;

        return [
            'configured_gross_amount' => round($configuredGross, 2),
            'configured_currency' => $configuredCurrency,
            'configured_gross_amount_in_refund_currency' => $configuredInRefundCurrency,
            'refund_currency' => $refundCurrency,
            'conversion_rate' => $conversionRate,
            'original_shipping_gross_amount' => $originalShippingGross,
            'gross_amount' => $refundGross,
            'net_amount' => $refundNet,
            'vat_amount' => round($refundGross - $refundNet, 2),
            'vat_rate' => $vatRate,
            'original_line' => $originalShippingLine,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function detailsFromDecision(ReturnCase $returnCase, ?Invoice $originalInvoice = null): ?array
    {
        $decision = data_get($returnCase->metadata, self::DECISION_METADATA_KEY);

        if (! is_array($decision) || ! ($decision['eligible'] ?? false)) {
            return null;
        }

        $returnCase->loadMissing('externalOrder.invoices.lines');
        $originalInvoiceId = (int) ($decision['original_invoice_id'] ?? 0);

        if ($originalInvoiceId > 0 && (int) $originalInvoice?->id !== $originalInvoiceId) {
            $originalInvoice = $returnCase->externalOrder?->invoices->firstWhere('id', $originalInvoiceId);
        }

        $originalInvoice ??= $this->originalInvoice($returnCase);
        $originalLineId = (int) ($decision['original_invoice_line_id'] ?? 0);
        $originalLine = $originalInvoice?->lines->firstWhere('id', $originalLineId);

        if (! $originalLine instanceof InvoiceLine) {
            throw new RuntimeException('Nie znaleziono pozycji dostawy zapisanej dla zatwierdzonego zwrotu.');
        }

        return [
            'configured_gross_amount' => round((float) ($decision['configured_gross_amount'] ?? 0), 2),
            'configured_currency' => strtoupper((string) ($decision['configured_currency'] ?? 'PLN')),
            'configured_gross_amount_in_refund_currency' => round((float) ($decision['configured_gross_amount_in_refund_currency'] ?? $decision['gross_amount'] ?? 0), 2),
            'refund_currency' => strtoupper((string) ($decision['refund_currency'] ?? $originalInvoice->currency)),
            'conversion_rate' => is_numeric($decision['conversion_rate'] ?? null) ? (float) $decision['conversion_rate'] : null,
            'original_shipping_gross_amount' => round((float) ($decision['original_shipping_gross_amount'] ?? 0), 2),
            'gross_amount' => round((float) ($decision['gross_amount'] ?? 0), 2),
            'net_amount' => round((float) ($decision['net_amount'] ?? 0), 2),
            'vat_amount' => round((float) ($decision['vat_amount'] ?? 0), 2),
            'vat_rate' => (float) ($decision['vat_rate'] ?? 0),
            'original_line' => $originalLine,
        ];
    }

    /**
     * @return array{float, ?float}
     */
    private function configuredAmountInInvoiceCurrency(
        float $configuredGross,
        string $configuredCurrency,
        Invoice $invoice,
    ): array {
        $invoiceCurrency = strtoupper((string) $invoice->currency);

        if ($configuredCurrency === $invoiceCurrency) {
            return [round($configuredGross, 2), null];
        }

        if ($configuredCurrency !== 'PLN') {
            throw new RuntimeException("Nieobsługiwana waluta konfiguracji zwrotu dostawy: {$configuredCurrency}.");
        }

        $rate = data_get($invoice->metadata, 'currency_conversion.rate');

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException(
                "Nie można przeliczyć kosztu dostawy {$configuredGross} PLN na {$invoiceCurrency}: faktura pierwotna nie ma prawidłowego kursu waluty.",
            );
        }

        return [round($configuredGross / (float) $rate, 2), (float) $rate];
    }

    private function hasDecision(ReturnCase $returnCase): bool
    {
        $decision = data_get($returnCase->metadata, self::DECISION_METADATA_KEY);

        return is_array($decision) && ($decision['decided'] ?? false) === true;
    }

    private function originalInvoice(ReturnCase $returnCase): ?Invoice
    {
        return $returnCase->externalOrder?->invoices
            ->where('type', 'vat')
            ->where('status', 'issued')
            ->sortByDesc('id')
            ->first();
    }

    private function isFullOrderReturn(ReturnCase $returnCase, Invoice $originalInvoice): bool
    {
        $merchandiseLines = $originalInvoice->lines
            ->filter(function (InvoiceLine $line): bool {
                $lineType = data_get($line->metadata, 'line_type');

                return ! in_array($lineType, ['shipping', 'fee'], true)
                    && ($line->product_id !== null || filled(data_get($line->metadata, 'external_line_id')));
            })
            ->values();

        if ($merchandiseLines->isEmpty()) {
            return false;
        }

        $returnedByInvoiceLine = [];

        foreach ($returnCase->lines as $returnLine) {
            if ((float) $returnLine->quantity_accepted <= 0) {
                continue;
            }

            $invoiceLine = $this->matchingInvoiceLine($originalInvoice, $returnLine);

            if (! $invoiceLine instanceof InvoiceLine || ! $merchandiseLines->contains('id', $invoiceLine->id)) {
                continue;
            }

            $returnedByInvoiceLine[$invoiceLine->id] = ($returnedByInvoiceLine[$invoiceLine->id] ?? 0)
                + (float) $returnLine->quantity_accepted;
        }

        return $merchandiseLines->every(
            fn (InvoiceLine $line): bool => ($returnedByInvoiceLine[$line->id] ?? 0) + 0.00001 >= abs((float) $line->quantity),
        );
    }

    private function shippingWasRefundedForAnotherReturn(ReturnCase $returnCase): bool
    {
        $order = $returnCase->externalOrder;

        if ($order === null) {
            return false;
        }

        return $order->invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->type === 'correction' && $invoice->status !== 'cancelled')
            ->flatMap(fn (Invoice $invoice) => $invoice->lines)
            ->contains(function (InvoiceLine $line) use ($returnCase): bool {
                if (data_get($line->metadata, 'line_type') !== 'shipping' || (float) $line->gross_total >= -0.005) {
                    return false;
                }

                $lineReturnCaseId = (int) data_get($line->metadata, 'return_case_id', 0);

                return $lineReturnCaseId === 0 || $lineReturnCaseId !== (int) $returnCase->id;
            });
    }

    private function matchingInvoiceLine(Invoice $invoice, ReturnCaseLine $returnLine): ?InvoiceLine
    {
        $externalLineId = $returnLine->externalOrderLine?->external_line_id;

        if (filled($externalLineId)) {
            $byExternalLine = $invoice->lines->first(
                fn (InvoiceLine $line): bool => (string) data_get($line->metadata, 'external_line_id') === (string) $externalLineId,
            );

            if ($byExternalLine instanceof InvoiceLine) {
                return $byExternalLine;
            }
        }

        return $invoice->lines->first(
            fn (InvoiceLine $line): bool => $returnLine->product_id !== null && $line->product_id === $returnLine->product_id,
        );
    }
}
