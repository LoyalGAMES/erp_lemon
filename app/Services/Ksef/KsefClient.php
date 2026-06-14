<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\KsefSubmission;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class KsefClient
{
    public function __construct(
        private readonly KsefSettingsService $settings,
    ) {}

    public function configurationStatus(): array
    {
        $environment = $this->environment();
        $hasAccessToken = $this->accessToken() !== '';
        $hasGatewayUrl = $this->gatewayUrl() !== '';
        $statusUrl = $this->statusUrl();

        return [
            'environment' => $environment,
            'api_version' => $this->apiVersion(),
            'base_url' => $this->baseUrl($environment),
            'status_url' => $statusUrl,
            'public_key_id' => $this->publicKeyId($environment),
            'public_key_sha256' => $this->publicKeySha256($environment),
            'has_access_token' => $hasAccessToken,
            'has_gateway_url' => $hasGatewayUrl,
            'has_status_url' => $statusUrl !== '',
            'has_public_key' => $this->publicKeyId($environment) !== '' && $this->publicKeySha256($environment) !== '',
            'direct_online_send_ready' => $hasAccessToken && $hasGatewayUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function send(KsefSubmission $submission): array
    {
        $token = $this->accessToken();

        if ($token === '') {
            throw new RuntimeException('Brak tokena dostępu KSeF. Ustaw KSEF_ACCESS_TOKEN albo skonfiguruj docelowy sposób uwierzytelnienia.');
        }

        $gatewayUrl = $this->gatewayUrl();

        if ($gatewayUrl === '') {
            throw new RuntimeException('KSeF API 2.0 wymaga szyfrowanej sesji online i zaszyfrowanego XML. Skonfiguruj bramkę KSeF albo etap szyfrowania przed realną wysyłką.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post($gatewayUrl, [
                'environment' => $submission->environment,
                'api_version' => $submission->api_version,
                'invoice_id' => $submission->invoice_id,
                'invoice_xml' => $submission->xml_payload,
                'invoice_hash_sha256' => base64_encode(hash('sha256', (string) $submission->xml_payload, true)),
                'invoice_size' => strlen((string) $submission->xml_payload),
                'public_key_id' => $this->publicKeyId($submission->environment),
                'public_key_sha256' => $this->publicKeySha256($submission->environment),
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Bramka KSeF zwróciła HTTP {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(KsefSubmission $submission): array
    {
        $token = $this->accessToken();

        if ($token === '') {
            throw new RuntimeException('Brak tokena dostępu KSeF. Nie można sprawdzić statusu zgłoszenia.');
        }

        if (! filled($submission->reference_number)) {
            throw new RuntimeException('Zgłoszenie KSeF nie ma numeru referencyjnego, więc nie można sprawdzić jego statusu.');
        }

        $statusUrl = $this->statusUrl($submission);

        if ($statusUrl === '') {
            throw new RuntimeException('Brak adresu sprawdzania statusu KSeF. Uzupełnij adres statusu w konfiguracji KSeF.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post($statusUrl, [
                'environment' => $submission->environment,
                'api_version' => $submission->api_version,
                'invoice_id' => $submission->invoice_id,
                'submission_id' => $submission->id,
                'referenceNumber' => $submission->reference_number,
                'reference_number' => $submission->reference_number,
                'ksefNumber' => $submission->ksef_number,
                'ksef_number' => $submission->ksef_number,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Bramka statusu KSeF zwróciła HTTP {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    public function environment(): string
    {
        $environment = strtolower($this->setting('environment', 'KSEF_ENVIRONMENT', 'test'));

        return in_array($environment, ['production', 'prod', 'demo', 'test'], true)
            ? $environment
            : 'test';
    }

    public function apiVersion(): string
    {
        return $this->setting('api_version', 'KSEF_API_VERSION', '2.6.0');
    }

    public function baseUrl(?string $environment = null): string
    {
        $configured = $this->setting('base_url', 'KSEF_BASE_URL', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return match ($environment ?? $this->environment()) {
            'production', 'prod' => 'https://api.ksef.mf.gov.pl/v2',
            'demo' => 'https://api-demo.ksef.mf.gov.pl/v2',
            default => 'https://api-test.ksef.mf.gov.pl/v2',
        };
    }

    private function publicKeyId(?string $environment = null): string
    {
        return $this->setting(
            'public_key_id',
            'KSEF_PUBLIC_KEY_ID',
            $this->defaultPublicKeyId($environment ?? $this->environment()),
        );
    }

    private function publicKeySha256(?string $environment = null): string
    {
        return strtolower($this->setting(
            'public_key_sha256',
            'KSEF_PUBLIC_KEY_SHA256',
            $this->defaultPublicKeySha256($environment ?? $this->environment()),
        ));
    }

    private function accessToken(): string
    {
        return $this->setting('access_token', 'KSEF_ACCESS_TOKEN', '');
    }

    private function gatewayUrl(): string
    {
        return $this->setting('gateway_url', 'KSEF_GATEWAY_URL', '');
    }

    private function statusUrl(?KsefSubmission $submission = null): string
    {
        $stored = $this->setting('status_url', 'KSEF_STATUS_URL', '');
        if ($stored !== '') {
            return $stored;
        }

        $responseUrl = $submission !== null
            ? (string) data_get($submission->response_metadata, 'statusUrl', data_get($submission->response_metadata, 'status_url', ''))
            : '';

        if (trim($responseUrl) !== '') {
            return trim($responseUrl);
        }

        $gatewayUrl = $this->gatewayUrl();
        if ($gatewayUrl === '') {
            return '';
        }

        if (preg_match('#/submit/?$#', $gatewayUrl) === 1) {
            return preg_replace('#/submit/?$#', '/status', $gatewayUrl) ?? '';
        }

        return rtrim($gatewayUrl, '/').'/status';
    }

    private function defaultPublicKeyId(string $environment): string
    {
        return $environment === 'test' ? KsefSettingsService::TEST_PUBLIC_KEY_ID : '';
    }

    private function defaultPublicKeySha256(string $environment): string
    {
        return $environment === 'test' ? KsefSettingsService::TEST_PUBLIC_KEY_SHA256 : '';
    }

    private function setting(string $configKey, string $envKey, string $default): string
    {
        $configValue = config("services.ksef.{$configKey}");

        if (is_string($configValue) && trim($configValue) !== '') {
            return trim($configValue);
        }

        $settingsValue = $this->settings->value($configKey);

        if ($settingsValue !== '') {
            return $settingsValue;
        }

        $envValue = env($envKey);

        return is_string($envValue) && trim($envValue) !== ''
            ? trim($envValue)
            : $default;
    }
}
