<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\ExternalOrder;

final class OssVatRateService
{
    /**
     * Current EU standard VAT rates used only as a configurable ERP fallback when WooCommerce did not send line tax.
     *
     * @var array<string, float>
     */
    private const STANDARD_RATES = [
        'AT' => 20.0,
        'BE' => 21.0,
        'BG' => 20.0,
        'CY' => 19.0,
        'CZ' => 21.0,
        'DE' => 19.0,
        'DK' => 25.0,
        'EE' => 24.0,
        'EL' => 24.0,
        'ES' => 21.0,
        'FI' => 25.5,
        'FR' => 20.0,
        'GR' => 24.0,
        'HR' => 25.0,
        'HU' => 27.0,
        'IE' => 23.0,
        'IT' => 22.0,
        'LT' => 21.0,
        'LU' => 17.0,
        'LV' => 21.0,
        'MT' => 18.0,
        'NL' => 21.0,
        'PL' => 23.0,
        'PT' => 23.0,
        'RO' => 21.0,
        'SE' => 25.0,
        'SI' => 22.0,
        'SK' => 23.0,
    ];

    public function rateForOrder(ExternalOrder $order): ?float
    {
        $country = $this->buyerCountry($order);

        return $country !== null ? self::STANDARD_RATES[$country] ?? null : null;
    }

    public function isOssB2cOrder(ExternalOrder $order): bool
    {
        $country = $this->buyerCountry($order);

        if ($country === null || $country === 'PL' || ! array_key_exists($country, self::STANDARD_RATES)) {
            return false;
        }

        return $this->buyerTaxId($order) === '';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadataForOrder(ExternalOrder $order): ?array
    {
        if (! $this->isOssB2cOrder($order)) {
            return null;
        }

        $country = $this->buyerCountry($order);

        return [
            'scheme' => 'oss_union',
            'sale_type' => 'eu_b2c_distance_sale',
            'buyer_country' => $country,
            'standard_vat_rate' => $country !== null ? self::STANDARD_RATES[$country] ?? null : null,
            'vat_rate_source' => 'eu_standard_rates_seed_2026',
            'legal_review_required' => true,
        ];
    }

    private function buyerCountry(ExternalOrder $order): ?string
    {
        $billing = $order->billing_data ?? [];
        $country = strtoupper(trim((string) ($billing['country'] ?? data_get($order->raw_payload, 'billing.country', ''))));

        if ($country === 'GR') {
            return 'EL';
        }

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    private function buyerTaxId(ExternalOrder $order): string
    {
        $billing = $order->billing_data ?? [];

        foreach (['nip', 'vat_number', 'billing_nip', 'billing_vat_number', '_billing_nip', '_lemon_erp_billing_nip'] as $key) {
            if (! empty($billing[$key])) {
                return trim((string) $billing[$key]);
            }
        }

        foreach (($order->raw_payload['meta_data'] ?? []) as $meta) {
            $key = (string) ($meta['key'] ?? '');
            if (in_array($key, ['nip', '_billing_nip', 'billing_nip', 'vat_number', '_billing_vat_number', '_lemon_erp_billing_nip'], true)) {
                return trim((string) ($meta['value'] ?? ''));
            }
        }

        return '';
    }
}
