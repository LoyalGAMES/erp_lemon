<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

final class KsefSettingsService
{
    private const KEY = 'ksef_configuration';

    public const TEST_PUBLIC_KEY_ID = '5855a4';

    public const TEST_PUBLIC_KEY_SHA256 = 'd38f31638bb72c435d03b34115f977ccb1e1c406b7abfb2852bd55f185217187';

    /**
     * @return array<string, mixed>
     */
    public function publicConfiguration(): array
    {
        $stored = $this->stored();
        $environment = $this->normalizeEnvironment((string) ($stored['environment'] ?? 'test'));
        $publicKeyId = $this->publicKeyId($stored, $environment);
        $publicKeySha256 = $this->publicKeySha256($stored, $environment);

        return [
            'environment' => $environment,
            'api_version' => (string) ($stored['api_version'] ?? '2.6.0'),
            'base_url' => (string) ($stored['base_url'] ?? ''),
            'gateway_url' => (string) ($stored['gateway_url'] ?? ''),
            'status_url' => (string) ($stored['status_url'] ?? ''),
            'public_key_id' => $publicKeyId,
            'public_key_sha256' => $publicKeySha256,
            'uses_test_public_key' => $publicKeyId === self::TEST_PUBLIC_KEY_ID
                && strtolower($publicKeySha256) === self::TEST_PUBLIC_KEY_SHA256,
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
     * @param  array<string, mixed>  $data
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public function update(array $data): array
    {
        $before = $this->publicConfiguration();
        $stored = $this->stored();
        $environment = $this->normalizeEnvironment((string) ($data['environment'] ?? $stored['environment'] ?? 'test'));
        $publicKeyId = trim((string) ($data['public_key_id'] ?? $stored['public_key_id'] ?? ''));
        $publicKeySha256 = strtolower(trim((string) ($data['public_key_sha256'] ?? $stored['public_key_sha256'] ?? '')));

        if ($environment === 'test') {
            $publicKeyId = $publicKeyId !== '' ? $publicKeyId : self::TEST_PUBLIC_KEY_ID;
            $publicKeySha256 = $publicKeySha256 !== '' ? $publicKeySha256 : self::TEST_PUBLIC_KEY_SHA256;
        } elseif (
            $publicKeyId === self::TEST_PUBLIC_KEY_ID
            && $publicKeySha256 === self::TEST_PUBLIC_KEY_SHA256
        ) {
            $publicKeyId = '';
            $publicKeySha256 = '';
        }

        $payload = [
            'environment' => $environment,
            'api_version' => trim((string) ($data['api_version'] ?? $stored['api_version'] ?? '2.6.0')),
            'base_url' => rtrim(trim((string) ($data['base_url'] ?? $stored['base_url'] ?? '')), '/'),
            'gateway_url' => trim((string) ($data['gateway_url'] ?? $stored['gateway_url'] ?? '')),
            'status_url' => trim((string) ($data['status_url'] ?? $stored['status_url'] ?? '')),
            'public_key_id' => $publicKeyId,
            'public_key_sha256' => $publicKeySha256,
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

    /**
     * @param  array<string, mixed>  $stored
     */
    private function publicKeyId(array $stored, string $environment): string
    {
        $value = trim((string) ($stored['public_key_id'] ?? ''));

        if ($value !== '') {
            return $value;
        }

        return $environment === 'test' ? self::TEST_PUBLIC_KEY_ID : '';
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function publicKeySha256(array $stored, string $environment): string
    {
        $value = strtolower(trim((string) ($stored['public_key_sha256'] ?? '')));

        if ($value !== '') {
            return $value;
        }

        return $environment === 'test' ? self::TEST_PUBLIC_KEY_SHA256 : '';
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));

        return in_array($environment, ['test', 'demo', 'production'], true) ? $environment : 'test';
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

        return str_repeat('*', min(8, strlen($token) - 4)).substr($token, -4);
    }
}
