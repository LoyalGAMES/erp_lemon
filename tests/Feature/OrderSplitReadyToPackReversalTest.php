<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Services\Orders\OrderSplitReversalService;
use App\Services\Orders\OrderSplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class OrderSplitReadyToPackReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_reversal_resets_picked_packed_and_problem_work_across_the_entire_split_family(): void
    {
        [$root, $child, $grandchild] = $this->nestedSplitFamilyWithStock();
        $familyIds = [$root->id, $child->id, $grandchild->id];

        $activeTasks = PackingTask::query()
            ->whereIn('external_order_id', $familyIds)
            ->whereIn('status', ['open', 'picked'])
            ->orderBy('external_order_id')
            ->get();

        $this->assertCount(3, $activeTasks);
        $this->assertEqualsCanonicalizing(
            $familyIds,
            $activeTasks->pluck('external_order_id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        foreach ($activeTasks as $task) {
            $task->update([
                'quantity_picked' => $task->quantity_required,
                'status' => 'picked',
                'picked_at' => now()->subMinutes(10),
                'packed_at' => null,
            ]);
        }

        $root->update(['fulfillment_status' => 'ready_to_pack']);

        $childTask = $activeTasks->firstWhere('external_order_id', $child->id);
        $this->assertInstanceOf(PackingTask::class, $childTask);
        $childTask->update([
            'status' => 'packed',
            'packed_at' => now()->subMinutes(5),
        ]);
        $child->update(['fulfillment_status' => 'awaiting_courier']);

        $grandchildTask = $activeTasks->firstWhere('external_order_id', $grandchild->id);
        $this->assertInstanceOf(PackingTask::class, $grandchildTask);
        $grandchildTask->update(['status' => 'problem']);
        $grandchild->update(['fulfillment_status' => 'problem']);

        foreach ([$child, $grandchild] as $part) {
            $this->assertGreaterThan(
                0,
                StockReservation::query()
                    ->where('external_order_id', $part->external_id)
                    ->whereIn('status', ['active', 'waiting'])
                    ->count(),
            );
        }

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($grandchild->fresh());

        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $restored = $reversal->reverse(
            $grandchild->fresh(),
            $availability['version'],
            'Reset pracy wykonanej po podziale',
        )->fresh('lines');

        $this->assertInstanceOf(ExternalOrder::class, $restored);
        $this->assertSame($root->id, $restored->id);
        $this->assertSame('600.00', (string) $restored->total_gross);
        $this->assertSame('picking', $restored->fulfillment_status);
        $this->assertSame(
            [
                'line-1' => '1.0000',
                'line-2' => '1.0000',
                'line-3' => '1.0000',
            ],
            $restored->lines
                ->sortBy('external_line_id')
                ->mapWithKeys(fn ($line): array => [(string) $line->external_line_id => (string) $line->quantity])
                ->all(),
        );

        $rootTasks = PackingTask::query()
            ->where('external_order_id', $root->id)
            ->orderBy('external_line_id')
            ->get();

        $this->assertCount(3, $rootTasks);
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => $task->status === 'open'));
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => (float) $task->quantity_picked === 0.0));
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => $task->picked_at === null));
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => $task->packed_at === null));
        $this->assertTrue($rootTasks->every(fn (PackingTask $task): bool => $task->external_order_line_id !== null));

        $archivedPartTasks = PackingTask::query()
            ->whereIn('external_order_id', [$child->id, $grandchild->id])
            ->get();

        $this->assertNotEmpty($archivedPartTasks);
        $this->assertTrue($archivedPartTasks->every(fn (PackingTask $task): bool => $task->status === 'cancelled'));
        $this->assertTrue($archivedPartTasks->every(fn (PackingTask $task): bool => (float) $task->quantity_picked === 0.0));
        $this->assertTrue($archivedPartTasks->every(fn (PackingTask $task): bool => $task->picked_at === null));
        $this->assertTrue($archivedPartTasks->every(fn (PackingTask $task): bool => $task->packed_at === null));

        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        $this->assertSoftDeleted('external_orders', ['id' => $grandchild->id]);

        foreach ([$child, $grandchild] as $part) {
            $this->assertSame(
                0,
                StockReservation::query()
                    ->where('external_order_id', $part->external_id)
                    ->whereIn('status', ['active', 'waiting'])
                    ->count(),
            );
            $this->assertGreaterThan(
                0,
                StockReservation::query()
                    ->where('external_order_id', $part->external_id)
                    ->where('status', 'released')
                    ->whereNotNull('released_at')
                    ->count(),
            );
        }

        $this->assertSame(
            3,
            StockReservation::query()
                ->where('external_order_id', $root->external_id)
                ->where('status', 'active')
                ->count(),
        );
        $this->assertSame(
            3.0,
            (float) StockReservation::query()
                ->where('external_order_id', $root->external_id)
                ->where('status', 'active')
                ->sum('quantity'),
        );
    }

    public function test_reversal_is_still_blocked_after_any_part_was_shipped(): void
    {
        [$root, $child, $grandchild] = $this->nestedSplitFamilyWithStock();
        $shippedTask = PackingTask::query()
            ->where('external_order_id', $grandchild->id)
            ->whereIn('status', ['open', 'picked'])
            ->firstOrFail();

        $shippedTask->update([
            'quantity_picked' => $shippedTask->quantity_required,
            'status' => 'shipped',
            'picked_at' => now()->subMinutes(10),
            'packed_at' => now()->subMinutes(5),
        ]);
        $grandchild->update(['fulfillment_status' => 'shipped']);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($grandchild->fresh());

        $this->assertFalse($availability['available']);

        try {
            $reversal->reverse($grandchild->fresh(), $availability['version']);
            $this->fail('Expected a shipped split family to reject reversal.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', trim($exception->getMessage()));
        }

        $this->assertSame(3, ExternalOrder::query()->count());
        $this->assertNotNull($root->fresh());
        $this->assertNotNull($child->fresh());
        $this->assertNotNull($grandchild->fresh());
        $this->assertSame('100.00', (string) $root->fresh()->total_gross);
        $this->assertSame('200.00', (string) $child->fresh()->total_gross);
        $this->assertSame('300.00', (string) $grandchild->fresh()->total_gross);
        $this->assertSame('shipped', $shippedTask->fresh()->status);
    }

    public function test_completed_status_without_physical_pickup_is_still_reversible(): void
    {
        [$root, $child, $grandchild] = $this->nestedSplitFamilyWithStock();

        foreach (PackingTask::query()->whereIn('external_order_id', [$root->id, $child->id, $grandchild->id])->get() as $task) {
            $task->update([
                'quantity_picked' => $task->quantity_required,
                'status' => 'packed',
                'picked_at' => now()->subMinutes(2),
                'packed_at' => now()->subMinute(),
            ]);
        }

        $child->update([
            'status' => 'completed',
            'fulfillment_status' => 'awaiting_courier',
        ]);
        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());

        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $restored = $reversal->reverse($child->fresh(), $availability['version'])->fresh();

        $this->assertNotNull($restored);
        $this->assertSame($root->id, $restored->id);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        $this->assertSoftDeleted('external_orders', ['id' => $grandchild->id]);
    }

    public function test_completed_status_with_a_confirmed_carrier_pickup_is_not_reversible(): void
    {
        [, $child] = $this->nestedSplitFamilyWithStock();
        $child->update([
            'status' => 'completed',
            'fulfillment_status' => 'awaiting_courier',
        ]);
        ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'picked_up',
            'provider' => 'inpost',
            'tracking_number' => 'PICKED-UP-COMPLETED-1',
            'disk' => 'local',
            'path' => 'shipping-labels/picked-up.pdf',
            'picked_up_at' => now(),
        ]);

        $availability = app(OrderSplitReversalService::class)->availability($child->fresh());

        $this->assertFalse($availability['available']);
        $this->assertStringContainsString('wysłana albo odebrana', implode(' ', $availability['reasons']));
    }

    public function test_stale_local_label_status_cannot_hide_confirmed_carrier_pickup(): void
    {
        [, $child] = $this->nestedSplitFamilyWithStock();
        ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => 'inpost',
            'tracking_number' => 'STALE-LOCAL-STATUS-1',
            'tracking_status' => 'collected_from_sender',
            'disk' => 'local',
            'path' => 'shipping-labels/stale-local-status.pdf',
        ]);

        $availability = app(OrderSplitReversalService::class)->availability($child->fresh());

        $this->assertFalse($availability['available']);
        $this->assertStringContainsString('wysłana albo odebrana', implode(' ', $availability['reasons']));
    }

    public function test_cancelled_label_cannot_hide_a_late_inpost_network_status(): void
    {
        [, $child] = $this->nestedSplitFamilyWithStock();
        ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'cancelled',
            'provider' => 'inpost',
            'tracking_number' => 'LATE-NETWORK-STATUS-1',
            'tracking_status' => 'sent_from_sorting_center',
            'disk' => 'local',
            'path' => 'shipping-labels/late-network-status.pdf',
            'response_payload' => [
                'cancellation' => [
                    'remote' => ['status' => 'cancelled'],
                ],
            ],
        ]);

        $availability = app(OrderSplitReversalService::class)->availability($child->fresh());

        $this->assertFalse($availability['available']);
        $this->assertStringContainsString('wysłana albo odebrana', implode(' ', $availability['reasons']));
    }

    public function test_unknown_inpost_status_is_fail_closed_but_pre_pickup_statuses_are_not_pickup_evidence(): void
    {
        [, $child] = $this->nestedSplitFamilyWithStock();
        $label = ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'cancelled',
            'provider' => 'inpost',
            'tracking_number' => 'FUTURE-INPOST-STATUS-1',
            'tracking_status' => 'CRE.1001',
            'disk' => 'local',
            'path' => 'shipping-labels/future-inpost-status.pdf',
            'response_payload' => [
                'cancellation' => [
                    'remote' => ['status' => 'cancelled'],
                ],
            ],
        ]);

        $beforePickup = app(OrderSplitReversalService::class)->availability($child->fresh());
        $this->assertTrue($beforePickup['available'], implode(' ', $beforePickup['reasons']));

        $label->update(['tracking_status' => 'confirmed']);
        $confirmed = app(OrderSplitReversalService::class)->availability($child->fresh());
        $this->assertTrue($confirmed['available'], implode(' ', $confirmed['reasons']));

        $label->update(['tracking_status' => 'future_inpost_network_status']);
        $unknownStatus = app(OrderSplitReversalService::class)->availability($child->fresh());

        $this->assertFalse($unknownStatus['available']);
        $this->assertStringContainsString('wysłana albo odebrana', implode(' ', $unknownStatus['reasons']));
    }

    public function test_unknown_blpaczka_status_is_fail_closed_but_waiting_for_courier_is_not_pickup_evidence(): void
    {
        [, $child] = $this->nestedSplitFamilyWithStock();
        $label = ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'cancelled',
            'provider' => 'blpaczka',
            'label_number' => 'BLP-FUTURE-STATUS-1',
            'tracking_status' => 'Oczekuje na odbiór przez kuriera',
            'disk' => 'local',
            'path' => 'shipping-labels/blp-future-status.pdf',
            'response_payload' => [
                'cancellation' => [
                    'remote' => ['status' => 'cancelled'],
                ],
            ],
        ]);

        $beforePickup = app(OrderSplitReversalService::class)->availability($child->fresh());
        $this->assertTrue($beforePickup['available'], implode(' ', $beforePickup['reasons']));

        $label->update(['tracking_status' => 'Nowy status operacyjny przewoźnika']);
        $unknownStatus = app(OrderSplitReversalService::class)->availability($child->fresh());

        $this->assertFalse($unknownStatus['available']);
        $this->assertStringContainsString('wysłana albo odebrana', implode(' ', $unknownStatus['reasons']));
    }

    public function test_reversal_never_changes_reflected_quantities_owned_by_another_sales_channel(): void
    {
        [$root, $child, $grandchild] = $this->nestedSplitFamilyWithStock();
        $foreignChannel = SalesChannel::query()->create([
            'code' => 'FOREIGN-REFLECTIONS',
            'name' => 'Inny kanał stanów',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $foreignWarehouse = Warehouse::query()->create([
            'code' => 'FOREIGN-REFLECTIONS-WH',
            'name' => 'Magazyn innego kanału',
            'type' => 'virtual',
            'is_active' => true,
        ]);
        $productId = (int) $root->lines()->whereNotNull('product_id')->value('product_id');
        $foreignReflections = [
            $root->external_id => 77,
            $child->external_id => 11,
            $grandchild->external_id => 22,
        ];
        $foreignBalance = StockBalance::query()->create([
            'warehouse_id' => $foreignWarehouse->id,
            'product_id' => $productId,
            'quantity_on_hand' => 100,
            'quantity_reserved' => 0,
            'quantity_available' => 100,
            'source_sales_channel_id' => $foreignChannel->id,
            'source_available_quantity' => 100,
            'source_reflected_order_quantities' => $foreignReflections,
        ]);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $reversal->reverse($child->fresh(), $availability['version']);

        $this->assertSame($foreignReflections, $foreignBalance->fresh()->source_reflected_order_quantities);
    }

    /** @return array{ExternalOrder, ExternalOrder, ExternalOrder} */
    private function nestedSplitFamilyWithStock(): array
    {
        Mail::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'SPLIT-ROLLBACK',
            'name' => 'Split rollback',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'ROLLBACK-WH',
            'name' => 'Magazyn testowy',
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

        $products = collect([1, 2, 3])->map(function (int $index) use ($warehouse): Product {
            $product = Product::query()->create([
                'sku' => 'ROLLBACK-SKU-'.$index,
                'name' => 'Produkt '.$index,
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
        });

        $root = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'ROLLBACK-1001',
            'external_number' => 'ROLLBACK/1001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 600,
            'raw_payload' => [
                'id' => 1001,
                'number' => 'ROLLBACK/1001',
                'total' => '600.00',
            ],
            'external_created_at' => now()->subHour(),
        ]);

        $lines = $products->values()->map(function (Product $product, int $index) use ($root) {
            $price = ($index + 1) * 100;

            return $root->lines()->create([
                'product_id' => $product->id,
                'external_line_id' => 'line-'.($index + 1),
                'canonical_external_line_id' => 'line-'.($index + 1),
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity' => 1,
                'unit_net_price' => round($price / 1.23, 4),
                'unit_gross_price' => $price,
                'vat_rate' => 23,
                'raw_payload' => [
                    'id' => 'line-'.($index + 1),
                    'quantity' => 1,
                    'total' => number_format($price / 1.23, 2, '.', ''),
                    'total_tax' => number_format($price - ($price / 1.23), 2, '.', ''),
                ],
            ]);
        });

        $child = app(OrderSplitService::class)->split(
            $root,
            [
                $lines[1]->id => 1,
                $lines[2]->id => 1,
            ],
            'Druga paczka',
        );
        $grandchildSourceLine = $child->lines()
            ->where('canonical_external_line_id', 'line-3')
            ->firstOrFail();
        $grandchild = app(OrderSplitService::class)->split(
            $child,
            [$grandchildSourceLine->id => 1],
            'Trzecia paczka',
        );

        $this->assertSame('100.00', (string) $root->fresh()->total_gross);
        $this->assertSame('200.00', (string) $child->fresh()->total_gross);
        $this->assertSame('300.00', (string) $grandchild->fresh()->total_gross);

        return [$root->fresh(), $child->fresh(), $grandchild->fresh()];
    }
}
