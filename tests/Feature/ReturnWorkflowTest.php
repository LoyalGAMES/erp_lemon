<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Automation\DocumentAutomationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_can_create_rx_document_and_post_stock_to_target_warehouse(): void
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-RET',
            'name' => 'Produkt zwracany',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('returns.store'), [
            'target_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'reason' => 'Odstąpienie',
            'condition' => 'opened',
            'disposition' => 'restock',
            'customer_email' => 'client@example.test',
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->with('lines')->firstOrFail();

        $this->assertSame('RET/'.now()->format('Y').'/000001', $returnCase->number);
        $this->assertSame('opened', $returnCase->status);
        $this->assertSame($warehouse->id, $returnCase->target_warehouse_id);
        $this->assertCount(1, $returnCase->lines);
        $this->assertSame('2.0000', (string) $returnCase->lines->first()->quantity_accepted);

        $this->post(route('returns.document.create', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();

        $this->assertSame('RX', $document->type);
        $this->assertSame('draft', $document->status);
        $this->assertSame($warehouse->id, $document->destination_warehouse_id);
        $this->assertSame($returnCase->id, $document->metadata['return_case_id']);
        $this->assertCount(1, $document->lines);
        $this->assertSame('2.0000', (string) $document->lines->first()->quantity);

        $returnCase->refresh();
        $this->assertSame('document_created', $returnCase->status);
        $this->assertSame($document->id, $returnCase->warehouse_document_id);

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('status');

        $document->refresh();
        $returnCase->refresh();

        $this->assertSame('posted', $document->status);
        $this->assertSame('completed', $returnCase->status);

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_available);
        $this->assertSame(1, StockLedgerEntry::query()->where('warehouse_document_id', $document->id)->count());
    }

    public function test_return_can_accept_multiple_lines_into_one_rx_document(): void
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'RMA',
            'name' => 'Zwroty do kontroli',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $firstProduct = Product::query()->create([
            'sku' => 'SKU-RET-A',
            'name' => 'Pierwszy produkt zwrotu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $secondProduct = Product::query()->create([
            'sku' => 'SKU-RET-B',
            'name' => 'Drugi produkt zwrotu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('returns.store'), [
            'target_warehouse_id' => $warehouse->id,
            'reason' => 'Zwrot części zamówienia',
            'customer_email' => 'returns@example.test',
            'lines' => [
                [
                    'product_id' => $firstProduct->id,
                    'quantity' => 1,
                    'condition' => 'new',
                    'disposition' => 'restock',
                    'notes' => 'Kompletne',
                ],
                [
                    'product_id' => $secondProduct->id,
                    'quantity' => 3,
                    'condition' => 'opened',
                    'disposition' => 'inspection',
                    'notes' => 'Do sprawdzenia',
                ],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->with('lines.product')->firstOrFail();

        $this->assertCount(2, $returnCase->lines);
        $this->assertSame('1.0000', (string) $returnCase->lines->firstWhere('product_id', $firstProduct->id)->quantity_accepted);
        $this->assertSame('3.0000', (string) $returnCase->lines->firstWhere('product_id', $secondProduct->id)->quantity_accepted);

        $this->post(route('returns.document.create', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();

        $this->assertSame('RX', $document->type);
        $this->assertCount(2, $document->lines);
        $this->assertSame('1.0000', (string) $document->lines->firstWhere('product_id', $firstProduct->id)->quantity);
        $this->assertSame('3.0000', (string) $document->lines->firstWhere('product_id', $secondProduct->id)->quantity);

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('status');

        $firstBalance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $firstProduct->id)
            ->firstOrFail();

        $secondBalance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $secondProduct->id)
            ->firstOrFail();

        $this->assertSame('1.0000', (string) $firstBalance->quantity_on_hand);
        $this->assertSame('3.0000', (string) $secondBalance->quantity_on_hand);
        $this->assertSame(2, StockLedgerEntry::query()->where('warehouse_document_id', $document->id)->count());

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('SKU-RET-A x 1')
            ->assertSee('SKU-RET-B x 3');
    }

    public function test_open_return_can_be_deleted_before_rx_is_created(): void
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-DELETE-RET',
            'name' => 'Produkt do usunięcia zwrotu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('returns.store'), [
            'target_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Test usunięcia',
            'condition' => 'opened',
            'disposition' => 'restock',
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->with('lines')->firstOrFail();
        $lineId = $returnCase->lines->first()->id;

        $this->delete(route('returns.destroy', $returnCase))
            ->assertRedirect(route('returns.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted('return_cases', ['id' => $returnCase->id]);
        $this->assertDatabaseMissing('return_case_lines', ['id' => $lineId]);
    }

    public function test_return_with_created_rx_cannot_be_deleted(): void
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-BLOCK-DELETE',
            'name' => 'Produkt z RX',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('returns.store'), [
            'target_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Test blokady',
            'condition' => 'opened',
            'disposition' => 'restock',
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->firstOrFail();

        $this->post(route('returns.document.create', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->delete(route('returns.destroy', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('return_cases', ['id' => $returnCase->id]);
    }

    public function test_return_automation_can_create_and_post_rx_after_store(): void
    {
        app(DocumentAutomationSettingsService::class)->update([
            'return_create_rx_on_store' => true,
            'return_post_rx_on_store' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-AUTO-RX',
            'name' => 'Produkt automatycznego RX',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('returns.store'), [
            'target_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'reason' => 'Automatyka',
            'condition' => 'opened',
            'disposition' => 'restock',
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->firstOrFail();
        $document = WarehouseDocument::query()->with('lines')->firstOrFail();

        $this->assertSame('RX', $document->type);
        $this->assertSame('posted', $document->status);
        $this->assertSame($document->id, $returnCase->warehouse_document_id);

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
    }

    public function test_return_can_be_prefilled_from_order_number(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-AUTO-RET',
            'name' => 'Produkt z zamówienia',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '4870',
            'external_number' => '4870',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 123,
            'billing_data' => ['email' => 'client@example.test'],
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 2,
            'unit_gross_price' => 61.5,
        ]);

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('data-return-order-lookup', false)
            ->assertSee('Dodaj pozycję z zamówienia')
            ->assertSee('.return-order-results[hidden]', false)
            ->assertDontSee('isNotEmpty())&gt;', false)
            ->assertDontSee('isNotEmpty())>', false);

        $this->post(route('returns.store'), [
            'external_order_number' => '4870',
            'target_warehouse_id' => $warehouse->id,
            'reason' => 'Zwrot z panelu',
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->with('lines')->firstOrFail();

        $this->assertSame($order->id, $returnCase->external_order_id);
        $this->assertSame('client@example.test', $returnCase->customer_email);
        $this->assertCount(1, $returnCase->lines);
        $this->assertSame('2.0000', (string) $returnCase->lines->first()->quantity_accepted);
        $this->assertSame($product->id, $returnCase->lines->first()->product_id);
    }

    public function test_return_settings_are_separated_and_drive_return_defaults(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'RMA',
            'name' => 'Zwroty po kontroli',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-SET-RET',
            'name' => 'Produkt zwrotu z ustawień',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'SET-4870',
            'external_number' => 'SET-4870',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 200,
            'billing_data' => ['email' => 'settings@example.test'],
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'settings-line-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_gross_price' => 200,
        ]);

        $this->put(route('settings.returns.update'), [
            'numbering_prefix' => 'RMA',
            'numbering_pattern' => '{PREFIX}/{MM}/{YYYY}/{SEQ}',
            'numbering_padding' => 4,
            'default_target_warehouse_id' => $warehouse->id,
            'default_condition' => 'opened',
            'default_disposition' => 'inspection',
            'return_reasons' => [
                'Odstąpienie od umowy',
                'Zwrot automatyczny z ustawień',
            ],
            'conditions' => [
                ['code' => 'unchecked', 'label' => 'Niezweryfikowany'],
                ['code' => 'opened', 'label' => 'Otwarte opakowanie'],
            ],
            'dispositions' => [
                ['code' => 'restock', 'label' => 'Przywróć na stan', 'warehouse_id' => null],
                ['code' => 'inspection', 'label' => 'Do kontroli', 'warehouse_id' => $warehouse->id],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Zwroty')
            ->assertDontSee('Domyślny magazyn zwrotów')
            ->assertDontSee('Zapisz ustawienia zwrotów');

        $this->get(route('settings.returns'))
            ->assertOk()
            ->assertSee('Ustawienia zwrotów')
            ->assertSee('{PREFIX}/{MM}/{YYYY}/{SEQ}')
            ->assertSee('RMA')
            ->assertSee('RMA - Zwroty po kontroli')
            ->assertSee('Dyspozycje i magazyny docelowe')
            ->assertSee('Przywróć na stan')
            ->assertSee('Przykład: RMA/'.now()->format('m/Y').'/0001');

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('value="'.$warehouse->id.'" selected', false)
            ->assertSee('Dodaj pozycję z zamówienia')
            ->assertSee('Otwarte opakowanie')
            ->assertSee('Do kontroli');

        $this->post(route('returns.store'), [
            'external_order_number' => 'SET-4870',
            'target_warehouse_id' => $warehouse->id,
            'reason' => 'Zwrot automatyczny z ustawień',
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->with('lines')->firstOrFail();

        $this->assertSame('RMA/'.now()->format('m/Y').'/0001', $returnCase->number);
        $this->assertSame('opened', $returnCase->lines->first()->condition);
        $this->assertSame('inspection', $returnCase->lines->first()->disposition);
    }

    public function test_return_disposition_mapping_creates_rx_documents_per_target_warehouse(): void
    {
        $fallbackWarehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Zwroty domyślne',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $restockWarehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn sprzedażowy',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $inspectionWarehouse = Warehouse::query()->create([
            'code' => 'QC',
            'name' => 'Kontrola zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $firstProduct = Product::query()->create([
            'sku' => 'SKU-RESTOCK',
            'name' => 'Produkt do przywrócenia',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $secondProduct = Product::query()->create([
            'sku' => 'SKU-QC',
            'name' => 'Produkt do kontroli',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->put(route('settings.returns.update'), [
            'numbering_prefix' => 'RET',
            'numbering_pattern' => '{PREFIX}/{YYYY}/{SEQ}',
            'numbering_padding' => 6,
            'default_target_warehouse_id' => $fallbackWarehouse->id,
            'default_condition' => 'unchecked',
            'default_disposition' => 'restock',
            'return_reasons' => ['Odstąpienie od umowy', 'Zwrot z mapowaniem magazynów'],
            'conditions' => [
                ['code' => 'unchecked', 'label' => 'Niezweryfikowany'],
                ['code' => 'new', 'label' => 'Nowy'],
                ['code' => 'opened', 'label' => 'Otwarte opakowanie'],
            ],
            'dispositions' => [
                ['code' => 'restock', 'label' => 'Przywróć na stan', 'warehouse_id' => $restockWarehouse->id],
                ['code' => 'inspection', 'label' => 'Do kontroli', 'warehouse_id' => $inspectionWarehouse->id],
                ['code' => 'scrap', 'label' => 'Utylizacja', 'warehouse_id' => null],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $this->post(route('returns.store'), [
            'target_warehouse_id' => $fallbackWarehouse->id,
            'reason' => 'Zwrot z mapowaniem magazynów',
            'lines' => [
                [
                    'product_id' => $firstProduct->id,
                    'quantity' => 1,
                    'condition' => 'new',
                    'disposition' => 'restock',
                ],
                [
                    'product_id' => $secondProduct->id,
                    'quantity' => 2,
                    'condition' => 'opened',
                    'disposition' => 'inspection',
                ],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $returnCase = ReturnCase::query()->with('lines')->firstOrFail();

        $this->assertSame($restockWarehouse->id, $returnCase->lines->firstWhere('product_id', $firstProduct->id)->target_warehouse_id);
        $this->assertSame($inspectionWarehouse->id, $returnCase->lines->firstWhere('product_id', $secondProduct->id)->target_warehouse_id);

        $this->post(route('returns.document.create', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $documents = WarehouseDocument::query()->with('lines')->orderBy('id')->get();

        $this->assertCount(2, $documents);
        $this->assertSame([$restockWarehouse->id, $inspectionWarehouse->id], $documents->pluck('destination_warehouse_id')->all());
        $this->assertSame($documents->first()->id, $returnCase->refresh()->warehouse_document_id);
        $this->assertSame($documents->pluck('id')->all(), $returnCase->metadata['warehouse_document_ids']);

        $restockDocument = $documents->firstWhere('destination_warehouse_id', $restockWarehouse->id);
        $inspectionDocument = $documents->firstWhere('destination_warehouse_id', $inspectionWarehouse->id);

        $this->assertSame('1.0000', (string) $restockDocument->lines->first()->quantity);
        $this->assertSame($firstProduct->id, $restockDocument->lines->first()->product_id);
        $this->assertSame('2.0000', (string) $inspectionDocument->lines->first()->quantity);
        $this->assertSame($secondProduct->id, $inspectionDocument->lines->first()->product_id);

        $this->post(route('documents.post', $restockDocument))
            ->assertRedirect()
            ->assertSessionHas('status');

        $returnCase->refresh();
        $this->assertSame('document_created', $returnCase->status);

        $this->post(route('documents.post', $inspectionDocument))
            ->assertRedirect()
            ->assertSessionHas('status');

        $returnCase->refresh();
        $this->assertSame('completed', $returnCase->status);

        $restockBalance = StockBalance::query()
            ->where('warehouse_id', $restockWarehouse->id)
            ->where('product_id', $firstProduct->id)
            ->firstOrFail();
        $inspectionBalance = StockBalance::query()
            ->where('warehouse_id', $inspectionWarehouse->id)
            ->where('product_id', $secondProduct->id)
            ->firstOrFail();

        $this->assertSame('1.0000', (string) $restockBalance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $inspectionBalance->quantity_on_hand);

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('M1, QC')
            ->assertSee('SKU-RESTOCK x 1')
            ->assertSee('SKU-QC x 2');
    }

    public function test_return_order_lookup_finds_orders_outside_initial_suggestions(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-OLD-RET',
            'name' => 'Starszy produkt zwrotu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $oldOrder = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'OLD-9001',
            'external_number' => 'OLD-9001',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 199,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'email' => 'anna.old@example.test',
            ],
        ]);
        $oldOrder->forceFill([
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ])->save();

        $oldOrder->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'old-line-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_gross_price' => 199,
        ]);

        foreach (range(1, 30) as $index) {
            $newOrder = ExternalOrder::query()->create([
                'sales_channel_id' => $channel->id,
                'external_id' => 'NEW-'.$index,
                'external_number' => 'NEW-'.$index,
                'status' => 'processing',
                'currency' => 'PLN',
                'total_gross' => 100 + $index,
                'billing_data' => ['email' => "new{$index}@example.test"],
            ]);
            $newOrder->forceFill([
                'created_at' => now()->subMinutes(30 - $index),
                'updated_at' => now()->subMinutes(30 - $index),
            ])->save();
        }

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertDontSee('OLD-9001');

        $this->getJson(route('returns.orders.lookup', ['q' => 'OLD-9001']))
            ->assertOk()
            ->assertJsonPath('exact.number', 'OLD-9001')
            ->assertJsonPath('exact.email', 'anna.old@example.test')
            ->assertJsonPath('exact.customer', 'Anna Nowak')
            ->assertJsonPath('exact.lines.0.product_id', $product->id)
            ->assertJsonPath('exact.lines.0.sku', 'SKU-OLD-RET')
            ->assertJsonPath('exact.lines.0.quantity', 1);

        $this->getJson(route('returns.orders.lookup', ['q' => 'anna']))
            ->assertOk()
            ->assertJsonPath('orders.0.number', 'OLD-9001')
            ->assertJsonPath('orders.0.customer', 'Anna Nowak');
    }

    public function test_posted_return_can_issue_correction_invoice(): void
    {
        Http::fake([
            'https://shop.test/wp-json/lemon-erp/v1/orders/9001/invoice' => Http::response([
                'file_url' => 'https://shop.test/wp-json/lemon-erp/v1/orders/9001/invoice/download?token=correction-token',
                'note_id' => 9801,
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
            'wp_api_username' => 'erp',
            'wp_api_password_encrypted' => Crypt::encryptString('app-password'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-KOR',
            'name' => 'Produkt do korekty',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '9001',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 246,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => 'anna@example.test',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
        ]);

        $orderLine = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-1',
            'sku' => $product->sku,
            'name' => 'Produkt do korekty',
            'quantity' => 2,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'vat_rate' => 23,
            'raw_payload' => [
                'total' => '200',
                'total_tax' => '46',
            ],
        ]);

        $originalInvoice = Invoice::query()->create([
            'number' => 'FV/'.now()->format('Y').'/000001',
            'type' => 'vat',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
            'payment_due_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => [
                'name' => 'Sempre',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => 'biuro@example.test',
                'phone' => '+48123123123',
                'bank_account' => 'PL00111122223333444455556666',
            ],
            'buyer_data' => [
                'name' => 'Anna Kowalska',
                'tax_id' => '',
                'address_1' => 'Kliencka 1',
                'country' => 'PL',
            ],
            'net_total' => 200,
            'vat_total' => 46,
            'gross_total' => 246,
            'payment_method' => 'Przelewy24',
            'issued_at' => now(),
        ]);

        $originalInvoice->lines()->create([
            'product_id' => $product->id,
            'name' => 'Produkt do korekty',
            'sku' => $product->sku,
            'unit' => 'szt',
            'quantity' => 2,
            'unit_net_price' => 100,
            'net_total' => 200,
            'vat_rate' => 23,
            'vat_total' => 46,
            'gross_total' => 246,
            'metadata' => ['external_line_id' => 'line-1'],
        ]);

        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/'.now()->format('Y').'/000001',
            'external_order_id' => $order->id,
            'target_warehouse_id' => $warehouse->id,
            'status' => 'opened',
            'reason' => 'Odstąpienie od umowy',
            'customer_email' => 'anna@example.test',
        ]);

        $returnCase->lines()->create([
            'product_id' => $product->id,
            'external_order_line_id' => $orderLine->id,
            'quantity_expected' => 1,
            'quantity_accepted' => 1,
            'condition' => 'opened',
            'disposition' => 'restock',
        ]);

        $this->post(route('returns.document.create', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $document = WarehouseDocument::query()->firstOrFail();

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('status');

        $returnCase->refresh();

        $this->post(route('returns.correction.create', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $correction = Invoice::query()
            ->where('type', 'correction')
            ->with(['lines', 'files'])
            ->firstOrFail();

        $this->assertSame('FK/'.now()->format('Y').'/000001', $correction->number);
        $this->assertSame('-100.00', (string) $correction->net_total);
        $this->assertSame('-23.00', (string) $correction->vat_total);
        $this->assertSame('-123.00', (string) $correction->gross_total);
        $this->assertSame($originalInvoice->number, $correction->metadata['corrected_invoice_number']);
        $this->assertSame('Odstąpienie od umowy', $correction->metadata['correction_reason']);
        $this->assertSame('-1.0000', (string) $correction->lines->first()->quantity);
        $this->assertTrue($correction->files->contains('type', 'html'));
        $this->assertTrue($correction->files->contains('type', 'pdf'));
        $this->assertSame('success', data_get($correction->metadata, 'woocommerce_upload.status'));
        $this->assertSame('correction', data_get($correction->metadata, 'woocommerce_upload.invoice_type'));
        $this->assertSame('lemon_plugin', data_get($correction->metadata, 'woocommerce_upload.delivery_mode'));
        $this->assertSame('https://shop.test/wp-json/lemon-erp/v1/orders/9001/invoice/download?token=correction-token', data_get($correction->metadata, 'woocommerce_upload.file_url'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://shop.test/wp-json/lemon-erp/v1/orders/9001/invoice'
            && data_get($request->data(), 'invoice_type') === 'correction'
            && data_get($request->data(), 'invoice_number') === $correction->number
            && data_get($request->data(), 'order_id') === '9001');

        $returnCase->refresh();

        $this->assertSame('corrected', $returnCase->status);
        $this->assertSame($correction->id, $returnCase->correction_invoice_id);

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('Korekta '.$correction->number);

        $preview = $this->get(route('invoices.preview', $correction))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="'.str_replace(['/', '\\'], '-', $correction->number).'.pdf"');

        $this->assertStringStartsWith('%PDF-', $preview->getContent());
    }
}
