<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_lists_filters_summarizes_and_exports_stock_movements(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-LEDGER',
            'name' => 'Produkt ledger',
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

        Warehouse::query()->create([
            'code' => 'M3',
            'name' => 'Magazyn wewnętrzny',
            'type' => 'internal',
            'is_active' => true,
        ]);

        $this->post('/documents', [
            'type' => 'PZ',
            'destination_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10],
            ],
        ])->assertRedirect();

        $pz = WarehouseDocument::query()->where('type', 'PZ')->firstOrFail();

        $this->post(route('documents.post', $pz))->assertRedirect();

        $this->post('/documents', [
            'type' => 'WZ',
            'source_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ])->assertRedirect();

        $wz = WarehouseDocument::query()->where('type', 'WZ')->firstOrFail();

        $this->post(route('documents.post', $wz))->assertRedirect();

        $this->assertSame(2, StockLedgerEntry::query()->count());

        $this->get(route('ledger.index'))
            ->assertOk()
            ->assertSee('Ledger stanów')
            ->assertSee('Przychody')
            ->assertSee('10,0000')
            ->assertSee('Rozchody')
            ->assertSee('3,0000')
            ->assertSee('Saldo netto')
            ->assertSee('7,0000')
            ->assertSee($pz->number)
            ->assertSee($wz->number)
            ->assertSee('Eksport CSV');

        $this->get(route('ledger.index', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'document_type' => 'WZ',
            'direction' => 'out',
            'q' => 'SKU-LEDGER',
        ]))
            ->assertOk()
            ->assertSee($wz->number)
            ->assertDontSee($pz->number);

        $response = $this->get(route('ledger.export', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'direction' => 'out',
        ]));

        $response
            ->assertOk()
            ->assertDownload();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('posted_at;document_number;document_type;warehouse;sku;product_name;quantity_change;direction', $csv);
        $this->assertStringContainsString($wz->number, $csv);
        $this->assertStringContainsString('-3.0000;out', $csv);

        $this->get('/modul/ledger')->assertRedirect('/ledger');
    }
}
