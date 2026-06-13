<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderSplitWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_split_order_and_recalculate_reservations(): void
    {
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
        $this->assertCount(1, $order->lines);
        $this->assertSame($firstProduct->id, $order->lines->first()->product_id);
        $this->assertCount(1, $splitOrder->lines);
        $this->assertSame($secondProduct->id, $splitOrder->lines->first()->product_id);
        $this->assertSame('200.00', (string) $order->total_gross);
        $this->assertSame('300.00', (string) $splitOrder->total_gross);

        $this->assertSame(2, StockReservation::query()->where('status', 'active')->count());
        $this->assertDatabaseHas('stock_reservations', [
            'sales_channel_id' => $channel->id,
            'external_order_id' => '9001-SPLIT-1',
            'product_id' => $secondProduct->id,
            'status' => 'active',
        ]);
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
            'number' => 'PZ/' . now()->format('Y') . '/000001',
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
}
