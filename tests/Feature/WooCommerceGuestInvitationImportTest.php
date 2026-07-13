<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerAccountClaim;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceGuestInvitationImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_order_import_establishes_a_per_integration_baseline_and_only_later_guest_orders_are_invited(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00', 'Europe/Warsaw'));
        $integration = $this->integration([
            'existing_section' => ['preserved' => true],
            'customer_import' => ['notification_baseline_at' => '2026-07-01T10:00:00+00:00'],
        ]);
        $orders = [
            $this->orderPayload(1001, 'historyczny@example.test', now()->subHour()->utc()),
        ];
        $this->fakeOrderPages($orders);

        app(WooCommerceImportService::class)->importOrders($integration);

        $settings = (array) $integration->fresh()->settings;
        $baseline = data_get($settings, 'customer_import.guest_invitation_baseline_at');
        $this->assertIsString($baseline);
        $this->assertTrue((bool) data_get($settings, 'existing_section.preserved'));
        $this->assertSame(
            '2026-07-01T10:00:00+00:00',
            data_get($settings, 'customer_import.notification_baseline_at'),
        );
        $this->assertDatabaseMissing('customer_messages', [
            'trigger' => 'guest_account_invitation',
        ]);
        $this->assertSame(0, CustomerAccountClaim::query()->count());

        $this->travel(2)->minutes();
        $orders[] = $this->orderPayload(1002, 'nowy@example.test', now()->utc());

        app(WooCommerceImportService::class)->importOrders($integration->fresh());

        $newOrder = ExternalOrder::query()->where('external_id', '1002')->firstOrFail();
        $historicalOrder = ExternalOrder::query()->where('external_id', '1001')->firstOrFail();
        $this->assertDatabaseHas('customer_messages', [
            'external_order_id' => $newOrder->id,
            'recipient_email' => 'nowy@example.test',
            'trigger' => 'guest_account_invitation',
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'external_order_id' => $historicalOrder->id,
            'trigger' => 'guest_account_invitation',
        ]);
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'guest_account_invitation')->count());
        $this->assertSame(1, CustomerAccountClaim::query()->count());
    }

    public function test_guest_order_created_after_the_baseline_during_first_scan_is_not_lost(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00', 'Europe/Warsaw'));
        $integration = $this->integration([]);
        $orders = [
            $this->orderPayload(1003, 'w-trakcie-importu@example.test', now()->addSecond()->utc()),
        ];
        $this->fakeOrderPages($orders);

        app(WooCommerceImportService::class)->importOrders($integration);

        $order = ExternalOrder::query()->where('external_id', '1003')->firstOrFail();
        $this->assertDatabaseHas('customer_messages', [
            'external_order_id' => $order->id,
            'recipient_email' => 'w-trakcie-importu@example.test',
            'trigger' => 'guest_account_invitation',
        ]);
        $this->assertSame(1, CustomerAccountClaim::query()->count());
    }

    public function test_recent_guest_invitation_is_retried_when_claim_preparation_failed_before_message_creation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00', 'Europe/Warsaw'));
        $integration = $this->integration([
            'customer_import' => [
                'guest_invitation_baseline_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);
        $orders = [
            $this->orderPayload(2001, 'ponowienie@example.test', now()->utc()),
        ];
        $this->fakeOrderPages($orders);
        config()->set('services.woocommerce.customer_claim_ttl_days', 0);

        app(WooCommerceImportService::class)->importOrders($integration);

        $order = ExternalOrder::query()->where('external_id', '2001')->firstOrFail();
        $this->assertDatabaseMissing('customer_messages', [
            'external_order_id' => $order->id,
            'trigger' => 'guest_account_invitation',
        ]);
        $this->assertSame(0, CustomerAccountClaim::query()->count());

        config()->set('services.woocommerce.customer_claim_ttl_days', 14);
        $stats = app(WooCommerceImportService::class)->importOrders($integration->fresh());

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['updated']);
        $this->assertDatabaseHas('customer_messages', [
            'external_order_id' => $order->id,
            'recipient_email' => 'ponowienie@example.test',
            'trigger' => 'guest_account_invitation',
        ]);
        $this->assertSame(1, CustomerAccountClaim::query()->count());

        app(WooCommerceImportService::class)->importOrders($integration->fresh());

        $this->assertSame(1, CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('trigger', 'guest_account_invitation')
            ->count());
        $this->assertSame(1, CustomerAccountClaim::query()->count());
    }

    public function test_registered_orders_do_not_trigger_customer_api_calls_or_account_mail_during_order_import(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 12:00:00', 'Europe/Warsaw'));
        $integration = $this->integration([
            'customer_import' => [
                'guest_invitation_baseline_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);
        $orders = [
            $this->orderPayload(3001, 'konto@example.test', now()->utc(), 44),
        ];
        $this->fakeOrderPages($orders);

        app(WooCommerceImportService::class)->importOrders($integration);
        app(WooCommerceImportService::class)->importOrders($integration->fresh());

        Http::assertNotSent(function (Request $request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return str_contains($path, '/customers');
        });
        $this->assertDatabaseMissing('customer_messages', [
            'recipient_email' => 'konto@example.test',
            'trigger' => 'customer_account_created',
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'recipient_email' => 'konto@example.test',
            'trigger' => 'guest_account_invitation',
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function integration(array $settings): WordpressIntegration
    {
        $channel = SalesChannel::query()->create([
            'code' => 'SHOP-'.str()->upper(str()->random(6)),
            'name' => 'Sklep testowy',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo test',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
            'settings' => $settings,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(
        int $id,
        string $email,
        CarbonInterface $createdAt,
        int $customerId = 0,
    ): array {
        return [
            'id' => $id,
            'number' => (string) $id,
            'customer_id' => $customerId,
            'status' => 'completed',
            'currency' => 'PLN',
            'total' => '49.90',
            'date_created_gmt' => $createdAt->utc()->format('Y-m-d\TH:i:s'),
            'date_modified_gmt' => $createdAt->utc()->format('Y-m-d\TH:i:s'),
            'billing' => [
                'email' => $email,
                'first_name' => 'Anna',
                'last_name' => 'Testowa',
            ],
            'shipping' => [],
            'line_items' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     */
    private function fakeOrderPages(array &$orders): void
    {
        Http::fake(function (Request $request) use (&$orders) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (! str_ends_with($path, '/orders')) {
                return Http::response([], 404);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response((int) ($query['page'] ?? 1) === 1 ? $orders : []);
        });
    }
}
