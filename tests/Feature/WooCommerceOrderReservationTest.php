<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WooCommerceOrderReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_order_reserves_stock_and_completed_order_releases_it(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-RES',
            'name' => 'Reserved product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '7001',
            'external_sku' => 'SKU-RES',
            'stock_sync_enabled' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
            'quantity_available' => 10,
        ]);

        $status = 'processing';
        Http::fake($this->ordersResponse($status));

        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertSame(1, $stats['reserved']);
        $this->assertSame(0, $stats['released']);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->count());

        $balance = StockBalance::query()->firstOrFail();
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('8.0000', (string) $balance->quantity_available);

        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertSame(0, $stats['reserved']);
        $this->assertSame(0, $stats['released']);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->count());

        $balance->refresh();
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('8.0000', (string) $balance->quantity_available);

        $status = 'completed';
        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertSame(0, $stats['reserved']);
        $this->assertSame(1, $stats['released']);
        $this->assertSame(0, StockReservation::query()->where('status', 'active')->count());

        $balance->refresh();
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('10.0000', (string) $balance->quantity_available);
    }

    public function test_pending_order_is_imported_for_customer_communication_and_reserves_stock(): void
    {
        Mail::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-RES',
            'name' => 'Reserved product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '7001',
            'external_sku' => 'SKU-RES',
            'stock_sync_enabled' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
            'quantity_available' => 10,
        ]);

        $status = 'pending';
        Http::fake($this->ordersResponse($status));

        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['reserved']);
        $this->assertSame(0, $stats['released']);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->count());

        $balance = StockBalance::query()->firstOrFail();
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('8.0000', (string) $balance->quantity_available);

        $order = ExternalOrder::query()->firstOrFail();
        $this->assertSame('pending', $order->status);

        $this->assertDatabaseHas('customer_messages', [
            'external_order_id' => $order->id,
            'type' => 'automated',
            'trigger' => 'order_created',
            'status' => 'sent',
            'recipient_email' => 'client@example.test',
        ]);
        $this->assertSame(0, CustomerMessage::query()->where('trigger', 'order_received')->count());

        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_contains($url, '/wp-json/wc/v3/orders?')
                && str_contains($url, 'status=any');
        });
    }

    public function test_order_transition_from_pending_to_processing_keeps_reservation_and_sends_realization_message(): void
    {
        Mail::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-RES',
            'name' => 'Reserved product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '7001',
            'external_sku' => 'SKU-RES',
            'stock_sync_enabled' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
            'quantity_available' => 10,
        ]);

        $status = 'pending';
        Http::fake($this->ordersResponse($status));
        $pendingStats = app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertSame(1, $pendingStats['reserved']);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->count());

        $status = 'processing';
        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $order = ExternalOrder::query()->firstOrFail();
        $this->assertSame('processing', $order->status);
        $this->assertSame(0, $stats['reserved']);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->count());
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'order_created')->count());
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'order_received')->count());
    }

    public function test_order_import_prefers_gmt_dates_to_avoid_dst_gap_datetime_errors(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
        ]);

        Http::fake([
            '*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([
                    [
                        'id' => 448869,
                        'number' => '448869',
                        'status' => 'refunded',
                        'currency' => 'PLN',
                        'total' => '520.90',
                        'date_created' => '2026-03-29T02:06:07',
                        'date_created_gmt' => '2026-03-29T00:06:07',
                        'date_modified' => '2026-04-14T20:00:26',
                        'date_modified_gmt' => '2026-04-14T18:00:26',
                        'billing' => [
                            'email' => 'client@example.test',
                            'first_name' => 'Natalia',
                            'last_name' => 'Pawlowska',
                        ],
                        'line_items' => [],
                    ],
                ]);
            },
        ]);

        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertSame(1, $stats['created']);

        $order = ExternalOrder::query()->where('external_id', '448869')->firstOrFail();

        $this->assertSame('2026-03-29 00:06:07', $order->external_created_at?->toDateTimeString());
        $this->assertSame('2026-04-14 18:00:26', $order->external_updated_at?->toDateTimeString());
    }

    /**
     * @return array<string, mixed>
     */
    private function ordersResponse(string &$status): array
    {
        return [
            '*' => function ($request) use (&$status) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([
                    [
                        'id' => 501,
                        'number' => '501',
                        'status' => $status,
                        'currency' => 'PLN',
                        'total' => '199.00',
                        'billing' => [
                            'email' => 'client@example.test',
                            'first_name' => 'Jan',
                            'last_name' => 'Klient',
                        ],
                        'line_items' => [
                            [
                                'id' => 9001,
                                'sku' => 'SKU-RES',
                                'name' => 'Reserved product',
                                'quantity' => 2,
                                'subtotal' => '160.00',
                                'total' => '199.00',
                            ],
                        ],
                    ],
                ]);
            },
        ];
    }
}
