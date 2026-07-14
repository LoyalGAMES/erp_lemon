<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderWzDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrderWzLegacySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_unscoped_legacy_draft_is_adopted_and_gets_delivery_snapshot(): void
    {
        [$channel, $warehouse, $product, $order] = $this->orderContext('WZ_SAFE', '8001');
        $order->update([
            'currency' => 'PLN',
            'raw_payload' => [
                'shipping_lines' => [[
                    'method_id' => 'flat_rate:4',
                    'method_title' => 'Kurier ekspresowy',
                    'total' => '14.50',
                ]],
            ],
        ]);
        $this->reserve($channel, $warehouse, $product, $order);

        $legacyDraft = WarehouseDocument::query()->create([
            'number' => 'WZ/LEGACY/8001',
            'type' => 'WZ',
            'status' => 'draft',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'external_reference' => $order->external_number,
            'metadata' => [],
        ]);

        $documents = app(OrderWzDocumentService::class)->ensureDrafts($order);

        $this->assertCount(1, $documents);
        $this->assertSame($legacyDraft->id, $documents[0]->id);

        $legacyDraft->refresh();
        $this->assertNotNull($legacyDraft->order_fulfillment_key);
        $this->assertSame($channel->id, data_get($legacyDraft->metadata, 'sales_channel_id'));
        $this->assertSame('flat_rate:4', data_get($legacyDraft->metadata, 'order_snapshot.delivery.method_id'));
        $this->assertSame('Kurier ekspresowy', data_get($legacyDraft->metadata, 'order_snapshot.delivery.method_title'));
        $this->assertSame('14.50', data_get($legacyDraft->metadata, 'order_snapshot.delivery.total'));
        $this->assertSame('PLN', data_get($legacyDraft->metadata, 'order_snapshot.delivery.currency'));

        $this->get(route('documents.show', $legacyDraft))
            ->assertOk()
            ->assertSee('Metoda dostawy:')
            ->assertSee('Kurier ekspresowy')
            ->assertSee('Koszt dostawy:')
            ->assertSee('14,50 PLN');

        $this->get(route('documents.print', $legacyDraft))
            ->assertOk()
            ->assertSee('Kurier ekspresowy')
            ->assertSee('14,50 PLN');
    }

    public function test_unscoped_legacy_wz_is_not_assigned_when_order_identity_repeats_between_channels(): void
    {
        [$firstChannel, $warehouse, $product, $firstOrder] = $this->orderContext('WZ_FIRST', '9001');
        [$secondChannel, , , $secondOrder] = $this->orderContext('WZ_SECOND', '9001', $warehouse, $product);
        $this->reserve($firstChannel, $warehouse, $product, $firstOrder);

        $legacyDraft = WarehouseDocument::query()->create([
            'number' => 'WZ/LEGACY/9001',
            'type' => 'WZ',
            'status' => 'draft',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'external_reference' => '9001',
            'metadata' => [],
        ]);

        $fulfillmentStatus = app(OrderFulfillmentStatusService::class);
        $this->assertNull($fulfillmentStatus->latestWz($firstOrder));
        $this->assertNull($fulfillmentStatus->latestWz($secondOrder));
        $this->assertNotSame($firstChannel->id, $secondChannel->id);

        try {
            app(OrderWzDocumentService::class)->ensureDrafts($firstOrder);
            $this->fail('Niejednoznaczny dokument legacy nie powinien zostać automatycznie przypisany.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('więcej niż jednym kanale sprzedaży', $exception->getMessage());
            $this->assertStringContainsString($legacyDraft->number, $exception->getMessage());
        }

        $legacyDraft->refresh();
        $this->assertNull($legacyDraft->order_fulfillment_key);
        $this->assertNull(data_get($legacyDraft->metadata, 'sales_channel_id'));
        $this->assertSame(1, WarehouseDocument::query()->count());
    }

    public function test_multiple_legacy_candidates_in_one_warehouse_stop_automatic_synchronization(): void
    {
        [$channel, $warehouse, $product, $order] = $this->orderContext('WZ_DUPLICATE', '9101');
        $this->reserve($channel, $warehouse, $product, $order);

        foreach (['WZ/LEGACY/9101/A', 'WZ/LEGACY/9101/B'] as $number) {
            WarehouseDocument::query()->create([
                'number' => $number,
                'type' => 'WZ',
                'status' => 'draft',
                'source_warehouse_id' => $warehouse->id,
                'document_date' => now(),
                'external_reference' => $order->external_number,
                'metadata' => [],
            ]);
        }

        try {
            app(OrderWzDocumentService::class)->ensureDrafts($order);
            $this->fail('Wiele dokumentów legacy nie powinno zostać automatycznie przypisanych.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('więcej niż jeden starszy dokument', $exception->getMessage());
            $this->assertStringContainsString('WZ/LEGACY/9101/A', $exception->getMessage());
            $this->assertStringContainsString('WZ/LEGACY/9101/B', $exception->getMessage());
        }

        $this->assertSame(2, WarehouseDocument::query()->count());
        $this->assertSame(0, WarehouseDocument::query()->whereNotNull('order_fulfillment_key')->count());
    }

    /**
     * @return array{SalesChannel, Warehouse, Product, ExternalOrder}
     */
    private function orderContext(
        string $channelCode,
        string $externalId,
        ?Warehouse $warehouse = null,
        ?Product $product = null,
    ): array {
        $channel = SalesChannel::query()->create([
            'code' => $channelCode,
            'name' => 'Kanał '.$channelCode,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse ??= Warehouse::query()->create([
            'code' => 'M-'.$channelCode,
            'name' => 'Magazyn '.$channelCode,
            'type' => 'physical',
            'is_active' => true,
        ]);
        $product ??= Product::query()->create([
            'sku' => 'SKU-'.$channelCode,
            'name' => 'Produkt '.$channelCode,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
        ]);

        return [$channel, $warehouse, $product, $order];
    }

    private function reserve(
        SalesChannel $channel,
        Warehouse $warehouse,
        Product $product,
        ExternalOrder $order,
    ): void {
        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->external_id,
            'quantity' => 1,
            'status' => 'active',
            'reserved_at' => now(),
        ]);
    }
}
