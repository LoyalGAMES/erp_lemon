<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\AppSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class PayuRefundSettingsService
{
    private const KEY = 'payu_refunds';

    /**
     * @return array{enabled:bool,auto_refund_enabled:bool,environment:string,client_id:string,pos_id:string,refund_type:string,client_secret_configured:bool}
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');
        $data = array_merge($this->defaults(), is_array($stored) ? $stored : []);

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'auto_refund_enabled' => (bool) ($data['auto_refund_enabled'] ?? false),
            'environment' => in_array($data['environment'] ?? null, ['sandbox', 'production'], true)
                ? (string) $data['environment']
                : 'sandbox',
            'client_id' => trim((string) ($data['client_id'] ?? '')),
            'pos_id' => trim((string) ($data['pos_id'] ?? '')),
            'refund_type' => in_array($data['refund_type'] ?? null, ['REFUND_PAYMENT_STANDARD', 'FAST'], true)
                ? (string) $data['refund_type']
                : 'REFUND_PAYMENT_STANDARD',
            'client_secret_configured' => filled($data['client_secret_encrypted'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');
        $stored = is_array($stored) ? $stored : [];

        $secret = trim((string) ($data['client_secret'] ?? ''));
        $encrypted = $stored['client_secret_encrypted'] ?? null;

        if ($secret !== '') {
            $encrypted = Crypt::encryptString($secret);
        } elseif ((bool) ($data['clear_client_secret'] ?? false)) {
            $encrypted = null;
        }

        $payload = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'auto_refund_enabled' => (bool) ($data['auto_refund_enabled'] ?? false),
            'environment' => in_array($data['environment'] ?? null, ['sandbox', 'production'], true)
                ? (string) $data['environment']
                : 'sandbox',
            'client_id' => trim((string) ($data['client_id'] ?? '')),
            'pos_id' => trim((string) ($data['pos_id'] ?? '')),
            'client_secret_encrypted' => $encrypted,
            'refund_type' => in_array($data['refund_type'] ?? null, ['REFUND_PAYMENT_STANDARD', 'FAST'], true)
                ? (string) $data['refund_type']
                : 'REFUND_PAYMENT_STANDARD',
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    public function clientSecret(): ?string
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');
        $encrypted = is_array($stored) ? ($stored['client_secret_encrypted'] ?? null) : null;

        if (! filled($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    public function baseUrl(): string
    {
        return $this->data()['environment'] === 'production'
            ? 'https://secure.payu.com'
            : 'https://secure.snd.payu.com';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'enabled' => false,
            'auto_refund_enabled' => false,
            'environment' => 'sandbox',
            'client_id' => '',
            'pos_id' => '',
            'client_secret_encrypted' => null,
            'refund_type' => 'REFUND_PAYMENT_STANDARD',
        ];
    }
}
