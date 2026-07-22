<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WooCommerceOrderStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_update_requires_woo_response_to_confirm_cancelled_status(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'STATUS-CANCEL',
            'name' => 'Status cancellation test',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo status test',
            'base_url' => 'https://status.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_status'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_status'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '501',
            'external_number' => '501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'raw_payload' => ['status' => 'processing'],
        ]);

        Http::fake([
            'https://status.shop.test/wp-json/wc/v3/orders/501' => Http::response([
                'id' => 501,
                'status' => 'processing',
            ], 200),
        ]);

        try {
            app(WooCommerceOrderStatusService::class)->updateManually($order, 'cancelled');
            $this->fail('Odpowiedź WooCommerce bez statusu cancelled powinna zatrzymać anulowanie.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nie potwierdził anulowania', $exception->getMessage());
            $this->assertStringContainsString('processing', $exception->getMessage());
        }

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('processing', data_get($order->fresh()->raw_payload, 'status'));
        $this->assertDatabaseHas('integration_sync_logs', [
            'wordpress_integration_id' => $integration->id,
            'external_id' => '501',
            'operation' => 'order_status_manual_update',
            'status' => 'failed',
        ]);
    }

    public function test_no_restock_cancellation_is_confirmed_by_plugin_before_cancelled_status(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'STATUS-NO-RESTOCK',
            'name' => 'Status no-restock test',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo no-restock status test',
            'base_url' => 'https://no-restock.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_no_restock'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_no_restock'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '502',
            'external_number' => '502',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'raw_payload' => ['status' => 'processing'],
        ]);
        $uuid = '01234567-89ab-4def-8123-456789abcdef';
        $sequence = [];

        Http::fake(function (Request $request) use (&$sequence, $uuid) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && str_ends_with($path, '/wc-lemon-erp/v1/orders/cancellation-stock/capabilities')) {
                $sequence[] = 'capability';

                return Http::response([
                    'available' => true,
                    'plugin_version' => '0.5.9',
                    'stock_disposition_contract' => 1,
                    'configuration_endpoint' => '/wp-json/wc-lemon-erp/v1/orders/{order_id}/cancellation-stock',
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/wc-lemon-erp/v1/orders/502/cancellation-stock')) {
                $sequence[] = 'stock_disposition';
                $this->assertFalse((bool) $request['restore_stock']);
                $this->assertSame($uuid, $request['cancellation_uuid']);

                return Http::response([
                    'confirmed' => true,
                    'order_id' => 502,
                    'cancellation_uuid' => $uuid,
                    'restore_stock' => false,
                    'decision_state' => 'armed',
                    'stock_disposition_contract' => 1,
                    'plugin_version' => '0.5.9',
                ]);
            }

            if ($request->method() === 'PUT' && str_ends_with($path, '/wp-json/wc/v3/orders/502')) {
                $sequence[] = 'cancelled_status';
                $this->assertSame('cancelled', $request['status']);

                return Http::response(['id' => 502, 'status' => 'cancelled']);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(WooCommerceOrderStatusService::class);
        $service->assertCancellationStockDispositionSupported($order, false, $uuid);
        $result = $service->markCancelledForOrderCancellation($order, false, $uuid);

        $this->assertSame(['capability', 'stock_disposition', 'stock_disposition', 'cancelled_status'], $sequence);
        $this->assertSame('cancelled', $result['status']);
        $this->assertFalse($result['restore_stock']);
        $this->assertSame('cancelled', $order->fresh()->status);
        Http::assertSentCount(4);
    }

    public function test_restore_cancellation_explicitly_replaces_stale_plugin_decision_when_contract_is_available(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'STATUS-RESTORE',
            'name' => 'Status restore test',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo restore status test',
            'base_url' => 'https://restore.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_restore'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_restore'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '503',
            'external_number' => '503',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'raw_payload' => ['status' => 'processing'],
        ]);
        $uuid = '11234567-89ab-4def-8123-456789abcdef';
        $sequence = [];

        Http::fake(function (Request $request) use (&$sequence, $uuid) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && str_ends_with($path, '/wc-lemon-erp/v1/orders/cancellation-stock/capabilities')) {
                $sequence[] = 'capability';

                return Http::response([
                    'available' => true,
                    'plugin_version' => '0.5.9',
                    'stock_disposition_contract' => 1,
                    'configuration_endpoint' => '/wp-json/wc-lemon-erp/v1/orders/{order_id}/cancellation-stock',
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/wc-lemon-erp/v1/orders/503/cancellation-stock')) {
                $sequence[] = 'stock_disposition';
                $this->assertTrue((bool) $request['restore_stock']);
                $this->assertSame($uuid, $request['cancellation_uuid']);

                return Http::response([
                    'confirmed' => true,
                    'order_id' => 503,
                    'cancellation_uuid' => $uuid,
                    'restore_stock' => true,
                    'decision_state' => 'armed',
                    'stock_disposition_contract' => 1,
                    'plugin_version' => '0.5.9',
                ]);
            }

            if ($request->method() === 'PUT' && str_ends_with($path, '/wp-json/wc/v3/orders/503')) {
                $sequence[] = 'cancelled_status';

                return Http::response(['id' => 503, 'status' => 'cancelled']);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(WooCommerceOrderStatusService::class);
        $service->assertCancellationStockDispositionSupported($order, true, $uuid);
        $result = $service->markCancelledForOrderCancellation($order, true, $uuid);

        $this->assertSame(['capability', 'stock_disposition', 'cancelled_status'], $sequence);
        $this->assertTrue($result['restore_stock']);
        $this->assertTrue((bool) data_get($result, 'stock_disposition_confirmation.restore_stock'));
        Http::assertSentCount(3);
    }
}
