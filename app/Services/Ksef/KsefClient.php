<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\KsefSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class KsefClient
{
    public function __construct(
        private readonly KsefSettingsService $settings,
        private readonly KsefCryptoService $crypto,
    ) {}

    public function configurationStatus(): array
    {
        $environment = $this->environment();
        $hasAccessToken = $this->ksefToken() !== '';
        $hasGatewayUrl = $this->gatewayUrl() !== '';
        $statusUrl = $this->statusUrl();
        $nativeReady = $hasAccessToken;

        return [
            'environment' => $environment,
            'api_version' => $this->apiVersion(),
            'base_url' => $this->baseUrl($environment),
            'delivery_mode' => $hasGatewayUrl ? 'gateway' : 'native',
            'status_url' => $statusUrl,
            'public_key_id' => $this->publicKeyId($environment),
            'public_key_sha256' => $this->publicKeySha256($environment),
            'has_access_token' => $hasAccessToken,
            'has_gateway_url' => $hasGatewayUrl,
            'has_status_url' => $statusUrl !== '',
            'has_public_key' => $this->publicKeyId($environment) !== '' && $this->publicKeySha256($environment) !== '',
            'native_online_send_ready' => $nativeReady,
            'direct_online_send_ready' => $nativeReady || ($hasAccessToken && $hasGatewayUrl),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function send(KsefSubmission $submission): array
    {
        $token = $this->ksefToken();

        if ($token === '') {
            throw new RuntimeException('Brak tokena dostępu KSeF. Uzupełnij token KSeF w konfiguracji integracji.');
        }

        $gatewayUrl = $this->gatewayUrl();

        if ($gatewayUrl !== '') {
            return $this->sendViaGateway($submission, $token, $gatewayUrl);
        }

        return $this->sendNative($submission, $token);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(KsefSubmission $submission): array
    {
        $token = $this->ksefToken();

        if ($token === '') {
            throw new RuntimeException('Brak tokena dostępu KSeF. Nie można sprawdzić statusu zgłoszenia.');
        }

        if (! filled($submission->reference_number)) {
            throw new RuntimeException('Zgłoszenie KSeF nie ma numeru referencyjnego faktury, więc nie można sprawdzić jego statusu.');
        }

        $statusUrl = $this->statusUrl($submission);

        if ($statusUrl !== '') {
            return $this->checkGatewayStatus($submission, $token, $statusUrl);
        }

        return $this->checkNativeStatus($submission, $token);
    }

    public function environment(): string
    {
        $environment = strtolower($this->setting('environment', 'test'));

        return in_array($environment, ['production', 'prod', 'demo', 'test'], true)
            ? $environment
            : 'test';
    }

    public function apiVersion(): string
    {
        return $this->setting('api_version', '2.6.0');
    }

    public function baseUrl(?string $environment = null): string
    {
        $configured = $this->setting('base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return match ($environment ?? $this->environment()) {
            'production', 'prod' => 'https://api.ksef.mf.gov.pl/v2',
            'demo' => 'https://api-demo.ksef.mf.gov.pl/v2',
            default => 'https://api-test.ksef.mf.gov.pl/v2',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sendViaGateway(KsefSubmission $submission, string $token, string $gatewayUrl): array
    {
        $idempotencyKey = 'sempre-ksef-'.$submission->id.'-'.hash('sha256', (string) $submission->xml_payload);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->timeout(60)
                ->post($gatewayUrl, [
                    'environment' => $submission->environment,
                    'api_version' => $submission->api_version,
                    'invoice_id' => $submission->invoice_id,
                    'submission_id' => $submission->id,
                    'idempotency_key' => $idempotencyKey,
                    'invoice_xml' => $submission->xml_payload,
                    'invoice_hash_sha256' => base64_encode(hash('sha256', (string) $submission->xml_payload, true)),
                    'invoice_size' => strlen((string) $submission->xml_payload),
                    'public_key_id' => $this->publicKeyId($submission->environment),
                    'public_key_sha256' => $this->publicKeySha256($submission->environment),
                ]);
        } catch (ConnectionException $exception) {
            throw new KsefDeliveryUncertainException(
                'Połączenie z bramką KSeF zostało przerwane podczas wysyłki faktury. Nie ponawiaj automatycznie; sprawdź zgłoszenie w bramce lub KSeF.',
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new RuntimeException("Bramka KSeF zwróciła HTTP {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendNative(KsefSubmission $submission, string $ksefToken): array
    {
        $context = $this->contextIdentifier($submission);
        $baseUrl = $this->baseUrl($submission->environment);
        $certificates = $this->publicKeyCertificates($baseUrl);
        $tokenCertificate = $this->selectPublicKeyCertificate($certificates, 'KsefTokenEncryption');
        $sessionCertificate = $this->selectPublicKeyCertificate($certificates, 'SymmetricKeyEncryption');
        $auth = $this->authenticateWithKsefToken($baseUrl, $ksefToken, $context, $tokenCertificate);
        $accessToken = (string) data_get($auth, 'accessToken.token', data_get($auth, 'access_token', ''));

        if ($accessToken === '') {
            throw new RuntimeException('KSeF nie zwrócił tokena dostępowego po uwierzytelnieniu.');
        }

        $encryptionData = $this->crypto->encryptionData(
            (string) $sessionCertificate['certificate'],
            (string) $sessionCertificate['publicKeyId'],
        );
        $session = $this->openOnlineSession($baseUrl, $accessToken, $encryptionData);
        $sessionReferenceNumber = (string) data_get($session, 'referenceNumber', '');

        if ($sessionReferenceNumber === '') {
            throw new RuntimeException('KSeF nie zwrócił numeru referencyjnego sesji online.');
        }

        $invoice = $this->sendOnlineSessionInvoice($baseUrl, $accessToken, $sessionReferenceNumber, (string) $submission->xml_payload, $encryptionData);
        $invoiceReferenceNumber = (string) data_get($invoice, 'referenceNumber', '');
        $response = [
            'mode' => 'native',
            'environment' => $submission->environment,
            'api_version' => $submission->api_version,
            'referenceNumber' => (string) data_get($invoice, 'referenceNumber', ''),
            'sessionReferenceNumber' => $sessionReferenceNumber,
            'sessionValidUntil' => data_get($session, 'validUntil'),
            'tokenPublicKeyId' => $tokenCertificate['publicKeyId'],
            'symmetricKeyPublicKeyId' => $sessionCertificate['publicKeyId'],
            'authenticationReferenceNumber' => data_get($auth, 'referenceNumber'),
            'authenticationStatus' => data_get($auth, 'status'),
            'invoice' => $invoice,
            'session' => $session,
        ];

        if ($invoiceReferenceNumber === '') {
            $submission->update([
                'status' => 'delivery_uncertain',
                'response_metadata' => array_merge((array) $submission->response_metadata, $response),
                'last_error' => 'KSeF przyjął żądanie wysyłki, ale nie zwrócił numeru referencyjnego faktury.',
            ]);

            throw new RuntimeException('KSeF nie zwrócił numeru referencyjnego wysłanej faktury. Nie ponawiaj automatycznie wysyłki.');
        }

        // Persist the external acknowledgement before closing the online session.
        // A close failure must never make the invoice eligible for another POST.
        $submission->update([
            'status' => 'submitted',
            'reference_number' => $invoiceReferenceNumber,
            'response_metadata' => array_merge((array) $submission->response_metadata, $response),
            'submitted_at' => now(),
        ]);

        try {
            $this->closeOnlineSession($baseUrl, $accessToken, $sessionReferenceNumber);
            $response['sessionClose'] = ['status' => 'success'];
        } catch (RuntimeException $exception) {
            $response['sessionClose'] = [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
            $submission->update([
                'response_metadata' => array_merge((array) $submission->response_metadata, $response),
                'last_error' => 'Faktura została wysłana, ale zamknięcie sesji KSeF nie powiodło się: '.$exception->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkGatewayStatus(KsefSubmission $submission, string $token, string $statusUrl): array
    {
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

    /**
     * @return array<string, mixed>
     */
    private function checkNativeStatus(KsefSubmission $submission, string $ksefToken): array
    {
        $context = $this->contextIdentifier($submission);
        $baseUrl = $this->baseUrl($submission->environment);
        $tokenCertificate = $this->selectPublicKeyCertificate($this->publicKeyCertificates($baseUrl), 'KsefTokenEncryption');
        $auth = $this->authenticateWithKsefToken($baseUrl, $ksefToken, $context, $tokenCertificate);
        $accessToken = (string) data_get($auth, 'accessToken.token', data_get($auth, 'access_token', ''));

        if ($accessToken === '') {
            throw new RuntimeException('KSeF nie zwrócił tokena dostępowego do sprawdzenia statusu.');
        }

        $sessionReferenceNumber = (string) data_get($submission->response_metadata, 'sessionReferenceNumber', data_get($submission->response_metadata, 'session.referenceNumber', ''));

        if ($sessionReferenceNumber === '') {
            throw new RuntimeException('Zgłoszenie KSeF nie ma numeru sesji online, więc nie można pobrać natywnego statusu.');
        }

        $invoiceReferenceNumber = (string) $submission->reference_number;
        $response = $this->getJson(
            $baseUrl.'/sessions/'.rawurlencode($sessionReferenceNumber).'/invoices/'.rawurlencode($invoiceReferenceNumber),
            $accessToken,
            'status faktury KSeF',
        );

        $response['mode'] = 'native';
        $response['sessionReferenceNumber'] = $sessionReferenceNumber;
        $response['authenticationReferenceNumber'] = data_get($auth, 'referenceNumber');
        $response['authenticationStatus'] = data_get($auth, 'status');

        return $response;
    }

    private function publicKeyId(?string $environment = null): string
    {
        return $this->setting(
            'public_key_id',
            $this->defaultPublicKeyId($environment ?? $this->environment()),
        );
    }

    private function publicKeySha256(?string $environment = null): string
    {
        return strtolower($this->setting(
            'public_key_sha256',
            $this->defaultPublicKeySha256($environment ?? $this->environment()),
        ));
    }

    private function ksefToken(): string
    {
        return $this->normalizeKsefToken($this->setting('access_token', ''));
    }

    private function normalizeKsefToken(string $token): string
    {
        $token = preg_replace('/^Bearer\s+/i', '', trim($token)) ?? '';

        return preg_replace('/\s+/', '', $token) ?? '';
    }

    private function gatewayUrl(): string
    {
        return $this->setting('gateway_url', '');
    }

    private function statusUrl(?KsefSubmission $submission = null): string
    {
        $stored = $this->setting('status_url', '');
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

    /**
     * @return array{type:string,value:string}
     */
    private function contextIdentifier(KsefSubmission $submission): array
    {
        $type = $this->setting('context_identifier_type', 'Nip');
        $value = $this->setting('context_identifier_value', '');

        if ($value === '') {
            $submission->loadMissing('invoice');
            $value = preg_replace('/\D+/', '', (string) data_get($submission->invoice?->seller_data, 'tax_id', '')) ?? '';
        }

        if ($value === '') {
            throw new RuntimeException('Brak NIP sprzedawcy albo KSEF_CONTEXT_IDENTIFIER_VALUE do uwierzytelnienia KSeF.');
        }

        return [
            'type' => $type !== '' ? $type : 'Nip',
            'value' => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticateWithKsefToken(string $baseUrl, string $ksefToken, array $context, array $tokenCertificate): array
    {
        $challenge = $this->postJson($baseUrl.'/auth/challenge', null, [], 'pobranie challenge KSeF');
        $challengeValue = (string) data_get($challenge, 'challenge', '');
        $timestampMs = data_get($challenge, 'timestampMs');

        if ($timestampMs === null || $timestampMs === '') {
            $timestamp = (string) data_get($challenge, 'timestamp', '');
            $timestampMs = $timestamp !== '' ? Carbon::parse($timestamp)->getTimestampMs() : null;
        }

        if ($challengeValue === '' || $timestampMs === null || $timestampMs === '') {
            throw new RuntimeException('KSeF nie zwrócił poprawnego challenge albo timestampMs.');
        }

        $encryptedToken = $this->crypto->encryptKsefToken(
            $ksefToken,
            is_numeric($timestampMs) ? (int) $timestampMs : (string) $timestampMs,
            (string) $tokenCertificate['certificate'],
        );
        $init = $this->postJson($baseUrl.'/auth/ksef-token', null, [
            'challenge' => $challengeValue,
            'contextIdentifier' => $context,
            'encryptedToken' => $encryptedToken,
            'publicKeyId' => $tokenCertificate['publicKeyId'],
        ], 'uwierzytelnienie tokenem KSeF');
        $authToken = (string) data_get($init, 'authenticationToken.token', '');
        $referenceNumber = (string) data_get($init, 'referenceNumber', '');

        if ($authToken === '' || $referenceNumber === '') {
            throw new RuntimeException('KSeF nie zwrócił tokena operacji uwierzytelnienia albo numeru referencyjnego.');
        }

        $status = $this->waitForAuthenticationStatus($baseUrl, $referenceNumber, $authToken);
        $tokens = $this->postJson($baseUrl.'/auth/token/redeem', $authToken, [], 'pobranie tokena dostępowego KSeF');

        return array_merge($tokens, [
            'referenceNumber' => $referenceNumber,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForAuthenticationStatus(string $baseUrl, string $referenceNumber, string $authToken): array
    {
        $attempts = max(1, (int) config('services.ksef.auth_status_attempts', 6));
        $delayMs = max(0, (int) config('services.ksef.auth_status_delay_ms', 500));
        $lastStatus = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $lastStatus = $this->getJson(
                $baseUrl.'/auth/'.rawurlencode($referenceNumber),
                $authToken,
                'status uwierzytelnienia KSeF',
            );
            $code = (int) data_get($lastStatus, 'status.code', 0);

            if ($code === 200) {
                return $lastStatus;
            }

            if ($code >= 400) {
                throw new RuntimeException($this->statusMessage('Uwierzytelnianie KSeF zakończone niepowodzeniem', (array) data_get($lastStatus, 'status', [])));
            }
        }

        throw new RuntimeException($this->statusMessage('Uwierzytelnianie KSeF nadal jest w toku', (array) data_get($lastStatus, 'status', [])));
    }

    /**
     * @param  array<string, mixed>  $encryptionData
     * @return array<string, mixed>
     */
    private function openOnlineSession(string $baseUrl, string $accessToken, array $encryptionData): array
    {
        return $this->postJson($baseUrl.'/sessions/online', $accessToken, [
            'formCode' => [
                'systemCode' => KsefXmlBuilder::FORM_SYSTEM_CODE,
                'schemaVersion' => KsefXmlBuilder::SCHEMA_VERSION,
                'value' => 'FA',
            ],
            'encryption' => [
                'encryptedSymmetricKey' => $encryptionData['encrypted_symmetric_key'],
                'initializationVector' => $encryptionData['initialization_vector'],
                'publicKeyId' => $encryptionData['public_key_id'],
            ],
        ], 'otwarcie sesji online KSeF');
    }

    /**
     * @param  array<string, mixed>  $encryptionData
     * @return array<string, mixed>
     */
    private function sendOnlineSessionInvoice(string $baseUrl, string $accessToken, string $sessionReferenceNumber, string $xml, array $encryptionData): array
    {
        $invoiceMetadata = $this->crypto->metadata($xml);
        $encryptedInvoice = $this->crypto->encryptInvoice($xml, $encryptionData);

        return $this->postJson(
            $baseUrl.'/sessions/online/'.rawurlencode($sessionReferenceNumber).'/invoices',
            $accessToken,
            [
                'invoiceHash' => $invoiceMetadata['hash'],
                'invoiceSize' => $invoiceMetadata['size'],
                'encryptedInvoiceHash' => $encryptedInvoice['hash'],
                'encryptedInvoiceSize' => $encryptedInvoice['size'],
                'encryptedInvoiceContent' => $encryptedInvoice['content'],
                'offlineMode' => false,
            ],
            'wysłanie faktury do sesji online KSeF',
            deliveryMayBeUncertain: true,
        );
    }

    private function closeOnlineSession(string $baseUrl, string $accessToken, string $sessionReferenceNumber): void
    {
        $response = Http::withToken($accessToken)
            ->withHeaders(['X-Error-Format' => 'problem-details'])
            ->acceptJson()
            ->timeout(60)
            ->post($baseUrl.'/sessions/online/'.rawurlencode($sessionReferenceNumber).'/close');

        if ($response->failed()) {
            throw new RuntimeException($this->httpErrorMessage($response, 'zamknięcie sesji online KSeF'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function publicKeyCertificates(string $baseUrl): array
    {
        $response = Http::acceptJson()
            ->withHeaders(['X-Error-Format' => 'problem-details'])
            ->timeout(30)
            ->get($baseUrl.'/security/public-key-certificates');

        if ($response->failed()) {
            throw new RuntimeException($this->httpErrorMessage($response, 'pobranie certyfikatów klucza publicznego KSeF'));
        }

        $certificates = $response->json();
        if (! is_array($certificates)) {
            throw new RuntimeException('KSeF zwrócił nieprawidłową listę certyfikatów klucza publicznego.');
        }

        return array_values(array_filter($certificates, fn ($certificate): bool => is_array($certificate)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $certificates
     * @return array<string, mixed>
     */
    private function selectPublicKeyCertificate(array $certificates, string $usage): array
    {
        $preferredId = $this->publicKeyId();
        $valid = collect($certificates)
            ->filter(fn (array $certificate): bool => in_array($usage, (array) ($certificate['usage'] ?? []), true)
                && $this->certificateIsCurrentlyValid($certificate))
            ->values();

        $preferred = $valid->first(fn (array $certificate): bool => $preferredId !== ''
            && (string) ($certificate['publicKeyId'] ?? '') === $preferredId);

        $selected = $preferred ?? $valid
            ->sortByDesc(fn (array $certificate): int => strtotime((string) ($certificate['validFrom'] ?? '')) ?: 0)
            ->first();

        if (! is_array($selected)) {
            throw new RuntimeException("Brak aktualnego certyfikatu KSeF dla użycia {$usage}.");
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $certificate
     */
    private function certificateIsCurrentlyValid(array $certificate): bool
    {
        $now = now()->getTimestamp();
        $validFrom = strtotime((string) ($certificate['validFrom'] ?? '')) ?: null;
        $validTo = strtotime((string) ($certificate['validTo'] ?? '')) ?: null;

        return ($validFrom === null || $validFrom <= $now)
            && ($validTo === null || $validTo >= $now)
            && filled($certificate['certificate'] ?? null)
            && filled($certificate['publicKeyId'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postJson(
        string $url,
        ?string $bearerToken,
        array $payload,
        string $operation,
        bool $deliveryMayBeUncertain = false,
    ): array {
        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders(['X-Error-Format' => 'problem-details'])
            ->timeout(60);

        if ($bearerToken !== null) {
            $request = $request->withToken($bearerToken);
        }

        try {
            $response = $request->post($url, $payload);
        } catch (ConnectionException $exception) {
            if ($deliveryMayBeUncertain) {
                throw new KsefDeliveryUncertainException(
                    'Połączenie z KSeF zostało przerwane podczas wysyłki faktury do sesji. Nie ponawiaj automatycznie; sprawdź fakturę w KSeF.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        if ($response->failed()) {
            if ($deliveryMayBeUncertain && $response->serverError()) {
                throw new KsefDeliveryUncertainException(
                    $this->httpErrorMessage($response, $operation).' Wynik przyjęcia faktury jest niepewny; nie ponawiaj automatycznie.',
                );
            }

            throw new RuntimeException($this->httpErrorMessage($response, $operation));
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url, string $bearerToken, string $operation): array
    {
        $response = Http::withToken($bearerToken)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Error-Format' => 'problem-details'])
            ->timeout(60)
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException($this->httpErrorMessage($response, $operation));
        }

        return $response->json() ?? [];
    }

    private function httpErrorMessage(Response $response, string $operation): string
    {
        $body = $response->json();
        $message = '';

        if (is_array($body)) {
            $message = implode(' ', array_filter([
                data_get($body, 'title'),
                data_get($body, 'detail'),
                data_get($body, 'message'),
                data_get($body, 'exception.exceptionDescription'),
                data_get($body, 'exceptionDescription'),
                $this->flattenDetails(data_get($body, 'details')),
                $this->flattenDetails(data_get($body, 'errors')),
            ], fn ($part): bool => is_scalar($part) && trim((string) $part) !== ''));
        }

        if ($message === '') {
            $message = trim($response->body());
        }

        $suffix = $message !== '' ? ': '.$message : '.';

        return "KSeF {$operation} zwrócił HTTP {$response->status()}{$suffix}";
    }

    private function statusMessage(string $prefix, array $status): string
    {
        return implode(': ', array_filter([
            $prefix,
            trim(implode(' ', array_filter([
                data_get($status, 'code'),
                data_get($status, 'description'),
                $this->flattenDetails(data_get($status, 'details')),
            ], fn ($part): bool => is_scalar($part) && trim((string) $part) !== ''))),
        ], fn (string $part): bool => $part !== ''));
    }

    private function flattenDetails(mixed $details): string
    {
        if (is_string($details)) {
            return $details;
        }

        if (! is_array($details)) {
            return '';
        }

        return collect($details)
            ->flatten()
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value): string => (string) $value)
            ->implode(' ');
    }

    private function defaultPublicKeyId(string $environment): string
    {
        return $environment === 'test' ? KsefSettingsService::TEST_PUBLIC_KEY_ID : '';
    }

    private function defaultPublicKeySha256(string $environment): string
    {
        return $environment === 'test' ? KsefSettingsService::TEST_PUBLIC_KEY_SHA256 : '';
    }

    private function setting(string $configKey, string $default): string
    {
        $configValue = config("services.ksef.{$configKey}");

        if (is_string($configValue) && trim($configValue) !== '') {
            return trim($configValue);
        }

        $settingsValue = $this->settings->value($configKey);

        if ($settingsValue !== '') {
            return $settingsValue;
        }

        return $default;
    }
}
