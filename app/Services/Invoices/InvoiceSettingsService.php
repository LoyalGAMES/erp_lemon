<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\AppSetting;

final class InvoiceSettingsService
{
    private const SELLER_KEY = 'invoice_seller';

    private const NUMBERING_KEY = 'invoice_numbering';

    private const KSEF_KEY = 'invoice_ksef_settings';

    /**
     * @return array<string, string>
     */
    public function sellerData(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::SELLER_KEY)
            ->value('value');

        return array_merge($this->defaultSellerData(), is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function updateSellerData(array $data): array
    {
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'tax_id' => $this->cleanTaxId((string) ($data['tax_id'] ?? '')),
            'address_1' => trim((string) ($data['address_1'] ?? '')),
            'address_2' => trim((string) ($data['address_2'] ?? '')),
            'postcode' => trim((string) ($data['postcode'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'country' => strtoupper(trim((string) ($data['country'] ?? 'PL'))) ?: 'PL',
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'bank_account' => trim((string) ($data['bank_account'] ?? '')),
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::SELLER_KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    /**
     * @return array{is_ready: bool, errors: list<string>, warnings: list<string>}
     */
    public function sellerConfigurationStatus(?array $data = null): array
    {
        $seller = $data ?? $this->sellerData();
        $errors = [];
        $warnings = [];

        foreach ([
            'name' => 'nazwy firmy',
            'tax_id' => 'NIP',
            'address_1' => 'adresu',
            'postcode' => 'kodu pocztowego',
            'city' => 'miasta',
            'country' => 'kraju',
        ] as $key => $label) {
            if (! filled($seller[$key] ?? null)) {
                $errors[] = 'Brakuje '.$label.' sprzedawcy.';
            }
        }

        $country = strtoupper(trim((string) ($seller['country'] ?? 'PL'))) ?: 'PL';
        $taxId = $this->cleanTaxId((string) ($seller['tax_id'] ?? ''));

        if ($taxId !== '' && $country === 'PL' && ! $this->isValidPolishNip($taxId)) {
            $errors[] = 'NIP sprzedawcy ma niepoprawny format lub sumę kontrolną.';
        }

        if ($taxId !== '' && $country !== 'PL' && mb_strlen($taxId) < 4) {
            $warnings[] = 'Zagraniczny numer podatkowy sprzedawcy wygląda nietypowo.';
        }

        if (! filled($seller['bank_account'] ?? null)) {
            $warnings[] = 'Brakuje numeru konta bankowego na fakturach.';
        }

        return [
            'is_ready' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{sales_prefix: string, correction_prefix: string, pattern: string, padding: int, payment_due_days: int}
     */
    public function numberingData(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::NUMBERING_KEY)
            ->value('value');

        $data = array_merge($this->defaultNumberingData(), is_array($stored) ? $stored : []);

        return [
            'sales_prefix' => $this->cleanPrefix((string) $data['sales_prefix'], 'FV'),
            'correction_prefix' => $this->cleanPrefix((string) $data['correction_prefix'], 'FK'),
            'pattern' => $this->cleanPattern((string) ($data['pattern'] ?? '{PREFIX}/{YYYY}/{SEQ}')),
            'padding' => max(3, min(9, (int) $data['padding'])),
            'payment_due_days' => max(0, min(365, (int) $data['payment_due_days'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sales_prefix: string, correction_prefix: string, pattern: string, padding: int, payment_due_days: int}
     */
    public function updateNumberingData(array $data): array
    {
        $payload = [
            'sales_prefix' => $this->cleanPrefix((string) ($data['sales_prefix'] ?? 'FV'), 'FV'),
            'correction_prefix' => $this->cleanPrefix((string) ($data['correction_prefix'] ?? 'FK'), 'FK'),
            'pattern' => $this->cleanPattern((string) ($data['pattern'] ?? '{PREFIX}/{YYYY}/{SEQ}')),
            'padding' => max(3, min(9, (int) ($data['padding'] ?? 6))),
            'payment_due_days' => max(0, min(365, (int) ($data['payment_due_days'] ?? 0))),
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::NUMBERING_KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    public function paymentDueDate(): string
    {
        return now()->addDays($this->numberingData()['payment_due_days'])->toDateString();
    }

    /**
     * @return array{default_send_policy: string}
     */
    public function ksefData(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KSEF_KEY)
            ->value('value');

        $data = array_merge($this->defaultKsefData(), is_array($stored) ? $stored : []);

        return [
            'default_send_policy' => $this->cleanKsefPolicy((string) ($data['default_send_policy'] ?? 'auto')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{default_send_policy: string}
     */
    public function updateKsefData(array $data): array
    {
        $payload = [
            'default_send_policy' => $this->cleanKsefPolicy((string) ($data['default_ksef_policy'] ?? $data['default_send_policy'] ?? 'auto')),
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::KSEF_KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function defaultSellerData(): array
    {
        return [
            'name' => env('INVOICE_SELLER_NAME', 'Sempre'),
            'tax_id' => env('INVOICE_SELLER_NIP', ''),
            'address_1' => env('INVOICE_SELLER_ADDRESS_1', ''),
            'address_2' => env('INVOICE_SELLER_ADDRESS_2', ''),
            'postcode' => env('INVOICE_SELLER_POSTCODE', ''),
            'city' => env('INVOICE_SELLER_CITY', ''),
            'country' => env('INVOICE_SELLER_COUNTRY', 'PL'),
            'email' => env('INVOICE_SELLER_EMAIL', ''),
            'phone' => env('INVOICE_SELLER_PHONE', ''),
            'bank_account' => env('INVOICE_SELLER_BANK_ACCOUNT', ''),
        ];
    }

    /**
     * @return array{sales_prefix: string, correction_prefix: string, pattern: string, padding: int, payment_due_days: int}
     */
    private function defaultNumberingData(): array
    {
        return [
            'sales_prefix' => env('INVOICE_SALES_PREFIX', 'FV'),
            'correction_prefix' => env('INVOICE_CORRECTION_PREFIX', 'FK'),
            'pattern' => env('INVOICE_NUMBER_PATTERN', '{PREFIX}/{YYYY}/{SEQ}'),
            'padding' => (int) env('INVOICE_NUMBER_PADDING', 6),
            'payment_due_days' => (int) env('INVOICE_PAYMENT_DUE_DAYS', 0),
        ];
    }

    /**
     * @return array{default_send_policy: string}
     */
    private function defaultKsefData(): array
    {
        return [
            'default_send_policy' => env('INVOICE_KSEF_DEFAULT_SEND_POLICY', 'auto'),
        ];
    }

    private function cleanPrefix(string $value, string $fallback): string
    {
        $value = trim($value, " \t\n\r\0\x0B/");
        $value = preg_replace('/[^A-Za-z0-9_\/-]+/', '', $value) ?? '';

        return $value !== '' ? $value : $fallback;
    }

    private function cleanPattern(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            $value = '{PREFIX}/{YYYY}/{SEQ}';
        }

        $value = preg_replace('/[^A-Za-z0-9_\/{}-]+/', '', $value) ?? '';

        if (! str_contains($value, '{SEQ}')) {
            $value = rtrim($value, '/').'/{SEQ}';
        }

        return $value !== '' ? $value : '{PREFIX}/{YYYY}/{SEQ}';
    }

    private function cleanKsefPolicy(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['auto', 'send', 'skip'], true) ? $value : 'auto';
    }

    private function cleanTaxId(string $value): string
    {
        $value = strtoupper(trim($value));

        if (str_starts_with($value, 'PL')) {
            $value = substr($value, 2);
        }

        return preg_replace('/[\s-]+/', '', $value) ?? '';
    }

    private function isValidPolishNip(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) !== 10) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        return $sum % 11 === (int) $digits[9];
    }
}
