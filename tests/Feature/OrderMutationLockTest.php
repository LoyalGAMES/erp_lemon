<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\WarehouseDocument;
use App\Services\Orders\OrderMutationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrderMutationLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_common_packing_fulfillment_lock_for_an_order(): void
    {
        $order = $this->order($this->channel('B2C'), '501');
        $key = 'packing-fulfillment-order-'.$order->id;

        $result = app(OrderMutationLock::class)->forOrder($order, function () use ($key): string {
            $this->assertFalse(Cache::lock($key, 900)->get());

            return 'completed';
        });

        $this->assertSame('completed', $result);

        $releasedLock = Cache::lock($key, 900);
        $this->assertTrue($releasedLock->get());
        $releasedLock->release();
    }

    public function test_it_locks_every_order_that_can_match_a_legacy_wz_reference(): void
    {
        $firstOrder = $this->order($this->channel('B2C'), '777');
        $secondOrder = $this->order($this->channel('B2B'), '777');
        $document = WarehouseDocument::query()->create([
            'number' => 'WZ/1/2026',
            'type' => 'WZ',
            'status' => 'draft',
            'document_date' => now(),
            'external_reference' => '777',
        ]);

        app(OrderMutationLock::class)->forWarehouseDocument(
            $document,
            function () use ($firstOrder, $secondOrder): void {
                $this->assertFalse(Cache::lock('packing-fulfillment-order-'.$firstOrder->id, 900)->get());
                $this->assertFalse(Cache::lock('packing-fulfillment-order-'.$secondOrder->id, 900)->get());
            },
        );
    }

    public function test_it_locks_the_root_and_every_split_member_for_a_family_mutation(): void
    {
        $channel = $this->channel('SPLIT');
        $root = $this->order($channel, '880');
        $child = $this->order($channel, '880-S1');
        $child->update([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);

        app(OrderMutationLock::class)->forOrderFamily($child, function () use ($root, $child): void {
            $this->assertFalse(Cache::lock('packing-fulfillment-order-'.$root->id, 900)->get());
            $this->assertFalse(Cache::lock('packing-fulfillment-order-'.$child->id, 900)->get());
        });
    }

    public function test_return_receipt_uses_the_lock_for_the_whole_split_family(): void
    {
        $channel = $this->channel('RETURN-RX');
        $root = $this->order($channel, '990');
        $child = $this->order($channel, '990-S1');
        $child->update([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);
        $document = WarehouseDocument::query()->create([
            'number' => 'RX/990/2026',
            'type' => 'RX',
            'status' => 'draft',
            'document_date' => now(),
        ]);
        ReturnCase::query()->create([
            'number' => 'RET/990/2026',
            'external_order_id' => $child->id,
            'warehouse_document_id' => $document->id,
            'status' => 'document_created',
        ]);

        app(OrderMutationLock::class)->forWarehouseDocument($document, function () use ($root, $child): void {
            $this->assertFalse(Cache::lock('packing-fulfillment-order-'.$root->id, 900)->get());
            $this->assertFalse(Cache::lock('packing-fulfillment-order-'.$child->id, 900)->get());
        });
    }

    private function channel(string $code): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function order(SalesChannel $channel, string $externalNumber): ExternalOrder
    {
        return ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => $externalNumber,
            'external_number' => $externalNumber,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
        ]);
    }
}
