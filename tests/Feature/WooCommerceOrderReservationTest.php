<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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
