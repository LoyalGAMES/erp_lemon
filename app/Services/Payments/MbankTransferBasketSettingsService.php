<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\AppSetting;

final class MbankTransferBasketSettingsService
{
    private const KEY = 'mbank_transfer_basket';

    /**
     * @return array{source_account:string,source_bank_code:string,source_name:string,encoding:string}
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');
        $data = array_merge($this->defaults(), is_array($stored) ? $stored : []);

        $encoding = in_array($data['encoding'] ?? null, ['UTF-8', 'Windows-1250', 'CP852'], true)
            ? (string) $data['encoding']
            : 'Windows-1250';

        return [
            'source_account' => preg_replace('/\D+/', '', (string) ($data['source_account'] ?? '')) ?? '',
            'source_bank_code' => preg_replace('/\D+/', '', (string) ($data['source_bank_code'] ?? '11402004')) ?: '11402004',
            'source_name' => trim((string) ($data['source_name'] ?? '')),
            'encoding' => $encoding,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $payload = [
            'source_account' => preg_replace('/\D+/', '', (string) ($data['source_account'] ?? '')) ?? '',
            'source_bank_code' => preg_replace('/\D+/', '', (string) ($data['source_bank_code'] ?? '11402004')) ?: '11402004',
            'source_name' => trim((string) ($data['source_name'] ?? '')),
            'encoding' => in_array($data['encoding'] ?? null, ['UTF-8', 'Windows-1250', 'CP852'], true)
                ? (string) $data['encoding']
                : 'Windows-1250',
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'source_account' => '',
            'source_bank_code' => '11402004',
            'source_name' => (string) config('app.name', 'Sempre ERP'),
            'encoding' => 'Windows-1250',
        ];
    }
}
