<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\GoogleMailConnection;
use App\Services\Communication\GoogleWorkspaceOAuthService;
use App\Services\Communication\MailSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GoogleWorkspaceMailOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google_workspace.client_id', null);
        config()->set('services.google_workspace.client_secret', null);
    }

    public function test_google_client_credentials_can_be_saved_without_exposing_the_secret_in_html(): void
    {
        $clientSecret = 'google-client-secret-that-must-stay-private';

        $this->put(route('settings.mail.update'), $this->mailSettingsPayload([
            'delivery_method' => MailSettingsService::DELIVERY_GOOGLE_WORKSPACE,
            'google_client_id' => 'erp-google-client.apps.googleusercontent.com',
            'google_client_secret' => $clientSecret,
        ]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $connection = GoogleMailConnection::query()
            ->where('purpose', GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL)
            ->firstOrFail();

        $this->assertSame('erp-google-client.apps.googleusercontent.com', $connection->client_id);
        $this->assertNotSame($clientSecret, $connection->client_secret_encrypted);
        $this->assertSame($clientSecret, Crypt::decryptString((string) $connection->client_secret_encrypted));
        $this->assertSame($clientSecret, $connection->clientSecret());

        $this->get(route('settings.mail'))
            ->assertOk()
            ->assertSee('erp-google-client.apps.googleusercontent.com')
            ->assertDontSee($clientSecret, false);
    }

    public function test_connect_action_saves_new_credentials_and_immediately_starts_oauth(): void
    {
        $clientId = 'erp-google-client.apps.googleusercontent.com';
        $clientSecret = 'google-client-secret-entered-in-the-form';

        $response = $this->put(route('settings.mail.update'), $this->mailSettingsPayload([
            'enabled' => '1',
            'delivery_method' => MailSettingsService::DELIVERY_GOOGLE_WORKSPACE,
            'google_client_id' => $clientId,
            'google_client_secret' => $clientSecret,
            'connect_google' => '1',
        ]));

        $response->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
        $location = (string) $response->headers->get('Location');
        $query = $this->oauthRedirectQuery($location);

        $this->assertSame($clientId, $query['client_id']);
        $this->assertSame(route('settings.mail.google.callback'), $query['redirect_uri']);
        $this->assertSame('powiadomienia@example.test', $query['login_hint']);

        $connection = GoogleMailConnection::query()
            ->where('purpose', GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL)
            ->firstOrFail();

        $this->assertSame($clientId, $connection->client_id);
        $this->assertSame($clientSecret, $connection->clientSecret());

        $savedSettings = AppSetting::query()->where('key', 'mail_settings')->firstOrFail()->value;
        $this->assertSame(MailSettingsService::DELIVERY_GOOGLE_WORKSPACE, $savedSettings['delivery_method']);
        $this->assertTrue($savedSettings['delivery_enabled']);
        $this->assertSame('powiadomienia@example.test', $savedSettings['from_address']);

        $pendingState = session('google_workspace_mail_oauth_state');
        $this->assertIsArray($pendingState);
        $this->assertSame(hash('sha256', $query['state']), $pendingState['hash']);
        $this->assertSame((int) auth()->id(), $pendingState['user_id']);
    }

    public function test_connect_button_submits_the_settings_form_before_credentials_are_stored(): void
    {
        $response = $this->get(route('settings.mail'));
        $response->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML((string) $response->getContent());
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query(
            '//form[@data-mail-settings-form]//button[@type="submit"][@name="connect_google"][@value="1"]',
        );

        $this->assertNotFalse($buttons);
        $this->assertCount(1, $buttons, 'Przycisk „Połącz z Google” musi wysyłać główny formularz z wpisanymi danymi OAuth.');
        $this->assertFalse(
            $buttons->item(0)?->hasAttribute('disabled') ?? true,
            'Przycisk „Połącz z Google” nie może być zablokowany przed pierwszym zapisaniem Client ID i Client Secret.',
        );
    }

    public function test_switching_to_google_workspace_preserves_the_existing_smtp_configuration(): void
    {
        app(MailSettingsService::class)->update([
            'enabled' => true,
            'delivery_method' => MailSettingsService::DELIVERY_SMTP,
            'host' => 'smtp.previous.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'previous-smtp@example.test',
            'password' => 'previous-smtp-password',
            'from_address' => 'powiadomienia@example.test',
            'from_name' => 'Sempre ERP',
            'reply_to_address' => 'kontakt@example.test',
            'ehlo_domain' => 'erp.example.test',
            'timeout' => 25,
        ]);

        $before = AppSetting::query()->where('key', 'mail_settings')->firstOrFail()->value;

        $this->put(route('settings.mail.update'), $this->mailSettingsPayload([
            'enabled' => '1',
            'delivery_method' => MailSettingsService::DELIVERY_GOOGLE_WORKSPACE,
            'host' => 'smtp.previous.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'previous-smtp@example.test',
            'from_address' => 'powiadomienia@example.test',
            'from_name' => 'Sempre ERP',
            'reply_to_address' => 'kontakt@example.test',
            'ehlo_domain' => 'erp.example.test',
            'timeout' => 25,
            'google_client_id' => 'erp-google-client.apps.googleusercontent.com',
            'google_client_secret' => 'new-google-client-secret',
        ]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $after = AppSetting::query()->where('key', 'mail_settings')->firstOrFail()->value;

        $this->assertSame(MailSettingsService::DELIVERY_GOOGLE_WORKSPACE, $after['delivery_method']);
        $this->assertTrue($after['delivery_enabled']);
        $this->assertFalse($after['enabled']);
        $this->assertSame($before['host'], $after['host']);
        $this->assertSame($before['port'], $after['port']);
        $this->assertSame($before['encryption'], $after['encryption']);
        $this->assertSame($before['username'], $after['username']);
        $this->assertSame($before['password_encrypted'], $after['password_encrypted']);
        $this->assertSame($before['ehlo_domain'], $after['ehlo_domain']);
    }

    public function test_connect_redirect_contains_a_one_time_state_and_required_google_oauth_parameters(): void
    {
        $this->storeOAuthCredentials();
        $this->storeSenderAddress();
        $userId = (int) auth()->id();

        $response = $this->post(route('settings.mail.google.connect'));
        $location = (string) $response->headers->get('Location');
        $query = $this->oauthRedirectQuery($location);

        $response->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
        $this->assertSame('erp-google-client.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame(route('settings.mail.google.callback'), $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('true', $query['include_granted_scopes']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('powiadomienia@example.test', $query['login_hint']);
        $this->assertSame('example.test', $query['hd']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $query['state']);
        $this->assertEqualsCanonicalizing(
            ['openid', 'email', GoogleWorkspaceOAuthService::GMAIL_SEND_SCOPE],
            preg_split('/\s+/', $query['scope']) ?: [],
        );

        $pendingState = session('google_workspace_mail_oauth_state');
        $this->assertIsArray($pendingState);
        $this->assertSame(hash('sha256', $query['state']), $pendingState['hash']);
        $this->assertNotSame($query['state'], $pendingState['hash']);
        $this->assertSame($userId, $pendingState['user_id']);
        $this->assertGreaterThan(now()->getTimestamp(), $pendingState['expires_at']);
        $this->assertLessThanOrEqual(now()->addMinutes(10)->getTimestamp(), $pendingState['expires_at']);
    }

    public function test_callback_with_valid_state_stores_encrypted_tokens_from_google(): void
    {
        $this->storeOAuthCredentials();
        $this->storeSenderAddress();

        $connectResponse = $this->post(route('settings.mail.google.connect'));
        $state = $this->oauthRedirectQuery((string) $connectResponse->headers->get('Location'))['state'];

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => 'openid email '.GoogleWorkspaceOAuthService::GMAIL_SEND_SCOPE,
                'token_type' => 'Bearer',
            ]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-workspace-subject',
                'email' => 'powiadomienia@example.test',
                'email_verified' => true,
                'hd' => 'example.test',
            ]),
        ]);

        $this->get(route('settings.mail.google.callback', [
            'state' => $state,
            'code' => 'single-use-authorization-code',
        ]))
            ->assertRedirect(route('settings.mail'))
            ->assertSessionHas('status');

        $connection = GoogleMailConnection::query()
            ->where('purpose', GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL)
            ->firstOrFail();

        $this->assertSame('erp-google-client.apps.googleusercontent.com', $connection->client_id);
        $this->assertSame('google-client-secret', $connection->clientSecret());
        $this->assertSame('google-workspace-subject', $connection->google_subject);
        $this->assertSame('powiadomienia@example.test', $connection->email);
        $this->assertNotSame('google-access-token', $connection->access_token_encrypted);
        $this->assertNotSame('google-refresh-token', $connection->refresh_token_encrypted);
        $this->assertSame('google-access-token', Crypt::decryptString((string) $connection->access_token_encrypted));
        $this->assertSame('google-refresh-token', Crypt::decryptString((string) $connection->refresh_token_encrypted));
        $this->assertSame('google-access-token', $connection->accessToken());
        $this->assertSame('google-refresh-token', $connection->refreshToken());
        $this->assertSame((int) auth()->id(), $connection->connected_by_user_id);
        $this->assertEqualsCanonicalizing(
            ['openid', 'email', GoogleWorkspaceOAuthService::GMAIL_SEND_SCOPE],
            $connection->granted_scopes,
        );
        $this->assertNotNull($connection->connected_at);
        $this->assertNotNull($connection->access_token_expires_at);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->method() === 'POST'
            && data_get($request->data(), 'client_id') === 'erp-google-client.apps.googleusercontent.com'
            && data_get($request->data(), 'client_secret') === 'google-client-secret'
            && data_get($request->data(), 'code') === 'single-use-authorization-code'
            && data_get($request->data(), 'grant_type') === 'authorization_code'
            && data_get($request->data(), 'redirect_uri') === route('settings.mail.google.callback'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://openidconnect.googleapis.com/v1/userinfo'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer google-access-token'));
    }

    public function test_callback_with_invalid_state_does_not_contact_google(): void
    {
        $this->storeOAuthCredentials();
        $this->storeSenderAddress();

        $this->post(route('settings.mail.google.connect'))
            ->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');

        Http::fake();

        $this->get(route('settings.mail.google.callback', [
            'state' => str_repeat('0', 64),
            'code' => 'authorization-code-that-must-not-be-used',
        ]))
            ->assertRedirect(route('settings.mail'))
            ->assertSessionHas('error');

        Http::assertNothingSent();

        $connection = GoogleMailConnection::query()
            ->where('purpose', GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL)
            ->firstOrFail();
        $this->assertNull($connection->access_token_encrypted);
        $this->assertNull($connection->refresh_token_encrypted);
    }

    public function test_disconnect_clears_google_tokens_but_keeps_client_credentials(): void
    {
        GoogleMailConnection::query()->create([
            'purpose' => GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL,
            'client_id' => 'erp-google-client.apps.googleusercontent.com',
            'client_secret_encrypted' => Crypt::encryptString('google-client-secret'),
            'google_subject' => 'google-workspace-subject',
            'email' => 'powiadomienia@example.test',
            'access_token_encrypted' => Crypt::encryptString('google-access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('google-refresh-token'),
            'access_token_expires_at' => now()->addHour(),
            'granted_scopes' => [GoogleWorkspaceOAuthService::GMAIL_SEND_SCOPE],
            'connected_by_user_id' => auth()->id(),
            'connected_at' => now(),
            'refreshed_at' => now(),
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $this->delete(route('settings.mail.google.disconnect'))
            ->assertRedirect(route('settings.mail'))
            ->assertSessionHas('status');

        $connection = GoogleMailConnection::query()
            ->where('purpose', GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL)
            ->firstOrFail();

        $this->assertSame('erp-google-client.apps.googleusercontent.com', $connection->client_id);
        $this->assertSame('google-client-secret', $connection->clientSecret());
        $this->assertNull($connection->google_subject);
        $this->assertNull($connection->email);
        $this->assertNull($connection->access_token_encrypted);
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertNull($connection->access_token_expires_at);
        $this->assertNull($connection->granted_scopes);
        $this->assertNull($connection->connected_by_user_id);
        $this->assertNull($connection->connected_at);
        $this->assertNull($connection->refreshed_at);
        $this->assertNull($connection->reauthorization_required_at);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request->method() === 'POST'
            && data_get($request->data(), 'token') === 'google-refresh-token');
    }

    public function test_connected_google_workspace_becomes_the_runtime_mailer(): void
    {
        GoogleMailConnection::query()->create([
            'purpose' => GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL,
            'client_id' => 'erp-google-client.apps.googleusercontent.com',
            'client_secret_encrypted' => Crypt::encryptString('google-client-secret'),
            'google_subject' => 'google-workspace-subject',
            'email' => 'powiadomienia@example.test',
            'access_token_encrypted' => Crypt::encryptString('google-access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('google-refresh-token'),
            'access_token_expires_at' => now()->addHour(),
            'granted_scopes' => [GoogleWorkspaceOAuthService::GMAIL_SEND_SCOPE],
            'connected_by_user_id' => auth()->id(),
            'connected_at' => now(),
            'refreshed_at' => now(),
        ]);
        $settings = app(MailSettingsService::class);
        $settings->update([
            'enabled' => true,
            'delivery_method' => MailSettingsService::DELIVERY_GOOGLE_WORKSPACE,
            'from_address' => 'powiadomienia@example.test',
            'from_name' => 'Sempre ERP',
        ]);

        Mail::shouldReceive('purge')->once()->with('google_workspace');

        $this->assertTrue($settings->apply());
        $this->assertSame('google_workspace', config('mail.default'));
        $this->assertSame('gmail_api', config('mail.mailers.google_workspace.transport'));
    }

    public function test_google_delivery_never_falls_back_to_smtp_before_oauth_connection(): void
    {
        $this->storeOAuthCredentials();
        $settings = app(MailSettingsService::class);
        $settings->update([
            'enabled' => true,
            'delivery_method' => MailSettingsService::DELIVERY_GOOGLE_WORKSPACE,
            'host' => 'smtp.must-not-be-used.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'powiadomienia@example.test',
            'from_name' => 'Sempre ERP',
        ]);

        $this->assertFalse($settings->apply());
        $this->assertSame(MailSettingsService::DELIVERY_GOOGLE_WORKSPACE, $settings->data()['delivery_method']);
        $this->assertFalse($settings->data()['delivery_ready']);
        $this->assertStringContainsString('nie jest połączone', (string) $settings->data()['delivery_issue']);
    }

    /** @param array<string, mixed> $overrides */
    private function mailSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'delivery_method' => MailSettingsService::DELIVERY_SMTP,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.test',
            'from_address' => 'powiadomienia@example.test',
            'from_name' => 'Sempre ERP',
            'reply_to_address' => 'kontakt@example.test',
            'ehlo_domain' => 'erp.example.test',
            'timeout' => 15,
        ], $overrides);
    }

    private function storeOAuthCredentials(): GoogleMailConnection
    {
        return GoogleMailConnection::query()->create([
            'purpose' => GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL,
            'client_id' => 'erp-google-client.apps.googleusercontent.com',
            'client_secret_encrypted' => Crypt::encryptString('google-client-secret'),
            'connected_at' => null,
        ]);
    }

    private function storeSenderAddress(): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'mail_settings'],
            ['value' => [
                'from_address' => 'powiadomienia@example.test',
                'from_name' => 'Sempre ERP',
            ]],
        );
    }

    /** @return array<string, string> */
    private function oauthRedirectQuery(string $location): array
    {
        $this->assertSame('https', parse_url($location, PHP_URL_SCHEME));
        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));
        $this->assertSame('/o/oauth2/v2/auth', parse_url($location, PHP_URL_PATH));

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertIsArray($query);

        return array_map(static fn (mixed $value): string => (string) $value, $query);
    }
}
