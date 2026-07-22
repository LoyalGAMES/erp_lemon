<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\WarehouseDocument;
use App\Services\Orders\OrderMutationLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
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

    public function test_it_locks_a_split_member_created_while_waiting_for_the_known_family(): void
    {
        $this->assertLateSplitMemberIsLocked(
            fn (OrderMutationLock $locks, ExternalOrder $root, ExternalOrder $child, callable $operation): mixed => $locks
                ->forOrderFamily($child, $operation),
        );
    }

    public function test_it_stabilizes_every_family_passed_to_for_orders(): void
    {
        $this->assertLateSplitMemberIsLocked(
            fn (OrderMutationLock $locks, ExternalOrder $root, ExternalOrder $child, callable $operation): mixed => $locks
                ->forOrders([$root, $child], $operation),
        );
    }

    public function test_for_order_does_not_run_after_the_waited_order_was_archived(): void
    {
        $order = $this->order($this->channel('ARCHIVED-ORDER-RACE'), '882');
        $key = 'packing-fulfillment-order-'.$order->id;
        $operationRan = false;
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->andReturnUsing(function (int $waitSeconds, callable $callback) use ($order): mixed {
                $this->assertSame(15, $waitSeconds);
                $order->delete();

                return $callback();
            });
        Cache::shouldReceive('lock')
            ->once()
            ->with($key, 900)
            ->andReturn($lock);

        try {
            app(OrderMutationLock::class)->forOrder($order, function () use (&$operationRan): void {
                $operationRan = true;
            });
            $this->fail('An operation waiting on an archived order must not run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zarchiwizowane', $exception->getMessage());
        }

        $this->assertFalse($operationRan);
        $this->assertSoftDeleted('external_orders', ['id' => $order->id]);
    }

    public function test_family_operation_does_not_run_after_its_requested_child_was_archived(): void
    {
        $channel = $this->channel('ARCHIVED-FAMILY-RACE');
        $root = $this->order($channel, '883');
        $child = $this->order($channel, '883-S1');
        $child->update([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);
        $rootKey = 'packing-fulfillment-order-'.$root->id;
        $childKey = 'packing-fulfillment-order-'.$child->id;
        $childArchived = false;
        $operationRan = false;

        Cache::shouldReceive('lock')
            ->andReturnUsing(function (string $key, int $seconds) use (
                $child,
                $rootKey,
                $childKey,
                &$childArchived,
            ): Lock {
                $this->assertContains($key, [$rootKey, $childKey]);
                $this->assertSame(900, $seconds);
                $lock = Mockery::mock(Lock::class);
                $lock->shouldReceive('block')
                    ->once()
                    ->andReturnUsing(function (int $waitSeconds, callable $callback) use (
                        $key,
                        $child,
                        $rootKey,
                        &$childArchived,
                    ): mixed {
                        $this->assertSame(15, $waitSeconds);

                        if ($key === $rootKey && ! $childArchived) {
                            $childArchived = true;
                            $child->delete();
                        }

                        return $callback();
                    });

                return $lock;
            });

        try {
            app(OrderMutationLock::class)->forOrderFamily($child, function () use (&$operationRan): void {
                $operationRan = true;
            });
            $this->fail('A family operation waiting on an archived child must not run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zarchiwizowane', $exception->getMessage());
        }

        $this->assertFalse($operationRan);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
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

    /**
     * @param  callable(OrderMutationLock,ExternalOrder,ExternalOrder,callable):mixed  $invoke
     */
    private function assertLateSplitMemberIsLocked(callable $invoke): void
    {
        $channel = $this->channel('SPLIT-RACE');
        $root = $this->order($channel, '881');
        $child = $this->order($channel, '881-S1');
        $child->update([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);
        $rootKey = 'packing-fulfillment-order-'.$root->id;
        $childKey = 'packing-fulfillment-order-'.$child->id;
        $grandchild = null;
        $inserted = false;
        $heldLocks = [];
        $acquiredLocks = [];

        Cache::shouldReceive('lock')
            ->andReturnUsing(function (string $key, int $seconds) use (
                $channel,
                $root,
                $child,
                $rootKey,
                &$grandchild,
                &$inserted,
                &$heldLocks,
                &$acquiredLocks,
            ): Lock {
                $this->assertSame(900, $seconds);
                $lock = Mockery::mock(Lock::class);
                $lock->shouldReceive('block')
                    ->once()
                    ->andReturnUsing(function (int $waitSeconds, callable $callback) use (
                        $key,
                        $channel,
                        $root,
                        $child,
                        $rootKey,
                        &$grandchild,
                        &$inserted,
                        &$heldLocks,
                        &$acquiredLocks,
                    ): mixed {
                        $this->assertSame(15, $waitSeconds);
                        $heldLocks[$key] = true;
                        $acquiredLocks[] = $key;

                        // The initial family query has already returned root +
                        // child. Insert another split member before the waiter
                        // enters its callback, as a split finishing while the
                        // waiter was blocked on the root lock would do.
                        if ($key === $rootKey && ! $inserted) {
                            $inserted = true;
                            $grandchild = $this->order($channel, '881-S1-S1');
                            $grandchild->update([
                                'split_parent_order_id' => $child->id,
                                'split_root_order_id' => $root->id,
                            ]);
                        }

                        try {
                            return $callback();
                        } finally {
                            unset($heldLocks[$key]);
                        }
                    });

                return $lock;
            });

        $invoke(
            app(OrderMutationLock::class),
            $root,
            $child,
            function () use (
                $rootKey,
                $childKey,
                &$grandchild,
                &$heldLocks,
            ): void {
                $this->assertInstanceOf(ExternalOrder::class, $grandchild);
                $grandchildKey = 'packing-fulfillment-order-'.$grandchild->id;

                $this->assertArrayHasKey($rootKey, $heldLocks);
                $this->assertArrayHasKey($childKey, $heldLocks);
                $this->assertArrayHasKey($grandchildKey, $heldLocks);
            },
        );

        $this->assertInstanceOf(ExternalOrder::class, $grandchild);
        $this->assertSame([
            $rootKey,
            $childKey,
            'packing-fulfillment-order-'.$grandchild->id,
        ], $acquiredLocks);
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
