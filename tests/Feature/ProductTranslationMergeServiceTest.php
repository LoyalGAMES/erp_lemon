<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Products\ProductTranslationMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTranslationMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_merges_a_translation_without_doubling_stock_or_losing_references(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $canonical = $this->product('BLS6A4FE375DAA5D', 'Koszula AVA kremowo-różowa');
        $duplicate = $this->product('WC-B2C-PARENT-750099', 'AVA Cream and Pink Shirt');
        $related = $this->product('RELATED-1', 'Produkt powiązany');

        ProductChannelMapping::query()->create([
            'product_id' => $canonical->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700143',
            'external_sku' => 'BLS6A4FE375DAA5D',
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $duplicate->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '750099',
            'external_sku' => 'BLS6A4FE375DAA5D',
            'stock_sync_enabled' => true,
            'metadata' => ['language' => 'en'],
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $canonical->id,
            'quantity_on_hand' => 2,
            'quantity_reserved' => 0,
            'quantity_available' => 2,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $duplicate->id,
            'quantity_on_hand' => 2,
            'quantity_reserved' => 0,
            'quantity_available' => 2,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'ORDER-1',
            'external_number' => '1001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 319,
        ]);
        $orderLine = $order->lines()->create([
            'product_id' => $duplicate->id,
            'external_line_id' => 'LINE-1',
            'sku' => 'BLS6A4FE375DAA5D',
            'name' => 'AVA Cream and Pink Shirt',
            'quantity' => 3,
        ]);
        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $duplicate->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->external_id,
            'quantity' => 3,
            'status' => 'active',
            'reserved_at' => now(),
        ]);

        $document = WarehouseDocument::query()->create([
            'number' => 'WZ/TEST/1',
            'type' => 'WZ',
            'status' => 'posted',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'posted_at' => now(),
        ]);
        $documentLine = $document->lines()->create([
            'product_id' => $duplicate->id,
            'quantity' => 1,
        ]);
        $ledger = StockLedgerEntry::query()->create([
            'warehouse_document_id' => $document->id,
            'warehouse_document_line_id' => $documentLine->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $duplicate->id,
            'quantity_change' => -1,
            'direction' => 'out',
            'posted_at' => now(),
        ]);

        $return = ReturnCase::query()->create([
            'number' => 'RMA/TEST/1',
            'external_order_id' => $order->id,
            'target_warehouse_id' => $warehouse->id,
            'status' => 'opened',
        ]);
        $returnLine = $return->lines()->create([
            'product_id' => $duplicate->id,
            'external_order_line_id' => $orderLine->id,
            'quantity_expected' => 1,
            'quantity_accepted' => 0,
            'condition' => 'unchecked',
            'disposition' => 'restock',
        ]);

        $invoice = Invoice::query()->create([
            'number' => 'FV/TEST/1',
            'type' => 'vat',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => [],
            'buyer_data' => [],
            'net_total' => 259.35,
            'vat_total' => 59.65,
            'gross_total' => 319,
        ]);
        $invoiceLine = $invoice->lines()->create([
            'product_id' => $duplicate->id,
            'name' => 'AVA Cream and Pink Shirt',
            'sku' => 'BLS6A4FE375DAA5D',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 259.35,
            'net_total' => 259.35,
            'vat_rate' => 23,
            'vat_total' => 59.65,
            'gross_total' => 319,
        ]);
        $packingTask = $order->packingTasks()->create([
            'sales_channel_id' => $channel->id,
            'external_order_line_id' => $orderLine->id,
            'product_id' => $duplicate->id,
            'external_line_id' => 'LINE-1',
            'order_number' => '1001',
            'sku' => 'BLS6A4FE375DAA5D',
            'product_name' => 'AVA Cream and Pink Shirt',
            'quantity_required' => 3,
            'quantity_picked' => 0,
            'status' => 'open',
        ]);
        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $duplicate->id,
            'sales_channel_id' => $channel->id,
            'status' => 'pending',
            'quantity_to_push' => 0,
            'available_at' => now(),
        ]);

        ProductRelation::query()->create([
            'parent_product_id' => $canonical->id,
            'child_product_id' => $related->id,
            'relation_type' => 'upsell',
            'sort_order' => 20,
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $duplicate->id,
            'child_product_id' => $related->id,
            'relation_type' => 'upsell',
            'sort_order' => 10,
        ]);
        $oldAudit = AuditLog::query()->create([
            'action' => 'product.updated',
            'auditable_type' => $duplicate->getMorphClass(),
            'auditable_id' => $duplicate->id,
        ]);

        $service = app(ProductTranslationMergeService::class);
        $context = [
            'reason' => 'polylang_translation',
            'language' => 'en',
            'sales_channel_id' => $channel->id,
            'external_product_id' => '750099',
            'external_sku' => 'BLS6A4FE375DAA5D',
        ];
        $service->merge($canonical, $duplicate, $context);

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $canonical->id)
            ->firstOrFail();
        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('3.0000', (string) $balance->quantity_reserved);
        $this->assertSame('0.0000', (string) $balance->quantity_available);
        $this->assertSame(1, StockBalance::query()->where('warehouse_id', $warehouse->id)->count());

        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $canonical->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700143',
        ]);
        $this->assertDatabaseMissing('product_channel_mappings', [
            'product_id' => $duplicate->id,
        ]);
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $canonical->id,
            'source_product_id' => $duplicate->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '750099',
            'external_key' => ProductChannelAlias::externalKey('750099', null),
            'language' => 'en',
        ]);

        foreach ([$orderLine, $returnLine, $invoiceLine, $packingTask, $queueItem, $documentLine, $ledger] as $reference) {
            $this->assertSame($canonical->id, (int) $reference->fresh()->product_id);
        }
        $this->assertSame(
            $canonical->id,
            (int) StockReservation::query()->where('external_order_id', 'ORDER-1')->firstOrFail()->product_id,
        );
        $this->assertSame($canonical->id, (int) $oldAudit->fresh()->auditable_id);
        $this->assertSame(1, ProductRelation::query()->where('relation_type', 'upsell')->count());
        $this->assertDatabaseHas('product_relations', [
            'parent_product_id' => $canonical->id,
            'child_product_id' => $related->id,
            'relation_type' => 'upsell',
            'sort_order' => 10,
        ]);

        $duplicate->refresh();
        $this->assertFalse($duplicate->is_active);
        $this->assertTrue($duplicate->is_translation);
        $this->assertSame($canonical->id, data_get($duplicate->attributes, 'master.merge.canonical_product_id'));

        // Repeating the repair must neither duplicate aliases/audits nor alter stock.
        $service->merge($canonical->fresh(), $duplicate->fresh(), $context);
        $this->assertSame(1, ProductChannelAlias::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.translation_merged')->count());
        $this->assertSame('2.0000', (string) $balance->fresh()->quantity_on_hand);
        $this->assertSame('3.0000', (string) $balance->fresh()->quantity_reserved);
    }

    private function product(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
    }
}
