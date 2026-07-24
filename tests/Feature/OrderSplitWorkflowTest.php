<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Communication\MailSettingsService;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderSplitService;
use App\Services\Packing\PackingTaskService;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrderSplitWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_split_order_and_recalculate_reservations(): void
    {
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

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
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

        $firstProduct = Product::query()->create([
            'sku' => 'SKU-BLUZKA',
            'name' => 'Bluzka',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $secondProduct = Product::query()->create([
            'sku' => 'SKU-BUTY',
            'name' => 'Buty',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        foreach ([$firstProduct, $secondProduct] as $product) {
            StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity_on_hand' => 1,
                'quantity_reserved' => 0,
                'quantity_available' => 1,
            ]);
        }

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '9001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 500,
            'billing_data' => [
                'email' => 'client@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Klient',
            ],
        ]);

        $order->lines()->create([
            'product_id' => $firstProduct->id,
            'external_line_id' => 'line-1',
            'sku' => $firstProduct->sku,
            'name' => $firstProduct->name,
            'quantity' => 1,
            'unit_gross_price' => 200,
        ]);

        $splitLine = $order->lines()->create([
            'product_id' => $secondProduct->id,
            'external_line_id' => 'line-2',
            'sku' => $secondProduct->sku,
            'name' => $secondProduct->name,
            'quantity' => 1,
            'unit_gross_price' => 300,
        ]);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Podziel zamówienie')
            ->assertSee('Utwórz zamówienie częściowe');

        $this->post(route('orders.split', $order), [
            'split_lines' => [
                $splitLine->id => ['quantity' => 1],
            ],
            'note' => 'Buty później',
        ])->assertRedirect()->assertSessionHas('status');

        $order->refresh()->load('lines');
        $splitOrder = ExternalOrder::query()->where('external_id', '9001-SPLIT-1')->with('lines')->firstOrFail();

        $this->assertSame('9001/S1', $splitOrder->external_number);
        $this->assertSame($order->id, $splitOrder->split_parent_order_id);
        $this->assertSame($order->id, $splitOrder->split_root_order_id);
        $this->assertCount(1, $order->lines);
        $this->assertSame($firstProduct->id, $order->lines->first()->product_id);
        $this->assertCount(1, $splitOrder->lines);
        $this->assertSame($secondProduct->id, $splitOrder->lines->first()->product_id);
        $this->assertSame('line-2', $splitOrder->lines->first()->canonical_external_line_id);
        $this->assertSame('200.00', (string) $order->total_gross);
        $this->assertSame('300.00', (string) $splitOrder->total_gross);

        $this->assertSame(2, StockReservation::query()->where('status', 'active')->count());
        $this->assertDatabaseHas('stock_reservations', [
            'sales_channel_id' => $channel->id,
            'external_order_id' => '9001-SPLIT-1',
            'product_id' => $secondProduct->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('customer_messages', [
            'external_order_id' => $order->id,
            'type' => 'automated',
            'trigger' => 'order_partial_created',
            'status' => 'sent',
            'recipient_email' => 'client@example.test',
        ]);
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'order_partial_created')->count());

        $nestedSourceLine = $splitOrder->lines->firstOrFail();
        $this->post(route('orders.split', $splitOrder), [
            'split_lines' => [
                $nestedSourceLine->id => ['quantity' => 1],
            ],
            'note' => 'Drugi poziom wydzielenia',
        ])->assertRedirect()->assertSessionHas('status');

        $nestedOrder = ExternalOrder::query()
            ->where('external_id', '9001-SPLIT-1-SPLIT-1')
            ->with('lines')
            ->firstOrFail();

        $this->assertSame($splitOrder->id, $nestedOrder->split_parent_order_id);
        $this->assertSame($order->id, $nestedOrder->split_root_order_id);
        $this->assertSame('line-2', $nestedOrder->lines->firstOrFail()->canonical_external_line_id);
        $this->assertSame(0, WarehouseDocument::query()->where('type', 'WZ')->count());
    }

    public function test_draft_wz_is_resynced_after_split_and_only_posted_wz_blocks_it(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'SPLIT-CANCELLED-WZ',
            'name' => 'Podział po anulowanym WZ',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'SPLIT-CANCELLED-WZ-WH',
            'name' => 'Magazyn podziału',
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
            'sku' => 'SKU-SPLIT-AFTER-CANCEL',
            'name' => 'Produkt do wydzielenia po anulowaniu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 2,
            'quantity_reserved' => 0,
            'quantity_available' => 2,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '869614',
            'external_number' => '869614',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 240,
        ]);
        $line = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '869614-line-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 2,
            'unit_gross_price' => 120,
        ]);
        $wz = WarehouseDocument::query()->create([
            'number' => 'WZ/869614',
            'type' => 'WZ',
            'status' => 'draft',
            'source_warehouse_id' => $warehouse->id,
            'external_reference' => '869614',
            'document_date' => now(),
            'metadata' => [
                'sales_channel_id' => $channel->id,
                'external_order_id' => '869614',
                'external_order_number' => '869614',
            ],
        ]);
        $wz->lines()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'metadata' => ['source' => 'stock_reservation'],
        ]);
        PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'external_order_line_id' => $line->id,
            'product_id' => $product->id,
            'external_line_id' => $line->external_line_id,
            'order_number' => '869614',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 2,
            'quantity_picked' => 1,
            'status' => 'cancelled',
        ]);

        $wz->update(['status' => 'posted']);
        $blocked = app(OrderSplitService::class)->availability($order->fresh());
        $this->assertFalse($blocked['available']);
        $this->assertStringContainsString('zaksięgowany dokument WZ', implode(' ', $blocked['reasons']));

        $wz->update(['status' => 'draft']);

        $available = app(OrderSplitService::class)->availability($order->fresh());
        $this->assertTrue($available['available']);
        $this->assertSame([], $available['reasons']);

        $splitOrder = app(OrderSplitService::class)->split(
            $order->fresh(),
            [$line->id => 1],
            source: 'manual',
            requestUuid: (string) Str::uuid(),
        );

        $this->assertSame('869614/S1', $splitOrder->external_number);
        $this->assertSame('1.0000', (string) $splitOrder->lines()->sole()->quantity);
        $this->assertSame('draft', $wz->fresh()->status);
        $this->assertSame('1.0000', (string) $wz->lines()->sole()->quantity);

        $splitWz = app(OrderFulfillmentStatusService::class)
            ->wzDocumentsForOrder($splitOrder)
            ->with('lines')
            ->sole();

        $this->assertSame('draft', $splitWz->status);
        $this->assertNotSame($wz->id, $splitWz->id);
        $this->assertSame('1.0000', (string) $splitWz->lines->sole()->quantity);
    }

    public function test_split_reallocates_woo_reflection_without_creating_phantom_stock(): void
    {
        Mail::fake();
        $channel = SalesChannel::query()->create([
            'code' => 'SPLIT-REFLECTION',
            'name' => 'Refleksja stanu przy podziale',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'SPLIT-REFLECTION-WH',
            'name' => 'Magazyn refleksji',
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
            'sku' => 'SPLIT-REFLECTED-SKU',
            'name' => 'Produkt ujęty w stanie Woo',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $observedAt = now();
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'SPLIT-REFLECTED-1001',
            'external_number' => 'SPLIT/REFLECTED/1001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 246,
            'external_created_at' => $observedAt->copy()->subHour(),
        ]);
        $line = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'reflected-line',
            'canonical_external_line_id' => 'reflected-line',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 2,
            'unit_gross_price' => 123,
        ]);
        $balance = StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
            'quantity_available' => 10,
            'source_sales_channel_id' => $channel->id,
            'source_available_quantity' => 8,
            'source_observed_at' => $observedAt,
            'source_reflected_order_quantities' => [$order->external_id => 2],
        ]);

        app(StockReservationService::class)->syncForOrder($order);
        $requestUuid = (string) Str::uuid();
        $child = app(OrderSplitService::class)->split(
            $order->fresh(),
            [$line->id => 1],
            requestUuid: $requestUuid,
        );
        $messageCountAfterFirstSplit = CustomerMessage::query()
            ->where('trigger', 'order_partial_created')
            ->count();
        $retriedChild = app(OrderSplitService::class)->split(
            $order->fresh(),
            [$line->id => 1],
            requestUuid: $requestUuid,
        );

        try {
            app(OrderSplitService::class)->split(
                $order->fresh(),
                [$line->id => 1],
                note: 'Zmieniona treść tego samego żądania',
                requestUuid: $requestUuid,
            );
            $this->fail('Reusing a split UUID for a changed command must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('został już użyty do innego podziału', $exception->getMessage());
        }

        $balance->refresh();

        $this->assertSame($child->id, $retriedChild->id);
        $this->assertSame(2, ExternalOrder::query()->count());
        $this->assertSame($messageCountAfterFirstSplit, CustomerMessage::query()
            ->where('trigger', 'order_partial_created')
            ->count());
        $this->assertSame('10.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('8.0000', (string) $balance->quantity_available);
        $this->assertEqualsWithDelta(1, (float) data_get(
            $balance->source_reflected_order_quantities,
            $order->external_id,
        ), 0.00001);
        $this->assertEqualsWithDelta(1, (float) data_get(
            $balance->source_reflected_order_quantities,
            $child->external_id,
        ), 0.00001);
        $this->assertEqualsWithDelta(
            2,
            array_sum((array) $balance->source_reflected_order_quantities),
            0.00001,
        );
    }

    public function test_split_order_waits_for_stock_and_is_allocated_after_pz_posting(): void
    {
        Queue::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
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
            'sku' => 'SKU-BUTY-PROD',
            'name' => 'Buty z produkcji',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9101',
            'external_number' => '9101',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 300,
        ]);

        $splitLine = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_gross_price' => 300,
        ]);

        $this->post(route('orders.split', $order), [
            'split_lines' => [
                $splitLine->id => ['quantity' => 1],
            ],
            'note' => 'Towar zejdzie z produkcji później',
        ])->assertRedirect()->assertSessionHas('status');

        $splitOrder = ExternalOrder::query()
            ->where('external_id', '9101-SPLIT-1')
            ->firstOrFail();

        $this->assertDatabaseHas('stock_reservations', [
            'warehouse_id' => $warehouse->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $splitOrder->external_id,
            'product_id' => $product->id,
            'quantity' => 1,
            'status' => 'waiting',
        ]);
        $this->assertSame(0, StockReservation::query()->where('status', 'active')->count());

        $document = WarehouseDocument::query()->create([
            'number' => 'PZ/'.now()->format('Y').'/000001',
            'type' => 'PZ',
            'status' => 'draft',
            'destination_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'notes' => 'Przyjęcie produkcji dla zaległego zamówienia',
        ]);
        $document->lines()->create([
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('stock_reservations', [
            'warehouse_id' => $warehouse->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $splitOrder->external_id,
            'product_id' => $product->id,
            'quantity' => 1,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('stock_reservations', [
            'external_order_id' => $splitOrder->external_id,
            'product_id' => $product->id,
            'status' => 'waiting',
        ]);

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('1.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('1.0000', (string) $balance->quantity_reserved);
        $this->assertSame('0.0000', (string) $balance->quantity_available);
    }

    public function test_splitting_single_line_order_cancels_source_packing_task(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn glowny',
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
            'sku' => 'SKU-SINGLE-SPLIT',
            'name' => 'Produkt do jednego splitu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 1,
            'quantity_reserved' => 0,
            'quantity_available' => 1,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9201',
            'external_number' => '9201',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 159,
            'external_created_at' => now(),
        ]);

        $line = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_gross_price' => 159,
        ]);

        app(PackingTaskService::class)->syncForOrder($order);

        $sourceTask = PackingTask::query()->firstOrFail();
        $this->assertSame($order->id, $sourceTask->external_order_id);
        $this->assertSame('open', $sourceTask->status);

        $this->post(route('orders.split', $order), [
            'split_lines' => [
                $line->id => ['quantity' => 1],
            ],
            'note' => 'Wydzielone do osobnej wysylki',
        ])->assertRedirect()->assertSessionHas('status');

        $order->refresh()->load('lines');
        $splitOrder = ExternalOrder::query()
            ->where('external_id', '9201-SPLIT-1')
            ->with('lines')
            ->firstOrFail();

        $this->assertCount(0, $order->lines);
        $this->assertSame('0.00', (string) $order->total_gross);
        $this->assertSame('9201/S1', $splitOrder->external_number);
        $this->assertCount(1, $splitOrder->lines);

        $sourceTask->refresh();
        $this->assertSame('cancelled', $sourceTask->status);

        $activeTask = PackingTask::query()->where('status', 'open')->firstOrFail();
        $this->assertSame($splitOrder->id, $activeTask->external_order_id);
        $this->assertSame('9201/S1', $activeTask->order_number);
        $this->assertSame('1.0000', (string) $activeTask->quantity_required);
        $this->assertSame(1, PackingTask::query()->whereIn('status', ['open', 'picked'])->count());

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
            '*' => function ($request) use ($product) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([
                    [
                        'id' => 9201,
                        'number' => '9201',
                        'status' => 'processing',
                        'currency' => 'PLN',
                        'total' => '159.00',
                        'line_items' => [
                            [
                                'id' => 'line-1',
                                'sku' => $product->sku,
                                'name' => $product->name,
                                'quantity' => 1,
                                'subtotal' => '159.00',
                                'total' => '159.00',
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        app(WooCommerceImportService::class)->importOrders($integration);

        $order->refresh()->load('lines');
        $splitOrder->refresh()->load('lines');

        $this->assertCount(0, $order->lines);
        $this->assertSame('0.00', (string) $order->total_gross);
        $this->assertCount(1, $splitOrder->lines);
        $this->assertSame('1.0000', (string) $splitOrder->lines->first()->quantity);
        $this->assertSame(1, PackingTask::query()->whereIn('status', ['open', 'picked'])->count());
    }

    public function test_woo_reimport_preserves_a_nested_split_without_sku_fallback(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'NESTED-WOO',
            'name' => 'Nested Woo',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $rootOrder = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9301',
            'external_number' => '9301',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 0,
        ]);
        $childOrder = ExternalOrder::query()->create([
            'split_parent_order_id' => $rootOrder->id,
            'split_root_order_id' => $rootOrder->id,
            'sales_channel_id' => $channel->id,
            'external_id' => '9301-SPLIT-1',
            'external_number' => '9301/S1',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 0,
        ]);
        $nestedOrder = ExternalOrder::query()->create([
            'split_parent_order_id' => $childOrder->id,
            'split_root_order_id' => $rootOrder->id,
            'sales_channel_id' => $channel->id,
            'external_id' => '9301-SPLIT-1-SPLIT-1',
            'external_number' => '9301/S1/S1',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 200,
        ]);
        $nestedOrder->lines()->create([
            'external_line_id' => 'line-1-S1-S1',
            'canonical_external_line_id' => 'line-1',
            'sku' => null,
            'name' => 'Produkt bez SKU',
            'quantity' => 2,
            'unit_gross_price' => 100,
            'raw_payload' => [
                'sempre_erp_split' => [
                    'source_external_line_id' => 'line-1-S1',
                    'root_external_line_id' => 'line-1',
                ],
            ],
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Nested Woo import',
            'base_url' => 'https://nested-shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_nested'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_nested'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);

        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ((int) ($query['page'] ?? 1) > 1) {
                return Http::response([]);
            }

            return Http::response([[
                'id' => 9301,
                'number' => '9301',
                'status' => 'processing',
                'currency' => 'PLN',
                'total' => '200.00',
                'line_items' => [[
                    'id' => 'line-1',
                    'sku' => '',
                    'name' => 'Produkt bez SKU',
                    'quantity' => 2,
                    'subtotal' => '200.00',
                    'total' => '200.00',
                ]],
            ]]);
        });

        app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertCount(0, $rootOrder->refresh()->lines);
        $this->assertCount(0, $childOrder->refresh()->lines);
        $this->assertSame('2.0000', (string) $nestedOrder->refresh()->lines->firstOrFail()->quantity);
        $this->assertSame('line-1', $nestedOrder->lines->firstOrFail()->canonical_external_line_id);
    }
}
