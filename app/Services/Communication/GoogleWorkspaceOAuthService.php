<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\GoogleMailConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleWorkspaceOAuthService
{
    public const GMAIL_SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    private const AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const REFRESH_LOCK = 'google-workspace-mail:refresh-token';

    public function __construct(
        private readonly MailSettingsService $mailSettings,
    ) {}

    public function clientConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        $storedClientId = trim((string) ($this->connection()?->client_id ?? ''));

        return $storedClientId !== ''
            ? $storedClientId
            : trim((string) config('services.google_workspace.client_id', ''));
    }

    public function updateClientCredentials(
        string $clientId,
        string $clientSecret = '',
        bool $clearClientSecret = false,
    ): GoogleMailConnection {
        $connection = $this->connection();
        $currentEffectiveClientId = $this->clientId();
        $currentEffectiveClientSecret = $this->clientSecret();
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $encryptedClientSecret = $connection?->client_secret_encrypted;

        if ($clientSecret !== '') {
            $encryptedClientSecret = Crypt::encryptString($clientSecret);
        } elseif ($clearClientSecret) {
            $encryptedClientSecret = null;
        }

        $effectiveClientId = $clientId !== ''
            ? $clientId
            : trim((string) config('services.google_workspace.client_id', ''));
        $storedSecret = $this->decryptClientSecret($encryptedClientSecret);
        $effectiveClientSecret = $storedSecret !== ''
            ? $storedSecret
            : trim((string) config('services.google_workspace.client_secret', ''));
        $credentialsChanged = ! hash_equals($currentEffectiveClientId, $effectiveClientId)
            || ! hash_equals($currentEffectiveClientSecret, $effectiveClientSecret);
        $updates = [
            'client_id' => $clientId !== '' ? $clientId : null,
            'client_secret_encrypted' => $encryptedClientSecret,
        ];

        if ($credentialsChanged) {
            $updates = array_merge($updates, $this->disconnectedAttributes());
        }

        return GoogleMailConnection::query()->updateOrCreate(
            ['purpose' => GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL],
            $updates,
        );
    }

    public function redirectUri(): string
    {
        return route('settings.mail.google.callback');
    }

    public function authorizationUrl(string $state): string
    {
        if (! $this->clientConfigured()) {
            throw new RuntimeException('Najpierw skonfiguruj identyfikator i sekret klienta Google OAuth.');
        }

        $settings = $this->mailSettings->data();
        $fromAddress = trim((string) ($settings['from_address'] ?? ''));
        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', ['openid', 'email', self::GMAIL_SEND_SCOPE]),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ];

        if ($fromAddress !== '') {
            $query['login_hint'] = $fromAddress;
            $domain = $this->emailDomain($fromAddress);

            if ($domain !== null) {
                $query['hd'] = $domain;
            }
        }

        return self::AUTHORIZATION_URL.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, int $connectedByUserId): GoogleMailConnection
    {
        if (! $this->clientConfigured()) {
            throw new RuntimeException('Konfiguracja klienta Google OAuth jest niekompletna.');
        }

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeout())
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->redirectUri(),
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Nie udało się połączyć z usługą logowania Google. Spróbuj ponownie.');
        }

        if (! $tokenResponse->successful()) {
            throw new RuntimeException($this->tokenErrorMessage($tokenResponse));
        }

        $accessToken = trim((string) $tokenResponse->json('access_token', ''));

        if ($accessToken === '') {
            throw new RuntimeException('Google OAuth nie zwrócił tokenu dostępu.');
        }

        $scopeString = trim((string) $tokenResponse->json('scope', ''));
        $scopes = $scopeString !== ''
            ? array_values(array_filter(preg_split('/\s+/', $scopeString) ?: []))
            : ['openid', 'email', self::GMAIL_SEND_SCOPE];

        if (! in_array(self::GMAIL_SEND_SCOPE, $scopes, true)) {
            throw new RuntimeException('Konto Google nie przyznało uprawnienia do wysyłania wiadomości.');
        }

        try {
            $profileResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout($this->timeout())
                ->get(self::USERINFO_URL);
        } catch (ConnectionException) {
            throw new RuntimeException('Nie udało się potwierdzić konta Google Workspace. Spróbuj ponownie.');
        }

        if (! $profileResponse->successful()) {
            throw new RuntimeException('Nie udało się potwierdzić konta Google Workspace.');
        }

        $email = mb_strtolower(trim((string) $profileResponse->json('email', '')));
        $subject = trim((string) $profileResponse->json('sub', ''));
        $hostedDomain = trim((string) $profileResponse->json('hd', ''));
        $emailVerified = $profileResponse->json('email_verified') === true;

        if ($email === '' || $subject === '' || ! $emailVerified || $hostedDomain === '') {
            throw new RuntimeException('Wybierz zweryfikowane konto należące do Google Workspace.');
        }

        $connection = $this->connection();
        $refreshToken = trim((string) $tokenResponse->json('refresh_token', ''));

        if ($refreshToken === '') {
            $refreshToken = $connection?->refreshToken() ?? '';
        }

        if ($refreshToken === '') {
            throw new RuntimeException('Google nie zwrócił dostępu offline. Połącz konto ponownie i zaakceptuj zgodę.');
        }

        $expiresIn = max(60, (int) $tokenResponse->json('expires_in', 3600));

        return GoogleMailConnection::query()->updateOrCreate(
            ['purpose' => GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL],
            [
                'google_subject' => $subject,
                'email' => $email,
                'access_token_encrypted' => Crypt::encryptString($accessToken),
                'refresh_token_encrypted' => Crypt::encryptString($refreshToken),
                'access_token_expires_at' => now()->addSeconds($expiresIn),
                'granted_scopes' => $scopes,
                'connected_by_user_id' => $connectedByUserId,
                'connected_at' => now(),
                'refreshed_at' => now(),
                'reauthorization_required_at' => null,
            ],
        );
    }

    public function accessToken(bool $forceRefresh = false): string
    {
        $connection = $this->connection();

        if (! $connection instanceof GoogleMailConnection || ! $connection->isUsable()) {
            throw new RuntimeException('Połączenie z Google Workspace wymaga ponownej autoryzacji.');
        }

        if (! $forceRefresh && $this->hasFreshAccessToken($connection)) {
            return (string) $connection->accessToken();
        }

        try {
            return Cache::lock(self::REFRESH_LOCK, 30)->block(10, function () use ($forceRefresh): string {
                $connection = $this->connection();

                if (! $connection instanceof GoogleMailConnection || ! $connection->isUsable()) {
                    throw new RuntimeException('Połączenie z Google Workspace wymaga ponownej autoryzacji.');
                }

                if (! $forceRefresh && $this->hasFreshAccessToken($connection)) {
                    return (string) $connection->accessToken();
                }

                return $this->refreshAccessToken($connection);
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Odświeżanie dostępu Google już trwa. Spróbuj ponownie za chwilę.');
        }
    }

    public function disconnect(): bool
    {
        $connection = $this->connection();

        if (! $connection instanceof GoogleMailConnection) {
            return true;
        }

        $token = $connection->refreshToken() ?? $connection->accessToken();
        $revoked = true;

        if ($token !== null) {
            try {
                $revoked = Http::asForm()
                    ->timeout($this->timeout())
                    ->post(self::REVOKE_URL, ['token' => $token])
                    ->successful();
            } catch (\Throwable) {
                $revoked = false;
            }
        }

        $connection->forceFill($this->disconnectedAttributes())->save();

        return $revoked;
    }

    private function refreshAccessToken(GoogleMailConnection $connection): string
    {
        $refreshToken = $connection->refreshToken();

        if ($refreshToken === null || ! $this->clientConfigured()) {
            $this->markReauthorizationRequired($connection);

            throw new RuntimeException('Połączenie z Google Workspace wymaga ponownej autoryzacji.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeout())
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Nie udało się odświeżyć dostępu Google Workspace. Spróbuj ponownie.');
        }

        if (! $response->successful()) {
            if ((string) $response->json('error') === 'invalid_grant') {
                $this->markReauthorizationRequired($connection);

                throw new RuntimeException('Dostęp Google Workspace wygasł lub został cofnięty. Połącz konto ponownie.');
            }

            throw new RuntimeException($this->tokenErrorMessage($response));
        }

        $accessToken = trim((string) $response->json('access_token', ''));

        if ($accessToken === '') {
            throw new RuntimeException('Google OAuth nie zwrócił nowego tokenu dostępu.');
        }

        $rotatedRefreshToken = trim((string) $response->json('refresh_token', ''));
        $expiresIn = max(60, (int) $response->json('expires_in', 3600));
        $updates = [
            'access_token_encrypted' => Crypt::encryptString($accessToken),
            'access_token_expires_at' => now()->addSeconds($expiresIn),
            'refreshed_at' => now(),
            'reauthorization_required_at' => null,
        ];

        if ($rotatedRefreshToken !== '') {
            $updates['refresh_token_encrypted'] = Crypt::encryptString($rotatedRefreshToken);
        }

        $scopeString = trim((string) $response->json('scope', ''));

        if ($scopeString !== '') {
            $updates['granted_scopes'] = array_values(array_filter(preg_split('/\s+/', $scopeString) ?: []));
        }

        $connection->forceFill($updates)->save();

        return $accessToken;
    }

    private function hasFreshAccessToken(GoogleMailConnection $connection): bool
    {
        return $connection->accessToken() !== null
            && $connection->access_token_expires_at?->isAfter(now()->addMinute()) === true;
    }

    private function markReauthorizationRequired(GoogleMailConnection $connection): void
    {
        $connection->forceFill([
            'access_token_encrypted' => null,
            'access_token_expires_at' => null,
            'reauthorization_required_at' => now(),
        ])->save();
    }

    private function connection(): ?GoogleMailConnection
    {
        return GoogleMailConnection::query()
            ->where('purpose', GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL)
            ->first();
    }

    private function clientSecret(): string
    {
        $storedClientSecret = $this->connection()?->clientSecret();

        return $storedClientSecret !== null
            ? trim($storedClientSecret)
            : trim((string) config('services.google_workspace.client_secret', ''));
    }

    private function decryptClientSecret(mixed $encrypted): string
    {
        if (! filled($encrypted)) {
            return '';
        }

        try {
            return trim(Crypt::decryptString((string) $encrypted));
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array<string, mixed> */
    private function disconnectedAttributes(): array
    {
        return [
            'google_subject' => null,
            'email' => null,
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
            'access_token_expires_at' => null,
            'granted_scopes' => null,
            'connected_by_user_id' => null,
            'connected_at' => null,
            'refreshed_at' => null,
            'reauthorization_required_at' => null,
        ];
    }

    private function timeout(): int
    {
        return max(3, min(120, (int) ($this->mailSettings->data()['timeout'] ?? 15)));
    }

    private function tokenErrorMessage(Response $response): string
    {
        return match ((string) $response->json('error', '')) {
            'invalid_client' => 'Dane klienta Google OAuth są nieprawidłowe.',
            'invalid_grant' => 'Kod lub dostęp Google wygasł. Rozpocznij łączenie konta ponownie.',
            'redirect_uri_mismatch' => 'Adres callback Google OAuth nie zgadza się z konfiguracją klienta.',
            default => 'Google OAuth zwrócił błąd (HTTP '.$response->status().').',
        };
    }

    private function emailDomain(string $email): ?string
    {
        if (! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));

        return $domain !== '' ? $domain : null;
    }
}
