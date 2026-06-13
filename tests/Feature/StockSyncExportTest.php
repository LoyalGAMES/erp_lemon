<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\IntegrationSyncLog;
use App\Models\StockBalance;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WarehouseChannelRoute;
use App\Models\WordpressIntegration;
use App\Jobs\ExportStockToWooCommerceJob;
use App\Services\WooCommerce\StockSyncExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockSyncExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_queue_item_is_exported_to_woocommerce_product(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-003',
                'stock_quantity' => 8,
                'stock_status' => 'instock',
            ]),
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-003',
            'name' => 'Synced product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-003',
            'stock_sync_enabled' => true,
        ]);

        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'status' => 'pending',
            'quantity_to_push' => 8.7,
            'available_at' => now(),
        ]);

        app(StockSyncExportService::class)->export($queueItem);

        $queueItem->refresh();

        $this->assertSame('success', $queueItem->status);
        $this->assertSame('8', (string) $queueItem->metadata['woocommerce_stock_quantity']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 8
            && $request['stock_status'] === 'instock');
    }

    public function test_failed_stock_export_can_be_retried_from_sync_module(): void
    {
        Queue::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre B2C',
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

        $product = Product::query()->create([
            'sku' => 'SKU-RETRY',
            'name' => 'Retry product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'status' => 'failed',
            'quantity_to_push' => 6,
            'available_at' => now()->subHour(),
            'processed_at' => now()->subMinute(),
            'last_error' => 'Brak mapowania produktu',
            'metadata' => ['reason' => 'warehouse_document_posted'],
        ]);

        $this->get(route('modules.show', 'sync'))
            ->assertOk()
            ->assertSee('SKU-RETRY')
            ->assertSee('Brak mapowania produktu')
            ->assertSee('Ponów');

        $this->post(route('sync.retry', $queueItem))
            ->assertRedirect()
            ->assertSessionHas('status', 'Eksport stanu został ponownie dodany do kolejki.');

        $queueItem->refresh();
        $this->assertSame('pending', $queueItem->status);
        $this->assertNull($queueItem->last_error);
        $this->assertNull($queueItem->processed_at);
        $this->assertSame(1, $queueItem->metadata['retry_count']);

        Queue::assertPushed(ExportStockToWooCommerceJob::class);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stock_sync.retry_requested',
            'auditable_type' => StockSyncQueueItem::class,
            'auditable_id' => $queueItem->id,
        ]);
    }

    public function test_successful_stock_export_cannot_be_retried(): void
    {
        Queue::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre B2C',
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

        $product = Product::query()->create([
            'sku' => 'SKU-NO-RETRY',
            'name' => 'No retry product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'status' => 'success',
            'quantity_to_push' => 2,
            'available_at' => now()->subHour(),
            'processed_at' => now()->subMinute(),
        ]);

        $this->post(route('sync.retry', $queueItem))
            ->assertRedirect()
            ->assertSessionHas('error', 'Ponowić można tylko nieudany eksport stanu.');

        $queueItem->refresh();
        $this->assertSame('success', $queueItem->status);

        Queue::assertNotPushed(ExportStockToWooCommerceJob::class);
    }

    public function test_operator_can_rebuild_full_stock_sync_queue_for_channels(): void
    {
        Queue::fake();

        $b2c = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $b2b = SalesChannel::query()->create([
            'code' => 'B2B',
            'name' => 'Sklep B2B',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $disabled = SalesChannel::query()->create([
            'code' => 'DISABLED',
            'name' => 'Kanał bez eksportu',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        foreach ([[$b2c, true], [$b2b, true], [$disabled, false]] as [$channel, $stockExportEnabled]) {
            WordpressIntegration::query()->create([
                'sales_channel_id' => $channel->id,
                'name' => $channel->name,
                'base_url' => 'https://' . strtolower($channel->code) . '.test',
                'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
                'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
                'stock_export_enabled' => $stockExportEnabled,
            ]);
        }

        $main = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $secondary = Warehouse::query()->create([
            'code' => 'M2',
            'name' => 'Secondary',
            'type' => 'physical',
            'is_active' => true,
        ]);

        WarehouseChannelRoute::query()->create([
            'warehouse_id' => $main->id,
            'sales_channel_id' => $b2c->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 1,
            'priority' => 10,
        ]);

        WarehouseChannelRoute::query()->create([
            'warehouse_id' => $secondary->id,
            'sales_channel_id' => $b2b->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 10,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-FULL-SYNC',
            'name' => 'Full sync product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $disabledProduct = Product::query()->create([
            'sku' => 'SKU-DISABLED-SYNC',
            'name' => 'Disabled sync product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        foreach ([$b2c, $b2b] as $channel) {
            ProductChannelMapping::query()->create([
                'product_id' => $product->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => (string) (100 + $channel->id),
                'external_sku' => $product->sku,
                'stock_sync_enabled' => true,
            ]);
        }

        ProductChannelMapping::query()->create([
            'product_id' => $disabledProduct->id,
            'sales_channel_id' => $disabled->id,
            'external_product_id' => '999',
            'external_sku' => $disabledProduct->sku,
            'stock_sync_enabled' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $main->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 12,
            'quantity_reserved' => 2,
            'quantity_available' => 10,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $secondary->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 5,
            'quantity_reserved' => 0,
            'quantity_available' => 5,
        ]);

        $this->get(route('modules.show', 'sync'))
            ->assertOk()
            ->assertSee('Pełna synchronizacja stanów')
            ->assertSee('B2C - Sklep B2C')
            ->assertSee('B2B - Sklep B2B')
            ->assertDontSee('DISABLED - Kanał bez eksportu');

        $this->post(route('sync.rebuild'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(2, StockSyncQueueItem::query()->count());

        $b2cItem = StockSyncQueueItem::query()
            ->where('sales_channel_id', $b2c->id)
            ->firstOrFail();

        $this->assertSame($main->id, $b2cItem->warehouse_id);
        $this->assertSame('9.0000', (string) $b2cItem->quantity_to_push);
        $this->assertSame('manual_full_stock_rebuild', $b2cItem->metadata['reason']);
        $this->assertSame('manual_full_rebuild', $b2cItem->metadata['source']);
        $this->assertSame('channel_warehouse_route_aggregate', $b2cItem->metadata['calculation']);

        $b2bItem = StockSyncQueueItem::query()
            ->where('sales_channel_id', $b2b->id)
            ->firstOrFail();

        $this->assertSame($secondary->id, $b2bItem->warehouse_id);
        $this->assertSame('5.0000', (string) $b2bItem->quantity_to_push);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stock_sync.full_rebuild_requested',
        ]);

        Queue::assertPushed(ExportStockToWooCommerceJob::class, 2);

        $this->post(route('sync.rebuild'), ['sales_channel_id' => $b2c->id])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(3, StockSyncQueueItem::query()->count());
        $this->assertSame($b2c->id, StockSyncQueueItem::query()->latest()->firstOrFail()->sales_channel_id);
    }

    public function test_sync_module_shows_operational_queue_summary(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre B2C',
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

        $product = Product::query()->create([
            'sku' => 'SKU-SUMMARY',
            'name' => 'Summary product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'failed',
            'error_message' => 'Stary błąd uprawnień',
            'attempts' => 1,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'queued',
            'attempts' => 1,
            'started_at' => now(),
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'failed',
            'error_message' => 'Woo timeout',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'status' => 'pending',
            'quantity_to_push' => 3,
            'available_at' => now(),
        ]);

        StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'status' => 'failed',
            'quantity_to_push' => 2,
            'available_at' => now()->subHour(),
            'last_error' => 'Brak mapowania produktu',
        ]);

        $this->get(route('modules.show', 'sync'))
            ->assertOk()
            ->assertSee('Zadania techniczne')
            ->assertSee('Importy WooCommerce')
            ->assertSee('queued 1 | running 0 | failed 1')
            ->assertSee('Eksport stanów')
            ->assertSee('pending 1 | running 0 | failed 1')
            ->assertSee('Wymaga reakcji')
            ->assertSee('Suma nieudanych importów i eksportów stanów')
            ->assertSee('SKU-SUMMARY')
            ->assertSee('Brak mapowania produktu');
    }
}
