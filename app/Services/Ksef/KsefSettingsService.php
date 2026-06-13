<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

final class KsefSettingsService
{
    private const KEY = 'ksef_configuration';

    /**
     * @return array<string, mixed>
     */
    public function publicConfiguration(): array
    {
        $stored = $this->stored();

        return [
            'environment' => (string) ($stored['environment'] ?? 'test'),
            'api_version' => (string) ($stored['api_version'] ?? '2.6.0'),
            'base_url' => (string) ($stored['base_url'] ?? ''),
            'gateway_url' => (string) ($stored['gateway_url'] ?? ''),
            'status_url' => (string) ($stored['status_url'] ?? ''),
            'has_access_token' => $this->accessToken() !== '',
            'access_token_hint' => $this->accessTokenHint(),
        ];
    }

    public function value(string $key, string $default = ''): string
    {
        $stored = $this->stored();

        if ($key === 'access_token') {
            return $this->accessToken();
        }

        $value = $stored[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public function update(array $data): array
    {
        $before = $this->publicConfiguration();
        $stored = $this->stored();

        $payload = [
            'environment' => strtolower(trim((string) ($data['environment'] ?? $stored['environment'] ?? 'test'))),
            'api_version' => trim((string) ($data['api_version'] ?? $stored['api_version'] ?? '2.6.0')),
            'base_url' => rtrim(trim((string) ($data['base_url'] ?? $stored['base_url'] ?? '')), '/'),
            'gateway_url' => trim((string) ($data['gateway_url'] ?? $stored['gateway_url'] ?? '')),
            'status_url' => trim((string) ($data['status_url'] ?? $stored['status_url'] ?? '')),
            'access_token_encrypted' => $stored['access_token_encrypted'] ?? null,
        ];

        if (! empty($data['clear_access_token'])) {
            $payload['access_token_encrypted'] = null;
        }

        $newToken = trim((string) ($data['access_token'] ?? ''));
        if ($newToken !== '') {
            $payload['access_token_encrypted'] = Crypt::encryptString($newToken);
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return [
            'before' => $before,
            'after' => $this->publicConfiguration(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        return is_array($stored) ? $stored : [];
    }

    private function accessToken(): string
    {
        $encrypted = $this->stored()['access_token_encrypted'] ?? null;

        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        return Crypt::decryptString($encrypted);
    }

    private function accessTokenHint(): ?string
    {
        $token = $this->accessToken();

        if ($token === '') {
            return null;
        }

        if (strlen($token) <= 4) {
            return '****';
        }

        return str_repeat('*', min(8, strlen($token) - 4)) . substr($token, -4);
    }
}
