<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\Customer;
use App\Models\CustomerExternalAccount;
use App\Models\CustomerMessage;
use App\Models\IntegrationSyncLog;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Communication\MailSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class WooCommerceCustomerWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CONSUMER_KEY = 'ck_customer_webhook_test';

    private const CONSUMER_SECRET = 'cs_customer_webhook_test';

    private const WORDPRESS_USERNAME = 'erp-wordpress';

    private const WORDPRESS_PASSWORD = 'wordpress-application-password';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        app(MailSettingsService::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'sklep@example.test',
            'from_name' => 'Sempre',
            'timeout' => 15,
        ]);
    }

    public function test_signed_created_event_synchronizes_canonical_profile_and_sends_mail_immediately_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 14:00:00', 'Europe/Warsaw'));
        $integration = $this->integration('2026-07-13T13:59:00+02:00');
        $this->fakeCustomerProfile(44, 'nowa@example.test', '2026-07-13T12:00:00');
        $payload = $this->payload('customer.created', 'evt-created-44', 44, '2026-07-13T14:00:00+02:00');

        $this->signedPost($integration, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('result.notification_eligible', true)
            ->assertJsonPath('result.notification_created', true)
            ->assertJsonPath('result.notification_status', 'sent');

        $account = CustomerExternalAccount::query()->firstOrFail();
        $this->assertSame('44', $account->external_customer_id);
        $this->assertSame('nowa@example.test', $account->email_normalized);
        $this->assertTrue($account->is_registered);
        $this->assertSame('registered', $account->customer->account_status);
        $this->assertDatabaseHas('customer_messages', [
            'customer_id' => $account->customer_id,
            'trigger' => 'customer_account_created',
            'recipient_email' => 'nowa@example.test',
            'status' => 'sent',
        ]);
        Mail::assertSent(CustomerMessageMail::class, 1);

        $this->signedPost($integration, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, CustomerExternalAccount::query()->count());
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'customer_account_created')->count());
        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'customer_webhook')->count());
        Mail::assertSent(CustomerMessageMail::class, 1);

        $this->travelBack();
    }

    public function test_update_event_refreshes_customer_without_account_created_mail(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 14:00:00', 'Europe/Warsaw'));
        $integration = $this->integration('2026-07-13T13:00:00+02:00');
        $this->fakeCustomerProfile(45, 'aktualizacja@example.test', '2026-07-13T11:55:00');

        $this->signedPost($integration, $this->payload(
            'customer.updated',
            'evt-updated-45',
            45,
            '2026-07-13T14:00:00+02:00',
        ))
            ->assertOk()
            ->assertJsonPath('result.notification_eligible', false)
            ->assertJsonPath('result.notification_created', false);

        $this->assertDatabaseHas('customer_external_accounts', [
            'wordpress_integration_id' => $integration->id,
            'external_customer_id' => '45',
            'email_normalized' => 'aktualizacja@example.test',
            'is_registered' => true,
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'trigger' => 'customer_account_created',
        ]);
        Mail::assertNothingSent();

        $this->travelBack();
    }

    public function test_historical_created_event_is_synchronized_without_historical_mail(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 14:00:00', 'Europe/Warsaw'));
        $integration = $this->integration('2026-07-13T13:30:00+02:00');
        $this->fakeCustomerProfile(46, 'historyczny@example.test', '2026-07-13T10:00:00');

        $this->signedPost($integration, $this->payload(
            'customer.created',
            'evt-historic-46',
            46,
            '2026-07-13T12:00:00+02:00',
        ))
            ->assertOk()
            ->assertJsonPath('result.notification_eligible', false)
            ->assertJsonPath('result.notification_created', false);

        $this->assertDatabaseHas('customer_external_accounts', [
            'external_customer_id' => '46',
            'is_registered' => true,
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'trigger' => 'customer_account_created',
        ]);
        Mail::assertNothingSent();

        $this->travelBack();
    }

    public function test_invalid_or_stale_signature_is_rejected_before_woocommerce_lookup(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 14:00:00', 'Europe/Warsaw'));
        $integration = $this->integration('2026-07-13T13:00:00+02:00');
        Http::fake();
        $payload = $this->payload('customer.created', 'evt-invalid-47', 47, '2026-07-13T14:00:00+02:00');
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->call('POST', route('api.woocommerce.customer-webhooks.store', $integration), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LEMON_WEBHOOK_TIMESTAMP' => (string) now()->getTimestamp(),
            'HTTP_X_LEMON_WEBHOOK_SIGNATURE' => 'invalid-signature',
            'HTTP_X_LEMON_WEBHOOK_EVENT' => $payload['event'],
            'HTTP_X_LEMON_WEBHOOK_ID' => $payload['event_id'],
            'HTTP_X_LEMON_WEBHOOK_VERSION' => '1',
        ], $json)->assertUnauthorized();

        $staleTimestamp = (string) now()->subMinutes(6)->getTimestamp();
        $this->call('POST', route('api.woocommerce.customer-webhooks.store', $integration), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LEMON_WEBHOOK_TIMESTAMP' => $staleTimestamp,
            'HTTP_X_LEMON_WEBHOOK_SIGNATURE' => $this->signature($staleTimestamp, $json),
            'HTTP_X_LEMON_WEBHOOK_EVENT' => $payload['event'],
            'HTTP_X_LEMON_WEBHOOK_ID' => $payload['event_id'],
            'HTTP_X_LEMON_WEBHOOK_VERSION' => '1',
        ], $json)->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, IntegrationSyncLog::query()->count());

        $this->travelBack();
    }

    public function test_operator_configures_plugin_without_sending_consumer_secret_and_sets_baseline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 14:00:00', 'Europe/Warsaw'));
        $integration = $this->integration(null);

        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/customer-webhook/configure' => Http::response([
                'configured' => true,
                'plugin_version' => '0.4.1',
            ]),
        ]);

        $this->post(route('integrations.customer-webhook.configure', $integration))
            ->assertRedirect()
            ->assertSessionHas('status', 'Webhook klientów został skonfigurowany. Nowe konta będą synchronizowane i obsługiwane mailowo od razu.');

        $this->assertSame(
            '2026-07-13T14:00:00+02:00',
            data_get($integration->fresh()->settings, 'customer_import.notification_baseline_at'),
        );
        $this->assertTrue((bool) data_get($integration->fresh()->settings, 'customer_webhook.configured'));
        $this->assertSame(
            '0.4.1',
            data_get($integration->fresh()->settings, 'customer_webhook.plugin_version'),
        );
        $this->assertDatabaseHas('integration_sync_logs', [
            'wordpress_integration_id' => $integration->id,
            'operation' => 'configure_customer_webhook',
            'status' => 'success',
        ]);
        Http::assertSent(function (Request $request) use ($integration): bool {
            $expectedDeliveryUrl = route('api.woocommerce.customer-webhooks.store', $integration);

            return $request->method() === 'POST'
                && $request->url() === 'https://shop.example.test/wp-json/wc-lemon-erp/v1/customer-webhook/configure'
                && $request['delivery_url'] === $expectedDeliveryUrl
                && $request['consumer_key'] === self::CONSUMER_KEY
                && ! array_key_exists('consumer_secret', $request->data())
                && $request->hasHeader(
                    'Authorization',
                    'Basic '.base64_encode(self::CONSUMER_KEY.':'.self::CONSUMER_SECRET),
                );
        });

        $this->travelBack();
    }

    public function test_plugin_040_configuration_falls_back_to_wordpress_application_password_after_new_route_is_missing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 14:00:00', 'Europe/Warsaw'));
        $integration = $this->integration(null, withWordpressCredentials: true);

        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/customer-webhook/configure' => Http::response([
                'code' => 'rest_no_route',
                'message' => 'No route was found matching the URL and request method.',
            ], 404),
            'https://shop.example.test/wp-json/lemon-erp/v1/customer-webhook/configure' => Http::response([
                'configured' => true,
                'plugin_version' => '0.4.0',
            ]),
        ]);

        $this->post(route('integrations.customer-webhook.configure', $integration))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue((bool) data_get($integration->fresh()->settings, 'customer_webhook.configured'));
        $this->assertSame(
            '0.4.0',
            data_get($integration->fresh()->settings, 'customer_webhook.plugin_version'),
        );
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://shop.example.test/wp-json/lemon-erp/v1/customer-webhook/configure'
                && $request->hasHeader(
                    'Authorization',
                    'Basic '.base64_encode(self::WORDPRESS_USERNAME.':'.self::WORDPRESS_PASSWORD),
                )
                && $request['consumer_key'] === self::CONSUMER_KEY
                && ! array_key_exists('consumer_secret', $request->data());
        });
        $this->assertSame(2, count(Http::recorded()));

        $this->travelBack();
    }

    public function test_authentication_failure_on_new_route_does_not_fall_back_to_other_credentials(): void
    {
        $integration = $this->integration(null, withWordpressCredentials: true);

        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/customer-webhook/configure' => Http::response([
                'code' => 'woocommerce_rest_authentication_error',
                'message' => 'Consumer secret is invalid.',
            ], 401),
            'https://shop.example.test/wp-json/lemon-erp/v1/customer-webhook/configure' => Http::response([
                'configured' => true,
            ]),
        ]);

        $this->post(route('integrations.customer-webhook.configure', $integration))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse((bool) data_get($integration->fresh()->settings, 'customer_webhook.configured', false));
        $this->assertSame(1, count(Http::recorded()));
    }

    /** @return array<string, mixed> */
    private function payload(string $event, string $eventId, int $customerId, string $occurredAt): array
    {
        return [
            'event' => $event,
            'event_id' => $eventId,
            'occurred_at' => $occurredAt,
            'store_url' => 'https://shop.example.test/',
            'customer_id' => $customerId,
        ];
    }

    private function signedPost(WordpressIntegration $integration, array $payload)
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();

        return $this->call('POST', route('api.woocommerce.customer-webhooks.store', $integration), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LEMON_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_LEMON_WEBHOOK_SIGNATURE' => $this->signature($timestamp, $json),
            'HTTP_X_LEMON_WEBHOOK_EVENT' => $payload['event'],
            'HTTP_X_LEMON_WEBHOOK_ID' => $payload['event_id'],
            'HTTP_X_LEMON_WEBHOOK_VERSION' => '1',
        ], $json);
    }

    private function signature(string $timestamp, string $json): string
    {
        return base64_encode(hash_hmac(
            'sha256',
            $timestamp.'.'.$json,
            self::CONSUMER_SECRET,
            true,
        ));
    }

    private function integration(
        ?string $baseline,
        bool $withWordpressCredentials = false,
    ): WordpressIntegration {
        $channel = SalesChannel::query()->create([
            'code' => 'WEBHOOK',
            'name' => 'Sklep webhook',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo webhook',
            'base_url' => 'https://shop.example.test',
            'consumer_key_encrypted' => Crypt::encryptString(self::CONSUMER_KEY),
            'consumer_secret_encrypted' => Crypt::encryptString(self::CONSUMER_SECRET),
            'wp_api_username' => $withWordpressCredentials ? self::WORDPRESS_USERNAME : null,
            'wp_api_password_encrypted' => $withWordpressCredentials
                ? Crypt::encryptString(self::WORDPRESS_PASSWORD)
                : null,
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
            'settings' => $baseline === null ? [] : [
                'customer_import' => [
                    'notification_baseline_at' => $baseline,
                ],
            ],
        ]);
    }

    private function fakeCustomerProfile(int $id, string $email, string $createdGmt): void
    {
        Http::fake(function (Request $request) use ($id, $email, $createdGmt) {
            if ($request->method() === 'GET'
                && $request->url() === "https://shop.example.test/wp-json/wc/v3/customers/{$id}"
            ) {
                return Http::response([
                    'id' => $id,
                    'email' => $email,
                    'username' => 'klient-'.$id,
                    'first_name' => 'Nowa',
                    'last_name' => 'Klientka',
                    'display_name' => 'Nowa Klientka',
                    'role' => 'customer',
                    'date_created_gmt' => $createdGmt,
                    'billing' => [
                        'email' => $email,
                        'first_name' => 'Nowa',
                        'last_name' => 'Klientka',
                    ],
                    'shipping' => [],
                    'orders_count' => 0,
                    'total_spent' => '0.00',
                ]);
            }

            return Http::response([], 404);
        });
    }
}
