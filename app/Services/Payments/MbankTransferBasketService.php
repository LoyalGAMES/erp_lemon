<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\CustomerPayment;
use App\Models\ExternalOrderLine;
use App\Models\Invoice;
use App\Models\ReturnCase;
use Illuminate\Support\Collection;
use RuntimeException;

final class MbankTransferBasketService
{
    public function __construct(
        private readonly MbankTransferBasketSettingsService $settings,
        private readonly PaymentMethodClassifier $classifier,
    ) {}

    /**
     * @return Collection<int, ReturnCase>
     */
    public function eligibleReturns(): Collection
    {
        return ReturnCase::query()
            ->with([
                'externalOrder',
                'correctionInvoice',
                'lines.externalOrderLine',
                'customerPayments',
            ])
            ->where('status', 'corrected')
            ->whereNotNull('correction_invoice_id')
            ->whereNotNull('external_order_id')
            ->latest()
            ->get()
            ->filter(fn (ReturnCase $returnCase): bool => $this->isEligible($returnCase))
            ->values();
    }

    public function csv(Collection $returns): string
    {
        $settings = $this->settings->data();
        $sourceAccount = $this->normalizeAccount($settings['source_account']);

        if ($sourceAccount === null) {
            throw new RuntimeException('Uzupełnij rachunek źródłowy mBank w ustawieniach płatności.');
        }

        $sourceName = $settings['source_name'] !== '' ? $settings['source_name'] : (string) config('app.name', 'Sempre ERP');
        $rows = $returns
            ->map(fn (ReturnCase $returnCase): string => $this->record($returnCase, $sourceAccount, $settings['source_bank_code'], $sourceName))
            ->implode("\r\n");

        $csv = $rows === '' ? '' : $rows."\r\n";

        if ($settings['encoding'] === 'UTF-8') {
            return $csv;
        }

        return mb_convert_encoding($csv, $settings['encoding'], 'UTF-8');
    }

    public function amount(ReturnCase $returnCase): float
    {
        if ($returnCase->correctionInvoice instanceof Invoice) {
            return abs((float) $returnCase->correctionInvoice->gross_total);
        }

        return round($returnCase->lines->sum(function ($line): float {
            $orderLine = $line->externalOrderLine;

            if (! $orderLine instanceof ExternalOrderLine) {
                return 0.0;
            }

            return (float) $line->quantity_accepted * (float) $orderLine->unit_gross_price;
        }), 2);
    }

    public function recipientName(ReturnCase $returnCase): string
    {
        $metadataName = trim((string) data_get($returnCase->metadata, 'refund_recipient_name', ''));

        if ($metadataName !== '') {
            return $metadataName;
        }

        $billing = (array) ($returnCase->externalOrder?->billing_data ?? []);
        $name = trim(implode(' ', array_filter([
            $billing['first_name'] ?? null,
            $billing['last_name'] ?? null,
            $billing['company'] ?? null,
        ])));

        return $name !== '' ? $name : ($returnCase->customer_email ?: $returnCase->number);
    }

    public function recipientAccount(ReturnCase $returnCase): ?string
    {
        return $this->classifier->refundBankAccount($returnCase->metadata ?? []);
    }

    private function isEligible(ReturnCase $returnCase): bool
    {
        if ($returnCase->externalOrder === null || ! $this->classifier->isCashOnDelivery($returnCase->externalOrder)) {
            return false;
        }

        if ($returnCase->status !== 'corrected' || ! $returnCase->correctionInvoice instanceof Invoice) {
            return false;
        }

        if ($this->recipientAccount($returnCase) === null || $this->amount($returnCase) <= 0.0) {
            return false;
        }

        return ! $returnCase->customerPayments
            ->contains(fn (CustomerPayment $payment): bool => $payment->direction === 'outgoing'
                && in_array($payment->method, ['mbank', 'bank_transfer'], true)
                && in_array($payment->status, ['paid', 'settled'], true));
    }

    private function record(ReturnCase $returnCase, string $sourceAccount, string $sourceBankCode, string $sourceName): string
    {
        $recipientAccount = $this->recipientAccount($returnCase);

        if ($recipientAccount === null) {
            throw new RuntimeException("Zwrot {$returnCase->number} nie ma poprawnego rachunku klienta.");
        }

        $fields = [
            '110',
            now()->format('Ymd'),
            (string) (int) round($this->amount($returnCase) * 100),
            $this->bankCode($sourceAccount, $sourceBankCode),
            '0',
            $this->quoted($sourceAccount),
            $this->quoted($recipientAccount),
            $this->quoted($this->chunked($sourceName)),
            $this->quoted($this->chunked($this->recipientName($returnCase))),
            '0',
            $this->bankCode($recipientAccount, ''),
            $this->quoted($this->chunked($this->title($returnCase))),
            $this->quoted(''),
            $this->quoted(''),
            $this->quoted('51'),
        ];

        return implode(',', $fields);
    }

    private function normalizeAccount(string $account): ?string
    {
        $account = preg_replace('/\D+/', '', $account) ?? '';

        if (str_starts_with($account, '48') && strlen($account) === 28) {
            $account = substr($account, 2);
        }

        return strlen($account) === 26 ? $account : null;
    }

    private function bankCode(string $account, string $fallback): string
    {
        if (strlen($account) === 26) {
            return substr($account, 2, 8);
        }

        $fallback = preg_replace('/\D+/', '', $fallback) ?? '';

        return substr(str_pad($fallback, 8, '0'), 0, 8);
    }

    private function title(ReturnCase $returnCase): string
    {
        $orderNumber = $returnCase->externalOrder?->external_number;

        return trim('Zwrot '.$returnCase->number.($orderNumber ? ' zam. '.$orderNumber : ''));
    }

    private function chunked(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        $value = mb_substr($value, 0, 143);
        $chunks = [];

        while ($value !== '') {
            $chunks[] = mb_substr($value, 0, 35);
            $value = mb_substr($value, 35);
        }

        return implode('|', $chunks);
    }

    private function quoted(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
