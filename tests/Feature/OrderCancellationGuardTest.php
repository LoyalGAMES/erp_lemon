<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\Inventory\StockReservationService;
use App\Services\Shipping\ShippedOrderWooSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OrderCancellationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_child_cannot_recreate_reservations_while_root_cancellation_is_active(): void
    {
        [$channel, $warehouse, $product] = $this->inventoryContext('SPLIT');
        $root = $this->order($channel, '8101');
        $child = $this->order($channel, '8101-SPLIT-1', [
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);
        $reservation = StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $child->external_id,
            'quantity' => 1,
            'status' => 'active',
            'reserved_at' => now()->subMinute(),
        ]);
        OrderCancellation::query()->create([
            'uuid' => '81010000-0000-4000-8000-000000000001',
            'external_order_id' => $root->id,
            'status' => 'attention_required',
            'reason' => 'Anulowanie rodziny splitów',
            'refund_status' => 'manual_required',
            'currency' => 'PLN',
        ]);

        $result = app(StockReservationService::class)->syncForOrder($child);

        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame(1, $result['released']);
        $this->assertSame(0, $result['reserved']);
        $this->assertSame(0, StockReservation::query()
            ->where('external_order_id', $child->external_id)
            ->whereIn('status', ['active', 'waiting'])
            ->count());
    }

    public function test_rejected_cancellation_does_not_disable_later_shipped_status_sync(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'REJECTED-CANCEL',
            'name' => 'Odrzucona anulacja',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo rejected cancellation',
            'base_url' => 'https://rejected-cancel.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_rejected'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_rejected'),
            'order_import_enabled' => true,
        ]);
        $order = $this->order($channel, '8201', [
            'wordpress_integration_id' => $integration->id,
            'fulfillment_status' => 'shipped',
            'woo_shipped_sync_status' => 'failed',
            'woo_shipped_sync_attempts' => 1,
            'woo_shipped_sync_next_at' => null,
        ]);
        OrderCancellation::query()->create([
            'uuid' => '82010000-0000-4000-8000-000000000001',
            'external_order_id' => $order->id,
            'status' => 'rejected',
            'reason' => 'Próba została odrzucona',
            'refund_status' => 'pending',
            'currency' => 'PLN',
        ]);
        Http::fake(fn (Request $request) => Http::response([
            'id' => 8201,
            'number' => '8201',
            'status' => 'completed',
        ]));

        $result = app(ShippedOrderWooSyncService::class)->retry();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('success', $order->fresh()->woo_shipped_sync_status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/orders/8201')
            && ($request->data()['status'] ?? null) === 'completed');
    }

    /** @return array{SalesChannel,Warehouse,Product} */
    private function inventoryContext(string $suffix): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'CANCEL-GUARD-'.$suffix,
            'name' => 'Guard anulacji '.$suffix,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'MAG-'.$suffix,
            'name' => 'Magazyn '.$suffix,
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
            'sku' => 'GUARD-'.$suffix,
            'name' => 'Produkt guard '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 1,
            'quantity_available' => 9,
        ]);

        return [$channel, $warehouse, $product];
    }

    /** @param array<string, mixed> $overrides */
    private function order(SalesChannel $channel, string $number, array $overrides = []): ExternalOrder
    {
        return ExternalOrder::query()->create(array_merge([
            'sales_channel_id' => $channel->id,
            'external_id' => $number,
            'external_number' => $number,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'raw_payload' => [],
            'external_created_at' => now()->subHour(),
        ], $overrides));
    }
}
