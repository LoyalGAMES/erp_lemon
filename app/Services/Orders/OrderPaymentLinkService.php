<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\WordpressIntegration;

final class OrderPaymentLinkService
{
    public function resolve(ExternalOrder $order): ?string
    {
        foreach ([
            data_get($order->raw_payload, 'payment_url'),
            data_get($order->raw_payload, 'checkout_payment_url'),
            data_get($order->raw_payload, 'pay_url'),
            data_get($order->raw_payload, 'payment_details.payment_url'),
        ] as $candidate) {
            $url = $this->httpUrl($candidate);

            if ($url !== null) {
                return $url;
            }
        }

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $key = mb_strtolower((string) ($meta['key'] ?? ''));

            if (! str_contains($key, 'payment_url') && ! str_contains($key, 'pay_url')) {
                continue;
            }

            $url = $this->httpUrl($meta['value'] ?? null);

            if ($url !== null) {
                return $url;
            }
        }

        $orderKey = trim((string) data_get($order->raw_payload, 'order_key', ''));

        if ($orderKey === '' || trim((string) $order->external_id) === '') {
            return null;
        }

        $integration = WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->first();

        if (! $integration instanceof WordpressIntegration) {
            return null;
        }

        return rtrim($integration->base_url, '/')
            .'/checkout/order-pay/'.rawurlencode((string) $order->external_id)
            .'/?pay_for_order=true&key='.rawurlencode($orderKey);
    }

    private function httpUrl(mixed $candidate): ?string
    {
        if (! is_scalar($candidate)) {
            return null;
        }

        $url = trim((string) $candidate);
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
                ? $url
                : null;
    }
}
