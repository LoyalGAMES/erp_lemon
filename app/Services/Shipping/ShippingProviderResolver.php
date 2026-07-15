<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\ExternalOrder;
use App\Models\ShippingLabel;

final class ShippingProviderResolver
{
    public function providerForOrder(ExternalOrder $order): ?string
    {
        $methods = collect((array) data_get($order->raw_payload, 'shipping_lines', []))
            ->map(fn (mixed $line): string => is_array($line)
                ? $this->normalize((string) ($line['method_title'] ?? $line['method_id'] ?? ''))
                : '')
            ->filter();

        if ($methods->contains(fn (string $method): bool => str_contains($method, 'gls'))) {
            return 'gls';
        }

        if ($methods->contains(fn (string $method): bool => str_contains($method, 'inpost')
            || str_contains($method, 'paczkomat')
            || str_contains($method, 'easy-pack')
            || str_contains($method, 'easypack'))) {
            return 'inpost';
        }

        return null;
    }

    public function providerKey(ShippingLabel $label): ?string
    {
        $provider = $this->normalize((string) ($label->provider ?: $label->courierAccount?->provider));

        if ($provider === '') {
            return preg_match('/^\d{24}$/', trim((string) $label->tracking_number)) === 1
                ? 'inpost'
                : null;
        }

        if ($provider === 'blpaczka' || str_contains($provider, 'blpaczka')) {
            return 'blpaczka';
        }

        if (str_contains($provider, 'inpost') || str_contains($provider, 'easy-pack') || str_contains($provider, 'easypack')) {
            return 'inpost';
        }

        foreach (['dpd', 'dhl', 'gls', 'ups', 'fedex', 'pocztex', 'orlen'] as $knownProvider) {
            if (str_contains($provider, $knownProvider)) {
                return $knownProvider;
            }
        }

        return $provider;
    }

    public function courierName(ShippingLabel $label, ?string $fallback = null): string
    {
        $provider = $this->providerKey($label);

        if ($provider === 'blpaczka') {
            $courier = trim((string) (
                data_get($label->response_payload, 'blpaczka.courier_name')
                ?: data_get($label->response_payload, 'blpaczka.courier_code')
                ?: data_get($label->response_payload, 'shipment.courier_name')
            ));

            if ($courier !== '') {
                return $courier;
            }
        }

        $name = match ($provider) {
            'inpost' => 'InPost',
            'dpd' => 'DPD',
            'dhl' => 'DHL',
            'gls' => 'GLS',
            'ups' => 'UPS',
            'fedex' => 'FedEx',
            'pocztex' => 'Pocztex',
            'orlen' => 'ORLEN Paczka',
            'blpaczka' => 'BLPaczka',
            default => null,
        };

        return $name ?: (trim((string) $fallback) ?: 'Nieznany kurier');
    }

    public function trackingUrl(ShippingLabel $label): ?string
    {
        $number = $label->trackingIdentifier();

        if ($number === null) {
            return null;
        }

        $provider = $this->providerKey($label);
        $blpaczkaCourier = $provider === 'blpaczka'
            ? $this->normalize($this->courierName($label))
            : '';

        if ($provider === 'inpost' || str_contains($blpaczkaCourier, 'inpost')) {
            return 'https://inpost.pl/sledzenie-przesylek?number='.rawurlencode($number);
        }

        if ($provider === 'gls' || str_contains($blpaczkaCourier, 'gls')) {
            return 'https://gls-group.com/PL/pl/sledzenie-paczek/?match='.rawurlencode($number);
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return preg_replace('/[^a-z0-9]+/', '-', $ascii !== false ? $ascii : $value) ?: '';
    }
}
