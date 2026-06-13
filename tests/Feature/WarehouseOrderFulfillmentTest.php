<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseOrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_reservations_can_create_and_post_wz_document(): void
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

        $product = Product::query()->create([
            'sku' => 'SKU-WZ',
            'name' => 'Produkt do wysyłki',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 2,
            'quantity_available' => 8,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '501',
            'external_number' => '501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 199,
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '9001',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 2,
        ]);

        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->external_id,
            'quantity' => 2,
            'status' => 'active',
            'reserved_at' => now(),
        ]);

        $this->post(route('orders.wz.create', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();

        $this->assertSame('WZ', $document->type);
        $this->assertSame('draft', $document->status);
        $this->assertSame($warehouse->id, $document->source_warehouse_id);
        $this->assertSame('501', $document->external_reference);
        $this->assertSame('501', $document->metadata['external_order_id']);
        $this->assertSame($channel->id, $document->metadata['sales_channel_id']);
        $this->assertCount(1, $document->lines);
        $this->assertSame('2.0000', (string) $document->lines->first()->quantity);

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('status');

        $document->refresh();
        $this->assertSame('posted', $document->status);

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('8.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('8.0000', (string) $balance->quantity_available);

        $reservation = StockReservation::query()->firstOrFail();
        $this->assertSame('released', $reservation->status);
        $this->assertNotNull($reservation->released_at);

        $this->assertSame(1, StockLedgerEntry::query()->where('warehouse_document_id', $document->id)->count());

        $this->post(route('orders.wz.create', $order))
            ->assertRedirect()
            ->assertSessionHas('status', "WZ {$document->number} jest już zaksięgowane dla tego zamówienia.");

        $this->assertSame(1, WarehouseDocument::query()->count());
    }

    public function test_posted_manual_wz_with_order_reference_blocks_next_wz_action(): void
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

        $product = Product::query()->create([
            'sku' => 'SKU-WZ',
            'name' => 'Produkt do wysyłki',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '501',
            'external_number' => '501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 199,
        ]);

        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->external_id,
            'quantity' => 2,
            'status' => 'active',
            'reserved_at' => now(),
        ]);

        $document = WarehouseDocument::query()->create([
            'number' => 'WZ/' . now()->format('Y') . '/000009',
            'type' => 'WZ',
            'status' => 'posted',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'posted_at' => now(),
            'external_reference' => $order->external_number,
            'metadata' => [],
        ]);

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('WZ zaksięgowane')
            ->assertDontSee('Utwórz WZ');

        $this->post(route('orders.wz.create', $order))
            ->assertRedirect()
            ->assertSessionHas('status', "WZ {$document->number} jest już zaksięgowane dla tego zamówienia.");

        $this->assertSame(1, WarehouseDocument::query()->count());
    }
}
