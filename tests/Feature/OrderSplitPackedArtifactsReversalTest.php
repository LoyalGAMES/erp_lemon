<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\PackingTask;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Inventory\StockReservationService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Orders\OrderSplitReversalService;
use App\Services\Orders\OrderSplitService;
use App\Services\Orders\OrderWzDocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class OrderSplitPackedArtifactsReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reverses_packed_artifacts_and_later_creates_fresh_wz_and_invoice(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        [$root, $child, $warehouse, $firstProduct] = $this->splitFamilyWithStock();
        $this->configureInvoiceSettings();

        $this->markFamilyPacked($root, $child);

        $oldWz = collect(app(OrderWzDocumentService::class)->ensureDrafts($root->fresh()))->sole();
        $oldWzKey = (string) $oldWz->order_fulfillment_key;
        app(WarehouseDocumentPostingService::class)->post($oldWz);
        $oldWz->refresh();

        $this->assertSame('posted', $oldWz->status);
        $this->assertSame(9.0, (float) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $firstProduct->id)
            ->value('quantity_on_hand'));

        $oldInvoice = app(OrderInvoiceService::class)->createForOrder($root->fresh());
        $label = $this->shippingLabel($child->fresh(), 'generated');
        $printJob = $this->printingJob($label);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());

        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $this->assertTrue($availability['shipping_confirmation_required']);

        $restored = $reversal->reverse(
            $child->fresh(),
            $availability['version'],
            'Scalenie po rozpoczętym pakowaniu',
            true,
        )->fresh('lines');

        $this->assertInstanceOf(ExternalOrder::class, $restored);
        $this->assertSame($root->id, $restored->id);
        $this->assertSame('processing', $restored->status);
        $this->assertSame('picking', $restored->fulfillment_status);
        $this->assertSame('369.00', (string) $restored->total_gross);
        $this->assertCount(2, $restored->lines);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);

        $rootTasks = PackingTask::query()
            ->where('external_order_id', $root->id)
            ->orderBy('external_line_id')
            ->get();
        $childTasks = PackingTask::query()
            ->where('external_order_id', $child->id)
            ->get();

        $this->assertCount(2, $rootTasks);
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => $task->status === 'open'));
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => (float) $task->quantity_picked === 0.0));
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => $task->picked_at === null && $task->packed_at === null));
        $this->assertNotEmpty($childTasks);
        $this->assertTrue($childTasks->every(fn (PackingTask $task): bool => $task->status === 'cancelled'));
        $this->assertTrue($childTasks->every(fn (PackingTask $task): bool => (float) $task->quantity_picked === 0.0));

        $this->assertSame(0, StockReservation::query()
            ->where('external_order_id', $child->external_id)
            ->whereIn('status', ['active', 'waiting'])
            ->count());
        $this->assertSame(2, StockReservation::query()
            ->where('external_order_id', $root->external_id)
            ->where('status', 'active')
            ->count());

        $archivedWz = WarehouseDocument::withTrashed()->findOrFail($oldWz->id);
        $this->assertSame('cancelled', $archivedWz->status);
        $this->assertNotNull($archivedWz->deleted_at);
        $this->assertStringStartsWith('split-reverted:', (string) $archivedWz->order_fulfillment_key);
        $this->assertSame($oldWzKey, data_get($archivedWz->metadata, 'split_reversal.original_order_fulfillment_key'));
        $this->assertSame(2, StockLedgerEntry::query()->where('warehouse_document_id', $oldWz->id)->count());
        $this->assertSame(0.0, (float) StockLedgerEntry::query()
            ->where('warehouse_document_id', $oldWz->id)
            ->sum('quantity_change'));
        $this->assertSame(10.0, (float) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $firstProduct->id)
            ->value('quantity_on_hand'));

        $label->refresh();
        $printJob->refresh();
        $this->assertSame('cancelled', $label->status);
        $this->assertStringStartsWith('split-reverted:', (string) $label->idempotency_key);
        $this->assertSame('manual_required', data_get($label->response_payload, 'cancellation.remote.status'));
        $this->assertNotNull(data_get($label->response_payload, 'split_reversal.operation_uuid'));
        $this->assertContains(
            $label->label_number,
            (array) data_get($restored->raw_payload, 'sempre_erp_split_reversal.cancelled_shipment_identities', []),
        );
        $this->assertSame('cancelled', $printJob->status);
        $this->assertNull($printJob->lease_token);
        $this->assertNull($printJob->reserved_by);
        $this->assertSame('printing', data_get($printJob->metadata, 'shipping_label_cancellation.previous_status'));

        $oldInvoice->refresh();
        $correction = Invoice::query()
            ->where('type', 'correction')
            ->where('metadata->corrected_invoice_id', $oldInvoice->id)
            ->sole();
        $this->assertSame('issued', $oldInvoice->status);
        $this->assertTrue(data_get($oldInvoice->metadata, 'split_reversal.fully_reversed'));
        $this->assertSame('issued', $correction->status);
        $this->assertSame('-123.00', (string) $correction->gross_total);

        $newWz = collect(app(OrderWzDocumentService::class)->ensureDrafts($restored->fresh()))->sole();
        $this->assertNotSame($oldWz->id, $newWz->id);
        $this->assertSame('draft', $newWz->status);
        $this->assertSame($oldWzKey, $newWz->order_fulfillment_key);
        $this->assertCount(2, $newWz->lines()->get());

        app(WarehouseDocumentPostingService::class)->post($newWz);
        $newInvoice = app(OrderInvoiceService::class)->createForOrder($restored->fresh());

        $this->assertNotSame($oldInvoice->id, $newInvoice->id);
        $this->assertNotSame($oldInvoice->number, $newInvoice->number);
        $this->assertSame('369.00', (string) $newInvoice->gross_total);
        $this->assertNotTrue(data_get($newInvoice->metadata, 'split_reversal.fully_reversed'));
        Http::assertNothingSent();
    }

    public function test_reversal_restores_the_source_baseline_captured_immediately_before_wz_posting(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        [$root, $child, $warehouse, $firstProduct] = $this->splitFamilyWithStock(true);
        $this->markFamilyPacked($root, $child);

        $wz = collect(app(OrderWzDocumentService::class)->ensureDrafts($root->fresh()))->sole();
        app(WarehouseDocumentPostingService::class)->post($wz);
        $postedBalance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $firstProduct->id)
            ->firstOrFail();

        $this->assertNull($postedBalance->source_sales_channel_id);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $reversal->reverse($child->fresh(), $availability['version']);
        $restoredBalance = $postedBalance->fresh();

        $this->assertNotNull($restoredBalance);
        $this->assertSame($root->sales_channel_id, $restoredBalance->source_sales_channel_id);
        $this->assertSame('9.0000', (string) $restoredBalance->source_available_quantity);
        $this->assertNotNull($restoredBalance->source_observed_at);
        $this->assertSame('10.0000', (string) $restoredBalance->quantity_on_hand);
        $this->assertSame('1.0000', (string) $restoredBalance->quantity_reserved);
        $this->assertSame('9.0000', (string) $restoredBalance->quantity_available);
        $this->assertEqualsWithDelta(1, (float) data_get(
            $restoredBalance->source_reflected_order_quantities,
            $root->external_id,
        ), 0.00001);
    }

    public function test_reversal_removes_a_ghost_reflection_for_a_product_added_only_after_the_split(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        [$root, $child, $warehouse] = $this->splitFamilyWithStock();
        $addedProduct = $this->productWithStock($warehouse, 'PACKED-REV-LATE', 'Produkt dodany po podziale');
        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $addedProduct->id)
            ->firstOrFail();
        $balance->update([
            'quantity_on_hand' => 11,
            'quantity_available' => 11,
            'source_sales_channel_id' => $root->sales_channel_id,
            'source_available_quantity' => 10,
            'source_observed_at' => now()->addSecond(),
            'source_reflected_order_quantities' => [$child->external_id => 1],
        ]);
        $child->lines()->create([
            'product_id' => $addedProduct->id,
            'external_line_id' => 'late-child-line',
            'canonical_external_line_id' => 'late-child-line',
            'sku' => $addedProduct->sku,
            'name' => $addedProduct->name,
            'quantity' => 1,
            'unit_gross_price' => 10,
        ]);
        app(StockReservationService::class)->syncForOrder($child->fresh());

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $reversal->reverse($child->fresh(), $availability['version']);
        $balance->refresh();

        $this->assertSame('10.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('10.0000', (string) $balance->quantity_available);
        $this->assertArrayNotHasKey($root->external_id, (array) $balance->source_reflected_order_quantities);
        $this->assertArrayNotHasKey($child->external_id, (array) $balance->source_reflected_order_quantities);
    }

    public function test_active_shipping_label_created_before_split_cutoff_blocks_without_mutation(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        [$root, $child] = $this->splitFamilyWithStock();
        $cutoff = CarbonImmutable::parse((string) data_get(
            $root->fresh()->raw_payload,
            'sempre_erp_split_original.captured_at',
        ));
        $label = $this->shippingLabel($child, 'generated');
        $label->timestamps = false;
        $label->forceFill([
            'created_at' => $cutoff->subSecond(),
            'updated_at' => $cutoff->subSecond(),
        ])->save();
        $label->timestamps = true;

        $rootBefore = $root->fresh();
        $childBefore = $child->fresh();
        $rootRawBefore = $rootBefore->getRawOriginal('raw_payload');
        $availability = app(OrderSplitReversalService::class)->availability($child->fresh());
        $thrown = null;

        try {
            app(OrderSplitReversalService::class)->reverse(
                $child->fresh(),
                $availability['version'],
                'Nie wolno cofać starszej przesyłki',
                true,
            );
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertFalse($availability['available']);
        $this->assertStringContainsString('podział', mb_strtolower(implode(' ', $availability['reasons'])));
        $this->assertSame($rootRawBefore, $root->fresh()->getRawOriginal('raw_payload'));
        $this->assertSame((string) $rootBefore->total_gross, (string) $root->fresh()->total_gross);
        $this->assertSame((string) $childBefore->total_gross, (string) $child->fresh()->total_gross);
        $this->assertSame('generated', $label->fresh()->status);
        $this->assertSame('shipment:order:'.$child->id, $label->fresh()->idempotency_key);
        $this->assertSame(2, ExternalOrder::query()->count());
        $this->assertNull($child->fresh()->deleted_at);
        Http::assertNothingSent();
    }

    public function test_reversal_leaves_only_one_open_root_task_for_each_restored_line(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        [$root, $child] = $this->splitFamilyWithStock();
        $sourceTask = PackingTask::query()
            ->where('external_order_id', $root->id)
            ->where('status', 'open')
            ->firstOrFail();
        $duplicate = $sourceTask->replicate();
        $duplicate->metadata = array_merge((array) $duplicate->metadata, ['test_duplicate' => true]);
        $duplicate->save();

        $this->assertSame(2, PackingTask::query()
            ->where('external_order_id', $root->id)
            ->where('external_line_id', $sourceTask->external_line_id)
            ->where('status', 'open')
            ->count());

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $restored = $reversal->reverse($child->fresh(), $availability['version']);
        $openCounts = PackingTask::query()
            ->where('external_order_id', $restored->id)
            ->where('status', 'open')
            ->selectRaw('external_line_id, count(*) as aggregate')
            ->groupBy('external_line_id')
            ->pluck('aggregate', 'external_line_id');

        $this->assertCount(2, $openCounts);
        $this->assertTrue($openCounts->every(fn (mixed $count): bool => (int) $count === 1));
        $this->assertSame(1, PackingTask::query()
            ->whereKey($sourceTask->id)
            ->where('status', 'open')
            ->count() + PackingTask::query()
            ->whereKey($duplicate->id)
            ->where('status', 'open')
            ->count());
        Http::assertNothingSent();
    }

    /** @return array{ExternalOrder,ExternalOrder,Warehouse,Product,Product} */
    private function splitFamilyWithStock(bool $withSourceBaseline = false): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'PACKED-SPLIT-REVERSAL',
            'name' => 'Cofnięcie spakowanego splitu',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'PACKED-SPLIT-WH',
            'name' => 'Magazyn testu cofnięcia',
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

        $firstProduct = $this->productWithStock($warehouse, 'PACKED-REV-A', 'Produkt A');
        $secondProduct = $this->productWithStock($warehouse, 'PACKED-REV-B', 'Produkt B');
        $root = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'PACKED-REV-1001',
            'external_number' => 'PACKED/REV/1001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 369,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => 'anna@example.test',
            ],
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'id' => 1001,
                'number' => 'PACKED/REV/1001',
                'total' => '369.00',
                'payment_method' => 'bacs',
                'payment_method_title' => 'Przelew bankowy',
            ],
            'external_created_at' => now()->subHour(),
        ]);
        $firstLine = $root->lines()->create([
            'product_id' => $firstProduct->id,
            'external_line_id' => 'packed-line-a',
            'canonical_external_line_id' => 'packed-line-a',
            'sku' => $firstProduct->sku,
            'name' => $firstProduct->name,
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => 'packed-line-a',
                'quantity' => 1,
                'total' => '100.00',
                'total_tax' => '23.00',
            ],
        ]);
        $secondLine = $root->lines()->create([
            'product_id' => $secondProduct->id,
            'external_line_id' => 'packed-line-b',
            'canonical_external_line_id' => 'packed-line-b',
            'sku' => $secondProduct->sku,
            'name' => $secondProduct->name,
            'quantity' => 1,
            'unit_net_price' => 200,
            'unit_gross_price' => 246,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => 'packed-line-b',
                'quantity' => 1,
                'total' => '200.00',
                'total_tax' => '46.00',
            ],
        ]);

        if ($withSourceBaseline) {
            foreach ([$firstProduct, $secondProduct] as $product) {
                StockBalance::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)
                    ->firstOrFail()
                    ->update([
                        'source_sales_channel_id' => $channel->id,
                        'source_available_quantity' => 9,
                        'source_observed_at' => now(),
                        'source_reflected_order_quantities' => [$root->external_id => 1],
                    ]);
            }
        }

        $child = app(OrderSplitService::class)->split(
            $root,
            [$secondLine->id => 1],
            'Druga paczka',
        );

        $this->assertSame('123.00', (string) $root->fresh()->total_gross);
        $this->assertSame('246.00', (string) $child->fresh()->total_gross);
        $this->assertNotNull($firstLine->fresh());

        return [$root->fresh(), $child->fresh(), $warehouse, $firstProduct, $secondProduct];
    }

    private function productWithStock(Warehouse $warehouse, string $sku, string $name): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
            'quantity_available' => 10,
        ]);

        return $product;
    }

    private function markFamilyPacked(ExternalOrder $root, ExternalOrder $child): void
    {
        foreach (PackingTask::query()->whereIn('external_order_id', [$root->id, $child->id])->get() as $task) {
            $task->update([
                'status' => 'packed',
                'quantity_picked' => $task->quantity_required,
                'picked_at' => now()->subMinute(),
                'packed_at' => now(),
                'metadata' => array_merge((array) $task->metadata, [
                    'packing_completion' => ['completed_at' => now()->toISOString()],
                ]),
            ]);
        }

        ExternalOrder::query()->whereIn('id', [$root->id, $child->id])->update([
            'status' => 'ready-to-ship',
            'fulfillment_status' => 'awaiting_courier',
        ]);
    }

    private function shippingLabel(ExternalOrder $order, string $status): ShippingLabel
    {
        return ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:order:'.$order->id,
            'status' => $status,
            'provider' => 'dpd',
            'label_number' => 'DPD-'.$order->id,
            'tracking_number' => 'DPD-'.$order->id,
            'disk' => 'local',
            'path' => 'shipping-labels/packed-reversal-'.$order->id.'.zpl',
            'mime_type' => 'application/zpl',
            'next_tracking_check_at' => now()->addMinutes(5),
            'generated_at' => now(),
        ]);
    }

    private function printingJob(ShippingLabel $label): PrintJob
    {
        return PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'deduplication_key' => hash('sha256', 'packed-split-reversal-'.$label->id),
            'status' => 'printing',
            'source' => 'packing',
            'station_code' => 'PACK-01',
            'printer_name' => 'Zebra testowa',
            'format' => 'zpl',
            'attempts' => 1,
            'reserved_by' => 'bridge-test',
            'reserved_station' => 'PACK-01',
            'reserved_at' => now(),
            'lease_token' => str_repeat('a', 64),
        ]);
    }

    private function configureInvoiceSettings(): void
    {
        app(InvoiceSettingsService::class)->updateSellerData([
            'name' => 'Sempre Love sp. z o.o.',
            'tax_id' => '5261040828',
            'address_1' => 'Testowa 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'email' => 'biuro@example.test',
            'phone' => '+48123123123',
            'bank_account' => 'PL00111122223333444455556666',
        ]);
        app(InvoiceSettingsService::class)->updateNumberingData([
            'sales_prefix' => 'FV/SPLIT-REV',
            'correction_prefix' => 'FK/SPLIT-REV',
            'padding' => 5,
            'payment_due_days' => 7,
        ]);
    }
}
