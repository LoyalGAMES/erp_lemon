<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Orders\OrderSplitReversalService;
use App\Services\Orders\OrderSplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class OrderSplitLegacyReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_splitting_one_order_cannot_move_a_line_or_change_data_from_another_order(): void
    {
        Mail::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'SPLIT-FAMILY-ISOLATION',
            'name' => 'Izolacja podziału',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $first = $this->order($channel, 'ISOLATED-1001', 'ISOLATED/1001', 123, [
            'id' => 1001,
            'number' => 'ISOLATED/1001',
            'total' => '123.00',
        ], ['status' => 'processing', 'fulfillment_status' => null]);
        $firstLine = $first->lines()->create([
            'external_line_id' => 'isolated-line-a',
            'canonical_external_line_id' => 'isolated-line-a',
            'sku' => 'ISOLATED-A',
            'name' => 'Produkt pierwszego zamówienia',
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'vat_rate' => 23,
            'raw_payload' => ['id' => 'isolated-line-a', 'quantity' => 1, 'total' => '100', 'total_tax' => '23'],
        ]);
        $second = $this->order($channel, 'ISOLATED-2002', 'ISOLATED/2002', 246, [
            'id' => 2002,
            'number' => 'ISOLATED/2002',
            'total' => '246.00',
        ], ['status' => 'processing', 'fulfillment_status' => null]);
        $secondLine = $second->lines()->create([
            'external_line_id' => 'isolated-line-b',
            'canonical_external_line_id' => 'isolated-line-b',
            'sku' => 'ISOLATED-B',
            'name' => 'Produkt drugiego zamówienia',
            'quantity' => 1,
            'unit_net_price' => 200,
            'unit_gross_price' => 246,
            'vat_rate' => 23,
            'raw_payload' => ['id' => 'isolated-line-b', 'quantity' => 1, 'total' => '200', 'total_tax' => '46'],
        ]);
        $secondRaw = $second->getRawOriginal('raw_payload');

        try {
            app(OrderSplitService::class)->split($first, [$secondLine->id => 1]);
            $this->fail('A line from another order must never be accepted for a split.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Żadna wskazana pozycja', $exception->getMessage());
        }

        $this->assertSame(2, ExternalOrder::query()->count());
        $this->assertSame('123.00', (string) $first->fresh()->total_gross);
        $this->assertSame('246.00', (string) $second->fresh()->total_gross);
        $this->assertSame('1.0000', (string) $secondLine->fresh()->quantity);

        $child = app(OrderSplitService::class)->split($first->fresh(), [$firstLine->id => 1]);

        $this->assertSame($first->id, $child->split_parent_order_id);
        $this->assertSame($first->id, $child->split_root_order_id);
        $this->assertSame(2, ExternalOrder::query()
            ->where(fn ($query) => $query->whereKey($first->id)->orWhere('split_root_order_id', $first->id))
            ->count());
        $this->assertSame('246.00', (string) $second->fresh()->total_gross);
        $this->assertSame($secondRaw, $second->fresh()->getRawOriginal('raw_payload'));
        $this->assertSame('1.0000', (string) $secondLine->fresh()->quantity);
        $this->assertNull($second->fresh()->split_parent_order_id);
        $this->assertNull($second->fresh()->split_root_order_id);
    }

    public function test_historical_split_without_a_snapshot_is_restored_without_touching_an_unrelated_order(): void
    {
        Mail::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'LEGACY-SPLIT-REVERSAL',
            'name' => 'Historyczne podziały',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $root = $this->order($channel, 'LEGACY-1001', 'LEGACY/1001', 123, [
            'number' => 'LEGACY/1001',
            'status' => 'processing',
            'total' => '369.00',
            'line_items' => [
                [
                    'id' => 'legacy-line-a',
                    'sku' => 'LEGACY-A',
                    'name' => 'Produkt A',
                    'quantity' => 1,
                    'subtotal' => '100.00',
                    'total' => '123.00',
                ],
                [
                    'id' => 'legacy-line-b',
                    'sku' => 'LEGACY-B',
                    'name' => 'Produkt B',
                    'quantity' => 1,
                    'subtotal' => '200.00',
                    'total' => '246.00',
                ],
            ],
            'sempre_erp_split_child_orders' => ['LEGACY-1001-SPLIT-1'],
            'sempre_erp_split_allocations' => [[
                'child_external_id' => 'LEGACY-1001-SPLIT-1',
                'source_external_line_id' => 'legacy-line-b',
                'split_quantity' => 1,
            ]],
        ]);
        $rootLine = $root->lines()->create([
            'external_line_id' => 'legacy-line-a',
            'canonical_external_line_id' => 'legacy-line-a',
            'sku' => 'LEGACY-A',
            'name' => 'Produkt A',
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'vat_rate' => 23,
            'raw_payload' => ['id' => 'legacy-line-a', 'quantity' => 1],
        ]);

        $child = $this->order($channel, 'LEGACY-1001-SPLIT-1', 'LEGACY/1001/S1', 246, [
            'number' => 'LEGACY/1001',
            'status' => 'processing',
            'total' => '369.00',
            'line_items' => [
                [
                    'id' => 'legacy-line-a',
                    'sku' => 'LEGACY-A',
                    'name' => 'Produkt A',
                    'quantity' => 1,
                    'subtotal' => '100.00',
                    'total' => '123.00',
                ],
                [
                    'id' => 'legacy-line-b',
                    'sku' => 'LEGACY-B',
                    'name' => 'Produkt B',
                    'quantity' => 1,
                    'subtotal' => '200.00',
                    'total' => '246.00',
                ],
            ],
            'sempre_erp_split' => [
                'parent_order_id' => $root->id,
                'parent_external_id' => $root->external_id,
                'root_order_id' => $root->id,
                'root_external_id' => $root->external_id,
                'created_at' => now()->toISOString(),
            ],
        ], [
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);
        $childLine = $child->lines()->create([
            'external_line_id' => 'legacy-line-b-S1',
            'canonical_external_line_id' => 'legacy-line-b',
            'sku' => 'LEGACY-B',
            'name' => 'Produkt B',
            'quantity' => 1,
            'unit_net_price' => 200,
            'unit_gross_price' => 246,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => 'legacy-line-b',
                'quantity' => 1,
                'sempre_erp_split' => [
                    'source_order_line_id' => 999,
                    'source_external_line_id' => 'legacy-line-b',
                    'root_external_line_id' => 'legacy-line-b',
                    'source_quantity' => 1,
                    'split_quantity' => 1,
                ],
            ],
        ]);

        $unrelated = $this->order($channel, 'UNRELATED-2002', 'UNRELATED/2002', 999, [
            'id' => 2002,
            'number' => 'UNRELATED/2002',
            'status' => 'processing',
            'total' => '999.00',
        ]);
        $unrelatedLine = $unrelated->lines()->create([
            'external_line_id' => 'legacy-line-b',
            'canonical_external_line_id' => 'legacy-line-b',
            'sku' => 'UNRELATED',
            'name' => 'Niepowiązany produkt',
            'quantity' => 9,
            'unit_net_price' => 90,
            'unit_gross_price' => 111,
            'vat_rate' => 23,
            'raw_payload' => ['id' => 'legacy-line-b', 'quantity' => 9],
        ]);
        $unrelatedRaw = $unrelated->getRawOriginal('raw_payload');

        $this->assertNull(data_get($root->raw_payload, 'sempre_erp_split_original'));

        $reversal = app(OrderSplitReversalService::class);

        $rootRaw = (array) $root->fresh()->raw_payload;
        $rootRaw['sempre_erp_split_import_adjusted_at'] = now()->toISOString();
        $rootRaw['status'] = 'on-hold';
        $root->update([
            'status' => 'on-hold',
            'total_gross' => 123,
            'raw_payload' => $rootRaw,
        ]);

        $availableAfterReimport = $reversal->availability($child->fresh());

        $this->assertTrue($availableAfterReimport['available'], implode(' ', $availableAfterReimport['reasons']));

        $changedCommerceRaw = (array) $root->fresh()->raw_payload;
        $changedCommerceRaw['total'] = '400.00';
        $root->update(['raw_payload' => $changedCommerceRaw]);
        $blockedByRemoteCommerceChange = $reversal->availability($child->fresh());

        $this->assertFalse($blockedByRemoteCommerceChange['available']);
        $this->assertStringContainsString(
            'Dane handlowe zamówienia w WooCommerce zmieniły się',
            implode(' ', $blockedByRemoteCommerceChange['reasons']),
        );

        $changedCommerceRaw['total'] = '369.00';
        $root->update(['raw_payload' => $changedCommerceRaw]);

        $root->update([
            'external_updated_at' => now()->addMinute(),
        ]);

        $availableAfterLocalStatusUpdate = $reversal->availability($child->fresh());

        $this->assertTrue(
            $availableAfterLocalStatusUpdate['available'],
            implode(' ', $availableAfterLocalStatusUpdate['reasons']),
        );

        $legacyProblemTask = PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $root->id,
            'external_order_line_id' => $rootLine->id,
            'external_line_id' => $rootLine->external_line_id,
            'order_number' => $root->external_number,
            'product_name' => $rootLine->name,
            'quantity_required' => 1,
            'quantity_picked' => 0,
            'status' => 'problem',
            'metadata' => [
                'packing_problem' => [
                    'reported_at' => $child->created_at?->copy()->subSeconds(2)->toISOString(),
                    'reason' => 'Problem sprzed historycznego podziału',
                ],
            ],
        ]);
        $blockedByPreSplitProblem = $reversal->availability($child->fresh());

        $this->assertFalse($blockedByPreSplitProblem['available']);
        $this->assertStringContainsString(
            'problem pakowania został zgłoszony przed utworzeniem części',
            implode(' ', $blockedByPreSplitProblem['reasons']),
        );

        $legacyProblemTask->update(['metadata' => null]);
        $blockedByProblemWithoutMetadata = $reversal->availability($child->fresh());
        $this->assertFalse($blockedByProblemWithoutMetadata['available']);

        $legacyProblemTask->delete();

        $legacyCancelledTask = PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $root->id,
            'external_order_line_id' => $rootLine->id,
            'external_line_id' => $rootLine->external_line_id,
            'order_number' => $root->external_number,
            'product_name' => $rootLine->name,
            'quantity_required' => 1,
            'quantity_picked' => 0,
            'status' => 'cancelled',
            'metadata' => [
                'packing_sync' => [
                    'cancelled_reason' => 'order_line_removed_or_moved',
                    'cancelled_at' => $child->created_at?->copy()->addSeconds(2)->toISOString(),
                ],
            ],
        ]);
        $this->assertTrue($reversal->availability($child->fresh())['available']);

        $cancelledMetadata = (array) $legacyCancelledTask->metadata;
        data_set(
            $cancelledMetadata,
            'packing_sync.cancelled_at',
            $child->created_at?->copy()->subSeconds(2)->toISOString(),
        );
        $legacyCancelledTask->update(['metadata' => $cancelledMetadata]);
        $blockedByPreSplitCancellation = $reversal->availability($child->fresh());

        $this->assertFalse($blockedByPreSplitCancellation['available']);
        $this->assertStringContainsString(
            'zadanie pakowania zostało anulowane przed utworzeniem części',
            implode(' ', $blockedByPreSplitCancellation['reasons']),
        );

        $legacyCancelledTask->delete();

        $legacyPickedTask = PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $root->id,
            'external_order_line_id' => $rootLine->id,
            'external_line_id' => $rootLine->external_line_id,
            'order_number' => $root->external_number,
            'product_name' => $rootLine->name,
            'quantity_required' => 1,
            'quantity_picked' => 1,
            'status' => 'picked',
            'picked_at' => $child->created_at?->copy()->subSeconds(2),
        ]);
        $blockedByPreSplitPicking = $reversal->availability($child->fresh());

        $this->assertFalse($blockedByPreSplitPicking['available']);
        $this->assertStringContainsString(
            'przed utworzeniem części',
            implode(' ', $blockedByPreSplitPicking['reasons']),
        );

        $legacyPickedTask->delete();
        $legacyPostedWz = WarehouseDocument::query()->create([
            'number' => 'WZ/LEGACY/NO-BASELINE',
            'type' => 'WZ',
            'status' => 'posted',
            'document_date' => now(),
            'external_reference' => $root->external_id,
            'order_fulfillment_key' => 'legacy-no-source-baseline',
            'posted_at' => now(),
            'metadata' => [
                'external_order_id' => $root->external_id,
                'external_order_number' => $root->external_number,
                'sales_channel_id' => $channel->id,
            ],
        ]);
        $legacyPostedWz->timestamps = false;
        $legacyPostedWz->forceFill([
            'created_at' => $child->created_at?->copy()->addSeconds(2),
            'updated_at' => $child->created_at?->copy()->addSeconds(2),
        ])->save();
        $legacyPostedWz->timestamps = true;
        $blockedByLegacyWz = $reversal->availability($child->fresh());

        $this->assertFalse($blockedByLegacyWz['available']);
        $this->assertStringContainsString(
            'zapisu bazowego stanu magazynowego',
            implode(' ', $blockedByLegacyWz['reasons']),
        );

        $legacyPostedWz->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $cancelledPostedWzWithoutLedger = $reversal->availability($child->fresh());
        $this->assertFalse($cancelledPostedWzWithoutLedger['available']);
        $legacyPostedWz->update(['status' => 'posted', 'cancelled_at' => null]);

        $legacyWarehouse = Warehouse::query()->create([
            'code' => 'LEGACY-PAIR-WH',
            'name' => 'Magazyn historycznej pary WZ',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $legacyProduct = Product::query()->create([
            'sku' => 'LEGACY-PAIR-SKU',
            'name' => 'Produkt historycznej pary WZ',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $legacyWzLine = $legacyPostedWz->lines()->create([
            'product_id' => $legacyProduct->id,
            'quantity' => 1,
        ]);
        StockLedgerEntry::query()->create([
            'warehouse_document_id' => $legacyPostedWz->id,
            'warehouse_document_line_id' => $legacyWzLine->id,
            'warehouse_id' => $legacyWarehouse->id,
            'product_id' => $legacyProduct->id,
            'quantity_change' => -1,
            'direction' => 'out',
            'posted_at' => now()->subMinute(),
            'metadata' => ['document_number' => $legacyPostedWz->number],
        ]);
        $legacyCancellationEntry = StockLedgerEntry::query()->create([
            'warehouse_document_id' => $legacyPostedWz->id,
            'warehouse_document_line_id' => $legacyWzLine->id,
            'warehouse_id' => $legacyWarehouse->id,
            'product_id' => $legacyProduct->id,
            'quantity_change' => 1,
            'direction' => 'in',
            'posted_at' => now(),
            'metadata' => [
                'source' => 'warehouse_document_cancelled',
                'reverses_original_change' => -1,
            ],
        ]);
        $legacyPostedWz->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $safeCancelledLegacyWz = $reversal->availability($child->fresh());

        $this->assertTrue($safeCancelledLegacyWz['available'], implode(' ', $safeCancelledLegacyWz['reasons']));

        $legacyCancellationEntry->update(['quantity_change' => 0.5]);
        $incompleteCancelledLegacyWz = $reversal->availability($child->fresh());
        $this->assertFalse($incompleteCancelledLegacyWz['available']);
        $legacyCancellationEntry->update(['quantity_change' => 1]);

        $legacyPostedWz->delete();
        $root->update(['external_updated_at' => null]);
        $availability = $reversal->availability($child->fresh());

        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $restored = $reversal->reverse(
            $child->fresh(),
            $availability['version'],
            'Przywrócenie historycznego podziału',
        )->fresh('lines');

        $this->assertSame($root->id, $restored->id);
        $this->assertSame('369.00', (string) $restored->total_gross);
        $this->assertSame('processing', $restored->status);
        $this->assertSame([
            'legacy-line-a' => '1.0000',
            'legacy-line-b' => '1.0000',
        ], $restored->lines
            ->sortBy('external_line_id')
            ->mapWithKeys(fn ($line): array => [(string) $line->external_line_id => (string) $line->quantity])
            ->all());
        $this->assertArrayNotHasKey('sempre_erp_split_child_orders', (array) $restored->raw_payload);
        $this->assertArrayNotHasKey('sempre_erp_split_allocations', (array) $restored->raw_payload);
        $this->assertArrayNotHasKey('sempre_erp_split_import_adjusted_at', (array) $restored->raw_payload);
        $this->assertSame('369.00', (string) data_get($restored->raw_payload, 'total'));
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        $this->assertNull($rootLine->fresh());
        $this->assertSame($child->id, $childLine->fresh()?->order?->id);
        $this->assertNotNull($childLine->fresh()?->order?->deleted_at);

        $this->assertNotNull($unrelated->fresh());
        $this->assertSame('999.00', (string) $unrelated->fresh()->total_gross);
        $this->assertSame($unrelatedRaw, $unrelated->fresh()->getRawOriginal('raw_payload'));
        $this->assertSame('9.0000', (string) $unrelatedLine->fresh()->quantity);
        $this->assertSame(2, ExternalOrder::query()->count());
        $this->assertSame(3, ExternalOrder::withTrashed()->count());
    }

    public function test_corrupt_cross_channel_family_pointer_is_blocked_before_any_order_is_changed(): void
    {
        Mail::fake();

        $rootChannel = SalesChannel::query()->create([
            'code' => 'SAFE-FAMILY-ROOT',
            'name' => 'Kanał rodziny',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $foreignChannel = SalesChannel::query()->create([
            'code' => 'SAFE-FAMILY-FOREIGN',
            'name' => 'Obcy kanał',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $root = $this->order($rootChannel, 'SAFE-ROOT-1', 'SAFE/ROOT/1', 123, [
            'id' => 1,
            'number' => 'SAFE/ROOT/1',
            'total' => '123.00',
        ], ['status' => 'processing', 'fulfillment_status' => null]);
        $root->lines()->create([
            'external_line_id' => 'safe-root-line',
            'canonical_external_line_id' => 'safe-root-line',
            'sku' => 'SAFE-ROOT',
            'name' => 'Bezpieczna pozycja',
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'vat_rate' => 23,
            'raw_payload' => ['id' => 'safe-root-line', 'quantity' => 1],
        ]);
        $foreign = $this->order($foreignChannel, 'FOREIGN-ORDER-9', 'FOREIGN/ORDER/9', 999, [
            'id' => 9,
            'number' => 'FOREIGN/ORDER/9',
            'total' => '999.00',
            'sempre_erp_split' => [
                'parent_order_id' => $root->id,
                'parent_external_id' => $root->external_id,
                'root_order_id' => $root->id,
                'root_external_id' => $root->external_id,
            ],
        ], [
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
            'status' => 'processing',
            'fulfillment_status' => null,
        ]);
        $foreignRaw = $foreign->getRawOriginal('raw_payload');

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($root->fresh());

        $this->assertFalse($availability['available']);
        $this->assertStringContainsString('innego kanału', implode(' ', $availability['reasons']));
        $this->assertFalse(app(OrderSplitService::class)->availability($root->fresh())['available']);

        try {
            $reversal->reverse($root->fresh(), $availability['version']);
            $this->fail('A corrupt cross-channel family must never be reversed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('innego kanału', $exception->getMessage());
        }

        $this->assertSame('123.00', (string) $root->fresh()->total_gross);
        $this->assertSame('999.00', (string) $foreign->fresh()->total_gross);
        $this->assertSame($foreignRaw, $foreign->fresh()->getRawOriginal('raw_payload'));
        $this->assertSame(2, ExternalOrder::query()->count());
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>  $overrides
     */
    private function order(
        SalesChannel $channel,
        string $externalId,
        string $externalNumber,
        float $total,
        array $rawPayload,
        array $overrides = [],
    ): ExternalOrder {
        return ExternalOrder::query()->create(array_merge([
            'sales_channel_id' => $channel->id,
            'external_id' => $externalId,
            'external_number' => $externalNumber,
            'status' => 'ready-to-ship',
            'fulfillment_status' => 'awaiting_courier',
            'currency' => 'PLN',
            'total_gross' => $total,
            'raw_payload' => $rawPayload,
            'external_created_at' => now()->subDays(30),
        ], $overrides));
    }
}
