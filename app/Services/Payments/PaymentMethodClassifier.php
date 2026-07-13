<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\ExternalOrder;

final class PaymentMethodClassifier
{
    public const CASH_ON_DELIVERY = 'cash_on_delivery';

    public const BANK_TRANSFER = 'bank_transfer';

    public const ONLINE = 'online';

    public const OTHER = 'other';

    public function category(ExternalOrder $order): string
    {
        if ($this->isCashOnDelivery($order)) {
            return self::CASH_ON_DELIVERY;
        }

        if ($this->isBankTransfer($order)) {
            return self::BANK_TRANSFER;
        }

        if ($this->isOnline($order)) {
            return self::ONLINE;
        }

        return self::OTHER;
    }

    public function isCashOnDelivery(ExternalOrder $order): bool
    {
        $method = $this->paymentMethod($order);

        if (in_array($method, ['cod', 'cash_on_delivery', 'cash-on-delivery'], true)
            || preg_match('/(?:^|[_-])cod(?:$|[_-])/', $method) === 1) {
            return true;
        }

        $title = $this->paymentTitle($order);

        foreach (['pobran', 'za pobraniem', 'cash on delivery', 'przy odbiorze'] as $needle) {
            if (str_contains($title, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function isPayuPrepaid(ExternalOrder $order): bool
    {
        if ($this->isCashOnDelivery($order) || $this->isBankTransfer($order)) {
            return false;
        }

        return $this->hasPayuMarker($order);
    }

    public function isBankTransfer(ExternalOrder $order): bool
    {
        if ($this->isCashOnDelivery($order)) {
            return false;
        }

        $method = $this->paymentMethod($order);

        if (in_array($method, ['bacs', 'bank_transfer', 'bank-transfer', 'wire_transfer'], true)) {
            return true;
        }

        $title = $this->paymentTitle($order);

        foreach (['przelew tradycyjny', 'przelew bankowy', 'wpłata na konto', 'wplata na konto', 'wire transfer'] as $needle) {
            if (str_contains($title, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function isOnline(ExternalOrder $order): bool
    {
        if ($this->isCashOnDelivery($order) || $this->isBankTransfer($order)) {
            return false;
        }

        $method = $this->paymentMethod($order);

        foreach (['payu', 'przelewy24', 'p24', 'tpay', 'stripe', 'paypal', 'blik', 'autopay', 'woocommerce_payments'] as $needle) {
            if ($method === $needle || str_contains($method, $needle)) {
                return true;
            }
        }

        $haystack = $this->paymentDescriptorHaystack($order);

        foreach (['payu', 'przelewy24', 'p24', 'tpay', 'stripe', 'paypal', 'blik', 'autopay', 'płatność online', 'platnosc online'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return $this->hasPayuMarker($order);
    }

    public function customerInstruction(ExternalOrder $order, ?string $trigger = null): string
    {
        if ($trigger === 'order_payment_failed') {
            return $this->category($order) === self::ONLINE
                ? 'Płatność online nie została potwierdzona — możesz bezpiecznie ponowić ją przyciskiem w tej wiadomości.'
                : '';
        }

        if ($trigger === 'order_payment_received') {
            return 'Wpłata została zaksięgowana w zamówieniu.';
        }

        if (in_array($trigger, ['order_received', 'order_packed', 'order_invoice_ready', 'order_packing_rollback', 'order_courier_picked_up', 'order_delivered'], true)) {
            return match ($this->category($order)) {
                self::CASH_ON_DELIVERY => 'Płatność przy odbiorze — zapłacisz za zamówienie kurierowi. Nie musisz opłacać go online.',
                self::BANK_TRANSFER => 'Przelew został zaksięgowany, dlatego zamówienie jest już realizowane.',
                self::ONLINE => 'Płatność online została potwierdzona.',
                default => '',
            };
        }

        if (in_array($trigger, ['order_cancelled', 'order_cancelled_problem', 'order_refunded'], true)) {
            return '';
        }

        return match ($this->category($order)) {
            self::CASH_ON_DELIVERY => 'Płatność przy odbiorze — zapłacisz za zamówienie kurierowi. Nie musisz opłacać go online.',
            self::BANK_TRANSFER => 'Przelew tradycyjny — realizację rozpoczniemy po zaksięgowaniu wpłaty na naszym koncie.',
            self::ONLINE => 'Płatność online — jeśli poprzednia próba nie została ukończona, możesz bezpiecznie wrócić do płatności.',
            default => 'Sposób płatności zapisaliśmy w szczegółach zamówienia. W razie pytań odpowiedz na tę wiadomość.',
        };
    }

    public function payuOrderId(ExternalOrder $order): ?string
    {
        if ($this->isCashOnDelivery($order) || $this->isBankTransfer($order)) {
            return null;
        }

        foreach ([
            data_get($order->raw_payload, 'payu_order_id'),
            data_get($order->raw_payload, 'payment_details.payu_order_id'),
        ] as $candidate) {
            $value = $this->cleanString($candidate);

            if ($value !== null) {
                return $value;
            }
        }

        if (! $this->hasPayuMarker($order)) {
            return null;
        }

        foreach ([
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

            $key = is_scalar($meta['key'] ?? null) ? mb_strtolower((string) $meta['key']) : '';

            if (! str_contains($key, 'payu') && ! str_contains($key, 'transaction')) {
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

    private function paymentDescriptorHaystack(ExternalOrder $order): string
    {
        $parts = [
            data_get($order->raw_payload, 'payment_method'),
            data_get($order->raw_payload, 'payment_method_title'),
            data_get($order->raw_payload, 'payment_url'),
        ];

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $key = is_scalar($meta['key'] ?? null) ? mb_strtolower((string) $meta['key']) : '';

            if (str_contains($key, 'payment') || str_contains($key, 'gateway')) {
                $parts[] = $key;
                $parts[] = $meta['value'] ?? null;
            }
        }

        return mb_strtolower(implode(' ', array_map(static fn ($part): string => is_scalar($part) ? (string) $part : '', $parts)));
    }

    private function hasPayuMarker(ExternalOrder $order): bool
    {
        if ($this->cleanString(data_get($order->raw_payload, 'payu_order_id')) !== null
            || $this->cleanString(data_get($order->raw_payload, 'payment_details.payu_order_id')) !== null) {
            return true;
        }

        foreach ([
            data_get($order->raw_payload, 'payment_method'),
            data_get($order->raw_payload, 'payment_method_title'),
            data_get($order->raw_payload, 'payment_url'),
        ] as $candidate) {
            $value = $this->cleanString($candidate);

            if ($value !== null && str_contains(mb_strtolower($value), 'payu')) {
                return true;
            }
        }

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $key = is_scalar($meta['key'] ?? null) ? mb_strtolower((string) $meta['key']) : '';
            $value = is_scalar($meta['value'] ?? null) ? mb_strtolower((string) $meta['value']) : '';

            if (str_contains($key, 'payu')
                || ((str_contains($key, 'payment') || str_contains($key, 'gateway'))
                    && str_contains($value, 'payu'))) {
                return true;
            }
        }

        return false;
    }

    private function paymentMethod(ExternalOrder $order): string
    {
        return mb_strtolower($this->cleanString(data_get($order->raw_payload, 'payment_method')) ?? '');
    }

    private function paymentTitle(ExternalOrder $order): string
    {
        return mb_strtolower($this->cleanString(data_get($order->raw_payload, 'payment_method_title')) ?? '');
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
