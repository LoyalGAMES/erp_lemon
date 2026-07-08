<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\ExternalOrder;

final class PaymentMethodClassifier
{
    public function isCashOnDelivery(ExternalOrder $order): bool
    {
        $haystack = $this->paymentHaystack($order);

        foreach (['cod', 'pobran', 'za pobraniem', 'cash on delivery', 'przy odbiorze'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function isPayuPrepaid(ExternalOrder $order): bool
    {
        if ($this->isCashOnDelivery($order)) {
            return false;
        }

        return $this->payuOrderId($order) !== null
            || str_contains($this->paymentHaystack($order), 'payu');
    }

    public function payuOrderId(ExternalOrder $order): ?string
    {
        foreach ([
            data_get($order->raw_payload, 'payu_order_id'),
            data_get($order->raw_payload, 'orderId'),
            data_get($order->raw_payload, 'transaction_id'),
            data_get($order->raw_payload, 'transactionId'),
            data_get($order->raw_payload, 'payment_details.transaction_id'),
        ] as $candidate) {
            $value = $this->cleanString($candidate);

            if ($value !== null) {
                return $value;
            }
        }

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $key = mb_strtolower((string) ($meta['key'] ?? ''));

            if (! str_contains($key, 'payu') && ! str_contains($key, 'transaction') && ! str_contains($key, 'order_id')) {
                continue;
            }

            $value = $this->cleanString($meta['value'] ?? null);

            if ($value !== null && ! in_array($value, ['0', 'false', 'null'], true)) {
                return $value;
            }
        }

        return null;
    }

    public function refundBankAccount(array $metadata): ?string
    {
        foreach (['refund_bank_account', 'customer_bank_account', 'bank_account', 'iban'] as $key) {
            $account = preg_replace('/\D+/', '', (string) ($metadata[$key] ?? '')) ?? '';

            if (str_starts_with($account, '48') && strlen($account) === 28) {
                $account = substr($account, 2);
            }

            if (strlen($account) === 26) {
                return $account;
            }
        }

        return null;
    }

    private function paymentHaystack(ExternalOrder $order): string
    {
        $parts = [
            data_get($order->raw_payload, 'payment_method'),
            data_get($order->raw_payload, 'payment_method_title'),
            data_get($order->raw_payload, 'payment_url'),
            data_get($order->raw_payload, 'transaction_id'),
        ];

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            if (is_array($meta)) {
                $parts[] = $meta['key'] ?? null;
                $parts[] = $meta['value'] ?? null;
            }
        }

        return mb_strtolower(implode(' ', array_map(static fn ($part): string => is_scalar($part) ? (string) $part : '', $parts)));
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
