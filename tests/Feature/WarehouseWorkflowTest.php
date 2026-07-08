<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportStockToWooCommerceJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\AuditLog;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarehouseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_product_warehouse_and_post_pz_document(): void
    {
        $this->post('/products', [
            'sku' => 'SKU-001',
            'name' => 'Test product',
            'unit' => 'szt',
            'vat_rate' => 23,
        ])->assertRedirect();

        $this->post('/warehouses', [
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
        ])->assertRedirect();

        $product = Product::query()->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();

        $this->post('/documents', [
            'type' => 'PZ',
            'destination_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ])->assertRedirect();

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();

        $this->assertSame('draft', $document->status);
        $this->assertSame('PZ', $document->type);
        $this->assertCount(1, $document->lines);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'warehouse_document.created',
            'auditable_type' => WarehouseDocument::class,
            'auditable_id' => $document->id,
        ]);

        $this->post(route('documents.post', $document))->assertRedirect();

        $document->refresh();
        $this->assertSame('posted', $document->status);

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('10.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('10.0000', (string) $balance->quantity_available);

        $this->assertSame(1, StockLedgerEntry::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'warehouse_document.posted',
            'auditable_type' => WarehouseDocument::class,
            'auditable_id' => $document->id,
        ]);

        $this->get('/documents')
            ->assertOk()
            ->assertSee('Szczegóły')
            ->assertSee('Drukuj');

        $this->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee($document->number)
            ->assertSee('Pozycje dokumentu')
            ->assertSee('Ruchy ledger')
            ->assertSee('SKU-001')
            ->assertSee('Test product')
            ->assertSee('10,0000');

        $this->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('Dokument magazynowy')
            ->assertSee($document->number)
            ->assertSee('SKU-001')
            ->assertSee('Wystawił');

        $postedAudit = AuditLog::query()->where('action', 'warehouse_document.posted')->firstOrFail();
        $this->assertSame('draft', $postedAudit->before['status']);
        $this->assertSame('posted', $postedAudit->after['status']);
        $this->assertSame('SKU-001', $postedAudit->after['balance_changes'][0]['sku']);
    }

    public function test_document_form_persists_document_date_purchase_price_location_and_numbering_pattern(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-DOC-001',
            'name' => 'Produkt do dokumentu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
            'quantity_available' => 10,
        ]);

        $this->put(route('settings.documents.update'), [
            'pattern' => '{TYPE}/{MM}/{YYYY}/{SEQ}',
            'padding' => 4,
        ])->assertRedirect()->assertSessionHas('status');

        $this->put(route('settings.locations.update'), [
            'locations_text' => "A-01-01\nB-02-03",
        ])->assertRedirect()->assertSessionHas('status');

        $this->get(route('documents.create'))
            ->assertOk()
            ->assertSee('Data wystawienia')
            ->assertSee('Cena zakupu')
            ->assertSee('Lokalizacja')
            ->assertSee('A-01-01')
            ->assertSee('data-product-picker-checkbox', false);

        $this->post(route('documents.store'), [
            'type' => 'PZ',
            'document_date' => '2026-06-01',
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_gross_price' => 149.99,
                    'location' => 'A-01-01',
                ],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();
        $line = $document->lines->firstOrFail();

        $this->assertSame('PZ/06/2026/0001', $document->number);
        $this->assertSame('2026-06-01', $document->document_date?->toDateString());
        $this->assertSame('149.9900', (string) $line->unit_gross_price);
        $this->assertSame('A-01-01', $line->metadata['location']);
        $this->assertNull($line->notes);

        $this->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('149,99 PLN')
            ->assertSee('A-01-01');

        $this->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('149,99 PLN')
            ->assertSee('A-01-01');
    }

    public function test_documents_export_respects_filters_and_outputs_line_rows(): void
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $exportedProduct = Product::query()->create([
            'sku' => 'SKU-EXPORT',
            'name' => 'Exportowany produkt',
            'unit' => 'szt',
            'ean' => '590000000001',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $otherProduct = Product::query()->create([
            'sku' => 'SKU-OTHER',
            'name' => 'Inny produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('documents.store'), [
            'type' => 'PZ',
            'document_date' => '2026-06-02',
            'destination_warehouse_id' => $warehouse->id,
            'notes' => 'Dostawa do eksportu',
            'lines' => [
                [
                    'product_id' => $exportedProduct->id,
                    'quantity' => 7,
                    'unit_gross_price' => 19.99,
                    'location' => 'A-10',
                ],
            ],
        ])->assertRedirect();

        $this->post(route('documents.store'), [
            'type' => 'RX',
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                [
                    'product_id' => $otherProduct->id,
                    'quantity' => 1,
                ],
            ],
        ])->assertRedirect();

        $this->get(route('documents.index', ['type' => 'PZ', 'q' => 'export']))
            ->assertOk()
            ->assertSee('Eksport CSV')
            ->assertSee(route('documents.export'), false)
            ->assertSee('q=export', false)
            ->assertSee('type=PZ', false);

        $response = $this->get(route('documents.export', [
            'type' => 'PZ',
            'q' => 'export',
        ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'dokumenty-magazynowe-',
            (string) $response->headers->get('Content-Disposition'),
        );

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Numer;Typ;Status', $csv);
        $this->assertStringContainsString('"Data dokumentu"', $csv);
        $this->assertStringContainsString('PZ', $csv);
        $this->assertStringContainsString('M1', $csv);
        $this->assertStringContainsString('SKU-EXPORT', $csv);
        $this->assertStringContainsString('Exportowany produkt', $csv);
        $this->assertStringContainsString('590000000001', $csv);
        $this->assertStringContainsString('7,0000', $csv);
        $this->assertStringContainsString('19,99', $csv);
        $this->assertStringContainsString('A-10', $csv);
        $this->assertStringContainsString('Dostawa do eksportu', $csv);
        $this->assertStringNotContainsString('SKU-OTHER', $csv);
    }

    public function test_operator_can_edit_warehouse_and_open_filtered_product_list(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-WH-STOCK',
            'name' => 'Produkt w magazynie',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn stary',
            'type' => 'physical',
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 4,
            'quantity_reserved' => 1,
            'quantity_available' => 3,
        ]);

        $this->get(route('warehouses.index'))
            ->assertOk()
            ->assertSee('Produkty (1)')
            ->assertSee(route('products.index', ['warehouse' => $warehouse->id]), false)
            ->assertSee(route('warehouses.edit', $warehouse), false)
            ->assertDontSee('Produkty i stany');

        $this->get(route('products.index', ['warehouse' => $warehouse->id]))
            ->assertOk()
            ->assertSee('Produkt w magazynie')
            ->assertSee('Magazyn stary');

        $this->get(route('warehouses.edit', $warehouse))
            ->assertOk()
            ->assertSee('Konfiguracja magazynu')
            ->assertSee('Produkty w tym magazynie');

        $this->put(route('warehouses.update', $warehouse), [
            'code' => 'M2',
            'name' => 'Magazyn poprawiony',
            'type' => 'returns',
            'is_active' => 1,
            'sales_channel_ids' => [$channel->id],
        ])->assertRedirect()->assertSessionHas('status');

        $warehouse->refresh()->load('routes');

        $this->assertSame('M2', $warehouse->code);
        $this->assertSame('Magazyn poprawiony', $warehouse->name);
        $this->assertSame('returns', $warehouse->type);
        $this->assertTrue($warehouse->routes->contains('sales_channel_id', $channel->id));
    }

    public function test_warehouse_route_update_queues_stock_sync_for_changed_channels(): void
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

        $this->createStockExportIntegration($b2c);
        $this->createStockExportIntegration($b2b);

        $product = Product::query()->create([
            'sku' => 'SKU-ROUTE-SYNC',
            'name' => 'Produkt routing sync',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn M1',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $warehouse->routes()->create([
            'sales_channel_id' => $b2c->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 8,
            'quantity_reserved' => 1,
            'quantity_available' => 7,
        ]);

        foreach ([$b2c, $b2b] as $channel) {
            ProductChannelMapping::query()->create([
                'product_id' => $product->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => (string) (1000 + $channel->id),
                'external_sku' => $product->sku,
                'stock_sync_enabled' => true,
            ]);
        }

        $this->put(route('warehouses.update', $warehouse), [
            'code' => 'M1',
            'name' => 'Magazyn M1',
            'type' => 'physical',
            'is_active' => 1,
            'sales_channel_ids' => [$b2c->id, $b2b->id],
        ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $queueItems = StockSyncQueueItem::query()
            ->orderBy('sales_channel_id')
            ->get();

        $this->assertCount(2, $queueItems);
        $this->assertEqualsCanonicalizing(
            [$b2c->id, $b2b->id],
            $queueItems->pluck('sales_channel_id')->all(),
        );

        foreach ($queueItems as $queueItem) {
            $this->assertSame($warehouse->id, $queueItem->warehouse_id);
            $this->assertSame($product->id, $queueItem->product_id);
            $this->assertSame('7.0000', (string) $queueItem->quantity_to_push);
            $this->assertSame('warehouse_routes_updated', $queueItem->metadata['reason']);
            $this->assertSame('channel_warehouse_route_aggregate', $queueItem->metadata['calculation']);
            $this->assertSame([$b2c->id], $queueItem->metadata['before_sales_channel_ids']);
            $this->assertEqualsCanonicalizing([$b2c->id, $b2b->id], $queueItem->metadata['after_sales_channel_ids']);
        }

        Queue::assertPushed(ExportStockToWooCommerceJob::class, 2);
    }

    public function test_mm_document_moves_stock_between_warehouses(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-002',
            'name' => 'Moved product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $source = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Source',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $target = Warehouse::query()->create([
            'code' => 'M2',
            'name' => 'Target',
            'type' => 'physical',
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 20,
            'quantity_reserved' => 0,
            'quantity_available' => 20,
        ]);

        $this->post('/documents', [
            'type' => 'MM',
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $target->id,
            'product_id' => $product->id,
            'quantity' => 7,
        ])->assertRedirect();

        $document = WarehouseDocument::query()->latest()->firstOrFail();

        $this->post(route('documents.post', $document))->assertRedirect();

        $this->assertSame('13.0000', (string) StockBalance::query()
            ->where('warehouse_id', $source->id)
            ->where('product_id', $product->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->assertSame('7.0000', (string) StockBalance::query()
            ->where('warehouse_id', $target->id)
            ->where('product_id', $product->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->assertSame(2, StockLedgerEntry::query()->where('warehouse_document_id', $document->id)->count());
    }

    public function test_receipt_document_types_post_to_destination_warehouse(): void
    {
        foreach (['PW', 'RX', 'ZW', 'KOR'] as $type) {
            $product = Product::query()->create([
                'sku' => "SKU-{$type}-IN",
                'name' => "{$type} receipt product",
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
            ]);

            $warehouse = Warehouse::query()->create([
                'code' => "WH{$type}",
                'name' => "{$type} destination",
                'type' => 'physical',
                'is_active' => true,
            ]);

            $document = WarehouseDocument::query()->create([
                'number' => "{$type}/TEST",
                'type' => $type,
                'status' => 'draft',
                'destination_warehouse_id' => $warehouse->id,
                'document_date' => now(),
            ]);
            $document->lines()->create([
                'product_id' => $product->id,
                'quantity' => 2,
            ]);

            $this->post(route('documents.post', $document))
                ->assertRedirect()
                ->assertSessionHas('status');

            $this->assertSame('2.0000', (string) StockBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->firstOrFail()
                ->quantity_on_hand);

            $this->assertDatabaseHas('stock_ledger_entries', [
                'warehouse_document_id' => $document->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'direction' => 'in',
            ]);
        }

        $this->assertSame(4, StockLedgerEntry::query()->count());
    }

    public function test_rw_document_issues_stock_from_source_warehouse(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-RW-OUT',
            'name' => 'RW issue product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 5,
            'quantity_reserved' => 0,
            'quantity_available' => 5,
        ]);

        $document = WarehouseDocument::query()->create([
            'number' => 'RW/TEST',
            'type' => 'RW',
            'status' => 'draft',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
        ]);
        $document->lines()->create([
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('status');

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_available);
        $this->assertDatabaseHas('stock_ledger_entries', [
            'warehouse_document_id' => $document->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'direction' => 'out',
        ]);
    }

    public function test_document_posting_rejects_invalid_warehouse_topology(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-BAD-TOPOLOGY',
            'name' => 'Bad topology product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $cases = [
            ['WZ', null, null, 'Dokument WZ wymaga magazynu źródłowego.'],
            ['RW', null, null, 'Dokument RW wymaga magazynu źródłowego.'],
            ['PZ', null, null, 'Dokument PZ wymaga magazynu docelowego.'],
            ['PW', null, null, 'Dokument PW wymaga magazynu docelowego.'],
            ['MM', null, $warehouse->id, 'Dokument MM wymaga magazynu źródłowego i docelowego.'],
            ['MM', $warehouse->id, $warehouse->id, 'MM wymaga różnych magazynów.'],
        ];

        foreach ($cases as $index => [$type, $sourceWarehouseId, $destinationWarehouseId, $message]) {
            $document = WarehouseDocument::query()->create([
                'number' => "BAD/{$index}/{$type}",
                'type' => $type,
                'status' => 'draft',
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'document_date' => now(),
            ]);
            $document->lines()->create([
                'product_id' => $product->id,
                'quantity' => 1,
            ]);

            $this->post(route('documents.post', $document))
                ->assertRedirect()
                ->assertSessionHas('error', $message);

            $this->assertSame('draft', $document->refresh()->status);
        }

        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame(0, StockBalance::query()->count());
    }

    public function test_operator_can_create_and_post_multi_line_pz_document(): void
    {
        $firstProduct = Product::query()->create([
            'sku' => 'SKU-MULTI-1',
            'name' => 'First multi product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $secondProduct = Product::query()->create([
            'sku' => 'SKU-MULTI-2',
            'name' => 'Second multi product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $this->get(route('documents.create'))
            ->assertOk()
            ->assertSee('Pozycje dokumentu')
            ->assertSee('Dodaj pozycję')
            ->assertSee('data-document-product-modal', false);

        $this->post('/documents', [
            'type' => 'PZ',
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $firstProduct->id, 'quantity' => 5, 'notes' => 'karton A'],
                ['product_id' => $secondProduct->id, 'quantity' => 3, 'notes' => 'karton B'],
            ],
        ])->assertRedirect();

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();
        $this->assertCount(2, $document->lines);

        $createdAudit = AuditLog::query()->where('action', 'warehouse_document.created')->firstOrFail();
        $this->assertCount(2, $createdAudit->after['lines']);

        $this->post(route('documents.post', $document))->assertRedirect();

        $this->assertSame('5.0000', (string) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $firstProduct->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->assertSame('3.0000', (string) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $secondProduct->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->assertSame(2, StockLedgerEntry::query()->where('warehouse_document_id', $document->id)->count());

        $postedAudit = AuditLog::query()->where('action', 'warehouse_document.posted')->firstOrFail();
        $this->assertCount(2, $postedAudit->after['balance_changes']);
    }

    public function test_documents_index_can_be_filtered_and_paginated(): void
    {
        $mainWarehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $secondaryWarehouse = Warehouse::query()->create([
            'code' => 'M2',
            'name' => 'Secondary',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $matchingProduct = Product::query()->create([
            'sku' => 'SKU-FILTER-DOC',
            'ean' => '5901111111111',
            'name' => 'Filtrowany produkt dokumentu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $otherProduct = Product::query()->create([
            'sku' => 'SKU-OTHER-DOC',
            'name' => 'Inny produkt dokumentu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $matchingDocument = WarehouseDocument::query()->create([
            'number' => 'PZ/2026/FILTER',
            'type' => 'PZ',
            'status' => 'posted',
            'destination_warehouse_id' => $mainWarehouse->id,
            'document_date' => now(),
            'posted_at' => now(),
            'notes' => 'partia do wyszukania',
        ]);
        $matchingDocument->lines()->create([
            'product_id' => $matchingProduct->id,
            'quantity' => 2,
        ]);

        $otherDocument = WarehouseDocument::query()->create([
            'number' => 'WZ/2026/OTHER',
            'type' => 'WZ',
            'status' => 'draft',
            'source_warehouse_id' => $secondaryWarehouse->id,
            'document_date' => now()->subMinute(),
        ]);
        $otherDocument->lines()->create([
            'product_id' => $otherProduct->id,
            'quantity' => 1,
        ]);

        for ($index = 1; $index <= 30; $index++) {
            $document = WarehouseDocument::query()->create([
                'number' => sprintf('PZ/2026/PAGE-%02d', $index),
                'type' => 'PZ',
                'status' => 'draft',
                'destination_warehouse_id' => $mainWarehouse->id,
                'document_date' => now()->subMinutes($index + 2),
            ]);
            $document->lines()->create([
                'product_id' => $otherProduct->id,
                'quantity' => 1,
            ]);
        }

        $this->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Szukaj')
            ->assertSee('Typ')
            ->assertSee('Status')
            ->assertSee('Magazyn')
            ->assertSee('Strona 1 z 2')
            ->assertSee('Następna');

        $this->get(route('documents.index', [
            'q' => 'FILTER-DOC',
            'type' => 'PZ',
            'status' => 'posted',
            'warehouse' => $mainWarehouse->id,
        ]))
            ->assertOk()
            ->assertSee('Wynik: 1 dokumentów po filtrach')
            ->assertSee('PZ/2026/FILTER')
            ->assertSee('SKU-FILTER-DOC')
            ->assertDontSee('WZ/2026/OTHER')
            ->assertDontSee('SKU-OTHER-DOC');
    }

    public function test_operator_can_edit_draft_document_before_posting(): void
    {
        $firstProduct = Product::query()->create([
            'sku' => 'SKU-EDIT-1',
            'name' => 'Edited first product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $secondProduct = Product::query()->create([
            'sku' => 'SKU-EDIT-2',
            'name' => 'Edited second product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $this->post('/documents', [
            'type' => 'PZ',
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $firstProduct->id, 'quantity' => 2, 'notes' => 'pierwotnie'],
            ],
            'notes' => 'stara notatka',
        ])->assertRedirect();

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();

        $this->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Utwórz dokument')
            ->assertSee('Edytuj')
            ->assertDontSee('data-document-lines', false);

        $this->get(route('documents.create'))
            ->assertOk()
            ->assertSee('data-document-form', false)
            ->assertSee('data-document-product-modal', false)
            ->assertSee('Szybka wyszukiwarka po SKU')
            ->assertSee('PZ przyjmuje towar do magazynu')
            ->assertSee('SKU-EDIT-1');

        $this->get(route('documents.edit', $document))
            ->assertOk()
            ->assertSee('Edycja dokumentu')
            ->assertSee('SKU-EDIT-1')
            ->assertSee('data-document-product-modal', false)
            ->assertSee('Dodaj pozycję')
            ->assertSee('Zapisz szkic');

        $this->put(route('documents.update', $document), [
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $firstProduct->id, 'quantity' => 4, 'notes' => 'poprawiono'],
                ['product_id' => $secondProduct->id, 'quantity' => 3, 'notes' => 'dodano'],
            ],
            'notes' => 'nowa notatka',
        ])
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('status');

        $document->refresh()->load('lines');
        $this->assertSame('draft', $document->status);
        $this->assertSame('nowa notatka', $document->notes);
        $this->assertCount(2, $document->lines);
        $this->assertSame('4.0000', (string) $document->lines->firstWhere('product_id', $firstProduct->id)->quantity);
        $this->assertSame('3.0000', (string) $document->lines->firstWhere('product_id', $secondProduct->id)->quantity);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'warehouse_document.updated',
            'auditable_type' => WarehouseDocument::class,
            'auditable_id' => $document->id,
        ]);

        $this->post(route('documents.post', $document))->assertRedirect();

        $this->assertSame('4.0000', (string) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $firstProduct->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->assertSame('3.0000', (string) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $secondProduct->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->get(route('documents.edit', $document->refresh()))
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('error', 'Edytować można tylko dokument w statusie szkic.');

        $this->put(route('documents.update', $document), [
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $firstProduct->id, 'quantity' => 9],
            ],
        ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Edytować można tylko dokument w statusie szkic.');
    }

    public function test_posted_pz_document_can_be_cancelled_with_reversal_ledger(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-CANCEL-PZ',
            'name' => 'Cancelable PZ product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $this->post('/documents', [
            'type' => 'PZ',
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10],
            ],
        ])->assertRedirect();

        $document = WarehouseDocument::query()->firstOrFail();

        $this->post(route('documents.post', $document))->assertRedirect();
        $this->post(route('documents.cancel', $document))->assertRedirect()->assertSessionHas('status');

        $document->refresh();
        $this->assertSame('cancelled', $document->status);
        $this->assertNotNull($document->cancelled_at);

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('0.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_available);

        $this->assertSame(2, StockLedgerEntry::query()->where('warehouse_document_id', $document->id)->count());
        $this->assertDatabaseHas('stock_ledger_entries', [
            'warehouse_document_id' => $document->id,
            'direction' => 'out',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'warehouse_document.cancelled',
            'auditable_type' => WarehouseDocument::class,
            'auditable_id' => $document->id,
        ]);

        $cancelAudit = AuditLog::query()->where('action', 'warehouse_document.cancelled')->firstOrFail();
        $this->assertSame('posted', $cancelAudit->before['status']);
        $this->assertSame('cancelled', $cancelAudit->after['status']);
        $this->assertSame(-10, $cancelAudit->after['balance_changes'][0]['change']);

        $this->post(route('documents.cancel', $document))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'warehouse_document.cancel_failed',
            'auditable_type' => WarehouseDocument::class,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_draft_document_can_be_cancelled_without_stock_movements(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-CANCEL-DRAFT',
            'name' => 'Draft cancel product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $this->post('/documents', [
            'type' => 'PZ',
            'destination_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ])->assertRedirect();

        $document = WarehouseDocument::query()->firstOrFail();

        $this->post(route('documents.cancel', $document))->assertRedirect()->assertSessionHas('status');

        $document->refresh();
        $this->assertSame('cancelled', $document->status);
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame(0, StockBalance::query()->count());

        $cancelAudit = AuditLog::query()->where('action', 'warehouse_document.cancelled')->firstOrFail();
        $this->assertSame('draft', $cancelAudit->before['status']);
        $this->assertSame([], $cancelAudit->after['balance_changes']);
        $this->assertFalse($cancelAudit->metadata['reversal_required']);
    }

    public function test_posted_mm_document_can_be_cancelled_and_restore_both_warehouses(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-CANCEL-MM',
            'name' => 'Cancelable MM product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $source = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Source',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $target = Warehouse::query()->create([
            'code' => 'M2',
            'name' => 'Target',
            'type' => 'physical',
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 12,
            'quantity_reserved' => 0,
            'quantity_available' => 12,
        ]);

        $this->post('/documents', [
            'type' => 'MM',
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $target->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ])->assertRedirect();

        $document = WarehouseDocument::query()->firstOrFail();

        $this->post(route('documents.post', $document))->assertRedirect();
        $this->post(route('documents.cancel', $document))->assertRedirect();

        $this->assertSame('12.0000', (string) StockBalance::query()
            ->where('warehouse_id', $source->id)
            ->where('product_id', $product->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->assertSame('0.0000', (string) StockBalance::query()
            ->where('warehouse_id', $target->id)
            ->where('product_id', $product->id)
            ->firstOrFail()
            ->quantity_on_hand);

        $this->assertSame(4, StockLedgerEntry::query()->where('warehouse_document_id', $document->id)->count());
    }

    public function test_failed_document_posting_is_audited(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-003',
            'name' => 'Missing stock product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Source',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $this->post('/documents', [
            'type' => 'WZ',
            'source_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertRedirect();

        $document = WarehouseDocument::query()->latest()->firstOrFail();

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('error');

        $document->refresh();
        $this->assertSame('draft', $document->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'warehouse_document.post_failed',
            'auditable_type' => WarehouseDocument::class,
            'auditable_id' => $document->id,
        ]);

        $failedAudit = AuditLog::query()->where('action', 'warehouse_document.post_failed')->firstOrFail();
        $this->assertStringContainsString('Brak stanu dla SKU SKU-003', $failedAudit->metadata['error']);
    }

    public function test_stock_sync_uses_aggregate_availability_for_channel_routes(): void
    {
        Queue::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $this->createStockExportIntegration($channel);

        $product = Product::query()->create([
            'sku' => 'SKU-AGG',
            'name' => 'Aggregated stock product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

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

        $internal = Warehouse::query()->create([
            'code' => 'M3',
            'name' => 'Internal',
            'type' => 'internal',
            'is_active' => true,
        ]);

        $main->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 10,
        ]);

        $secondary->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 1,
            'priority' => 20,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $main->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
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
            'warehouse_id' => $internal->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 99,
            'quantity_reserved' => 0,
            'quantity_available' => 99,
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);

        $this->post('/documents', [
            'type' => 'PZ',
            'destination_warehouse_id' => $main->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $document = WarehouseDocument::query()->latest()->firstOrFail();

        $this->post(route('documents.post', $document))->assertRedirect();

        $queueItem = StockSyncQueueItem::query()->firstOrFail();

        $this->assertSame($channel->id, $queueItem->sales_channel_id);
        $this->assertSame('16.0000', (string) $queueItem->quantity_to_push);
        $this->assertSame('channel_warehouse_route_aggregate', $queueItem->metadata['calculation']);
        $this->assertCount(2, $queueItem->metadata['breakdown']);
        $this->assertSame('M1', $queueItem->metadata['breakdown'][0]['warehouse_code']);
        $this->assertEquals(12, $queueItem->metadata['breakdown'][0]['contribution']);
        $this->assertSame('M2', $queueItem->metadata['breakdown'][1]['warehouse_code']);
        $this->assertEquals(4, $queueItem->metadata['breakdown'][1]['contribution']);

        $this->get(route('warehouses.index'))
            ->assertOk()
            ->assertSee('Podgląd stanów wysyłanych do kanałów')
            ->assertSee('SKU-AGG')
            ->assertSee('16')
            ->assertSee('M1:')
            ->assertSee('M2:')
            ->assertDontSee('M3:');
    }

    private function createStockExportIntegration(SalesChannel $channel): WordpressIntegration
    {
        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => $channel->name,
            'base_url' => 'https://' . strtolower($channel->code) . '.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);
    }
}
