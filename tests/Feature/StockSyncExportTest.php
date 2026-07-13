<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportStockToWooCommerceJob;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockSyncQueueItem;
use App\Models\StockSyncState;
use App\Models\Warehouse;
use App\Models\WarehouseChannelRoute;
use App\Models\WordpressIntegration;
use App\Services\Inventory\StockSyncQueueService;
use App\Services\WooCommerce\StockSyncExportService;
use App\Support\OperationalStatus;
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
            'https://shop.test/wp-json/wc/v3/products/124' => Http::response([
                'id' => 124,
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
        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '124',
            'external_sku' => 'SKU-003',
            'language' => 'en',
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
        $this->assertSame(2, $queueItem->metadata['woocommerce_targets_updated']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 8
            && $request['stock_status'] === 'instock');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124'
            && $request['stock_quantity'] === 8);
    }

    public function test_stock_queue_item_is_exported_to_woocommerce_variation(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123/variations/456' => Http::response([
                'id' => 456,
                'sku' => 'SKU-VARIANT-M',
                'stock_quantity' => 3,
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
        $variant = Product::query()->create([
            'sku' => 'SKU-VARIANT-M',
            'name' => 'Wariant M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => 'SKU-VARIANT-M',
            'stock_sync_enabled' => true,
        ]);
        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'status' => 'pending',
            'quantity_to_push' => 3,
            'available_at' => now(),
        ]);

        app(StockSyncExportService::class)->export($queueItem);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations/456'
            && $request['stock_quantity'] === 3);
    }

    public function test_older_queue_version_cannot_overwrite_newer_export(): void
    {
        Queue::fake();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => fn ($request) => Http::response([
                'id' => 123,
                'sku' => 'SKU-MONOTONIC',
                'stock_quantity' => $request['stock_quantity'],
                'stock_status' => $request['stock_status'],
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
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-MONOTONIC',
            'name' => 'Monotonic stock',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        $balance = StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 4,
            'quantity_reserved' => 0,
            'quantity_available' => 4,
        ]);
        $queue = app(StockSyncQueueService::class);

        $queue->queueForTriggers([[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]], 'first');
        $older = StockSyncQueueItem::query()->firstOrFail();
        $older->update(['status' => 'running']);

        $balance->update([
            'quantity_on_hand' => 2,
            'quantity_available' => 2,
        ]);
        $queue->queueForTriggers([[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]], 'second');

        $newer = StockSyncQueueItem::query()->latest('id')->firstOrFail();
        $this->assertNotSame($older->id, $newer->id);
        $this->assertSame(1, $older->version);
        $this->assertSame(2, $newer->version);
        $this->assertSame('2.0000', (string) $newer->quantity_to_push);
        $this->assertSame(2, StockSyncState::query()->firstOrFail()->desired_version);

        app(StockSyncExportService::class)->export($newer);
        $older->update(['status' => 'pending']);
        $result = app(StockSyncExportService::class)->export($older);

        $this->assertTrue($result['skipped']);
        $this->assertSame('superseded', $older->fresh()->status);
        $this->assertSame('success', $newer->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['stock_quantity'] === 2);
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
                'base_url' => 'https://'.strtolower($channel->code).'.test',
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

        $inactive = Warehouse::query()->create([
            'code' => 'OLD',
            'name' => 'Inactive legacy warehouse',
            'type' => 'physical',
            'is_active' => false,
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

        WarehouseChannelRoute::query()->create([
            'warehouse_id' => $inactive->id,
            'sales_channel_id' => $b2c->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 1,
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

        StockBalance::query()->create([
            'warehouse_id' => $inactive->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 99,
            'quantity_reserved' => 0,
            'quantity_available' => 99,
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

        $this->assertSame(2, StockSyncQueueItem::query()->count());

        $b2cItem->refresh();
        $this->assertSame($b2c->id, $b2cItem->sales_channel_id);
        $this->assertSame('manual_full_stock_rebuild', $b2cItem->metadata['latest_reason']);
        $this->assertSame(1, $b2cItem->metadata['coalesced_count']);
        $this->assertSame('9.0000', (string) $b2cItem->quantity_to_push);
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

        $queuedProduct = Product::query()->create([
            'sku' => 'SKU-SUMMARY-QUEUED',
            'name' => 'Queued summary product',
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

        $queuedItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $queuedProduct->id,
            'sales_channel_id' => $channel->id,
            'status' => 'queued',
            'quantity_to_push' => 3,
            'available_at' => now(),
        ]);

        $failedItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'status' => 'failed',
            'quantity_to_push' => 2,
            'available_at' => now()->subHour(),
            'last_error' => 'Brak mapowania produktu',
        ]);

        StockSyncState::query()->create([
            'product_id' => $queuedProduct->id,
            'sales_channel_id' => $channel->id,
            'desired_version' => 1,
            'desired_quantity' => 3,
            'exported_version' => 0,
            'queue_item_id' => $queuedItem->id,
        ]);

        StockSyncState::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'desired_version' => 1,
            'desired_quantity' => 2,
            'exported_version' => 0,
            'queue_item_id' => $failedItem->id,
        ]);

        $this->get(route('modules.show', 'sync'))
            ->assertOk()
            ->assertSee('Zadania techniczne')
            ->assertSee('Importy WooCommerce')
            ->assertSee('queued 1 | running 0 | failed 1')
            ->assertSee('Eksport stanów')
            ->assertSee('pending 0 | queued 1 | running 0 | failed 1')
            ->assertSee('Wymaga reakcji')
            ->assertSee('Suma nieudanych importów i eksportów stanów')
            ->assertSee('SKU-SUMMARY')
            ->assertSee('Brak mapowania produktu');
    }

    public function test_woocommerce_status_uses_only_current_stock_export_state(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre B2C',
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
            'sku' => 'SKU-CURRENT-STATUS',
            'name' => 'Current status product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $historicalFailure = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'version' => 1,
            'status' => 'failed',
            'quantity_to_push' => 3,
            'available_at' => now()->subHour(),
            'last_error' => 'Stary błąd autoryzacji',
        ]);

        $currentSuccess = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'version' => 2,
            'status' => 'success',
            'quantity_to_push' => 3,
            'available_at' => now(),
            'processed_at' => now(),
        ]);

        $state = StockSyncState::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'desired_version' => 2,
            'desired_quantity' => 3,
            'exported_version' => 2,
            'queue_item_id' => $currentSuccess->id,
        ]);

        $status = app(OperationalStatus::class)->navigation()['woocommerce'];

        $this->assertSame('green', $status['tone']);
        $this->assertSame('OK', $status['label']);
        $this->assertSame('integrations', $status['destination']);

        $state->update([
            'desired_version' => 1,
            'exported_version' => 0,
            'queue_item_id' => $historicalFailure->id,
        ]);

        $status = app(OperationalStatus::class)->navigation()['woocommerce'];

        $this->assertSame('red', $status['tone']);
        $this->assertSame('Eksport stanów: 1', $status['label']);
        $this->assertSame('sync', $status['destination']);

        $stockFailurePage = $this->get(route('dashboard'))->assertOk();

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('modules.show', 'sync'), '#').'"[^>]*>\s*<strong>WooCommerce</strong>.*?Eksport stanów: 1.*?</a>#s',
            $stockFailurePage->getContent(),
        );

        $state->update([
            'desired_version' => 2,
            'exported_version' => 2,
            'queue_item_id' => $currentSuccess->id,
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => WordpressIntegration::query()->value('id'),
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'failed',
            'error_message' => 'Błąd importu',
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $status = app(OperationalStatus::class)->navigation()['woocommerce'];

        $this->assertSame('red', $status['tone']);
        $this->assertSame('Importy: 1', $status['label']);
        $this->assertSame('integration_logs', $status['destination']);

        $importFailurePage = $this->get(route('dashboard'))->assertOk();

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('integrations.index').'#logs', '#').'"[^>]*>\s*<strong>WooCommerce</strong>.*?Importy: 1.*?</a>#s',
            $importFailurePage->getContent(),
        );
    }
}
