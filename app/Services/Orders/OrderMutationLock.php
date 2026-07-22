<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Models\WarehouseDocument;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class OrderMutationLock
{
    private const LOCK_SECONDS = 900;

    private const WAIT_SECONDS = 15;

    public function forOrder(ExternalOrder $order, callable $operation): mixed
    {
        return $this->forOrderIds([(int) $order->id], $operation);
    }

    /**
     * Serialize a mutation against the root order and every physical order in
     * its split family. Locking only the order referenced by a return would
     * leave cancellation of another split member able to race the mutation.
     */
    public function forOrderFamily(ExternalOrder $order, callable $operation): mixed
    {
        return $this->forStableOrderFamilies([(int) $order->id], $operation);
    }

    /**
     * The split-reversal saga itself must be able to resume while its durable
     * operation marker is present. All ordinary mutations use forOrderFamily()
     * and remain blocked until the saga removes that marker during final merge.
     */
    public function forSplitReversal(ExternalOrder $order, callable $operation): mixed
    {
        return $this->forStableOrderFamilies(
            [(int) $order->id],
            $operation,
            allowSplitReversal: true,
        );
    }

    /**
     * A split can finish while this process is waiting for the root lock. The
     * family list captured before waiting is then incomplete. Once the known
     * members are locked, resolve the family again and lock every newly added
     * member before the caller is allowed to mutate anything.
     *
     * @param  list<int>  $seedOrderIds
     */
    private function forStableOrderFamilies(
        array $seedOrderIds,
        callable $operation,
        bool $allowSplitReversal = false,
    ): mixed {
        $seedOrderIds = collect($seedOrderIds)
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->filter(fn (int $orderId): bool => $orderId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($seedOrderIds === []) {
            return $operation();
        }

        $orderIds = $this->familyOrderIds($seedOrderIds);

        if ($orderIds === []) {
            $orderIds = $seedOrderIds;
        }

        try {
            return $this->acquire(
                $orderIds,
                0,
                fn (): mixed => $this->acquireNewFamilyMembers(
                    $seedOrderIds,
                    $orderIds,
                    $operation,
                    $allowSplitReversal,
                ),
            );
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'To zamówienie jest właśnie aktualizowane lub obsługiwane w procesie pakowania. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<int>  $seedOrderIds
     * @param  list<int>  $lockedOrderIds
     */
    private function acquireNewFamilyMembers(
        array $seedOrderIds,
        array $lockedOrderIds,
        callable $operation,
        bool $allowSplitReversal,
    ): mixed {
        $currentFamilyIds = $this->familyOrderIds($seedOrderIds);
        $newOrderIds = collect($currentFamilyIds)
            ->diff($lockedOrderIds)
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->sort()
            ->values()
            ->all();

        if ($newOrderIds !== []) {
            $lastLockedOrderId = max($lockedOrderIds);

            // Split children are newly inserted rows, so their IDs must be
            // greater than every member already locked in ascending order.
            // Refuse corrupted/re-parented lineage instead of risking a
            // reverse-order distributed lock and a deadlock.
            if (min($newOrderIds) <= $lastLockedOrderId) {
                throw new RuntimeException(
                    'Rodzina zamówienia zmieniła się w sposób, którego nie można bezpiecznie zablokować. Odśwież widok i spróbuj ponownie.',
                );
            }

            $expandedLockedOrderIds = collect([...$lockedOrderIds, ...$newOrderIds])
                ->unique()
                ->sort()
                ->values()
                ->all();

            return $this->acquire(
                $newOrderIds,
                0,
                fn (): mixed => $this->acquireNewFamilyMembers(
                    $seedOrderIds,
                    $expandedLockedOrderIds,
                    $operation,
                    $allowSplitReversal,
                ),
            );
        }

        $this->assertOrdersRemainActive($seedOrderIds);

        if (! $allowSplitReversal) {
            $this->assertNoSplitReversalInProgress($lockedOrderIds);
        }

        return $operation();
    }

    /**
     * @param  iterable<int, ExternalOrder|int>  $orders
     */
    public function forOrders(iterable $orders, callable $operation): mixed
    {
        $orderIds = collect($orders)
            ->map(fn (ExternalOrder|int $order): int => $order instanceof ExternalOrder
                ? (int) $order->id
                : (int) $order)
            ->all();

        return $this->forStableOrderFamilies($orderIds, $operation);
    }

    public function forWarehouseDocument(WarehouseDocument $document, callable $operation): mixed
    {
        return $this->forStableOrderFamilies($this->linkedOrderIds($document), $operation);
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function forOrderIds(
        array $orderIds,
        callable $operation,
        bool $allowSplitReversal = false,
    ): mixed {
        $orderIds = collect($orderIds)
            ->filter(fn (int $orderId): bool => $orderId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        try {
            return $this->acquire(
                $orderIds,
                0,
                function () use ($orderIds, $operation, $allowSplitReversal): mixed {
                    $this->assertOrdersRemainActive($orderIds);

                    if (! $allowSplitReversal) {
                        $this->assertNoSplitReversalInProgress($orderIds);
                    }

                    return $operation();
                },
            );
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'To zamówienie jest właśnie aktualizowane lub obsługiwane w procesie pakowania. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function acquire(array $orderIds, int $offset, callable $operation): mixed
    {
        if (! isset($orderIds[$offset])) {
            return $operation();
        }

        return Cache::lock($this->lockKey($orderIds[$offset]), self::LOCK_SECONDS)
            ->block(
                self::WAIT_SECONDS,
                fn (): mixed => $this->acquire($orderIds, $offset + 1, $operation),
            );
    }

    /**
     * @return list<int>
     */
    private function linkedOrderIds(WarehouseDocument $document): array
    {
        if ($document->type === 'RX') {
            $orderIds = ReturnCase::query()
                ->where(function (Builder $query) use ($document): void {
                    $query
                        ->where('warehouse_document_id', $document->id)
                        ->orWhereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery
                            ->where('warehouse_document_id', $document->id));
                })
                ->whereNotNull('external_order_id')
                ->pluck('external_order_id')
                ->map(fn (mixed $orderId): int => (int) $orderId)
                ->all();

            return $this->familyOrderIds($orderIds);
        }

        if ($document->type !== 'WZ') {
            return [];
        }

        $metadata = (array) $document->metadata;
        $externalId = trim((string) ($metadata['external_order_id'] ?? ''));
        $externalNumber = trim((string) ($metadata['external_order_number'] ?? ''));
        $externalReference = trim((string) $document->external_reference);
        $identifiers = array_values(array_unique(array_filter([
            $externalId,
            $externalNumber,
            $externalReference,
        ], fn (string $value): bool => $value !== '')));

        if ($identifiers === []) {
            return [];
        }

        $salesChannelId = filter_var(
            $metadata['sales_channel_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return ExternalOrder::query()
            ->when(
                $salesChannelId !== false,
                fn (Builder $query): Builder => $query->where('sales_channel_id', $salesChannelId),
            )
            ->where(function (Builder $query) use ($identifiers): void {
                $query
                    ->whereIn('external_id', $identifiers)
                    ->orWhereIn('external_number', $identifiers);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function familyOrderIds(array $orderIds): array
    {
        $families = ExternalOrder::query()
            ->whereKey($orderIds)
            ->get(['id', 'sales_channel_id', 'split_root_order_id'])
            ->map(fn (ExternalOrder $order): array => [
                'root_id' => (int) ($order->split_root_order_id ?: $order->id),
                'sales_channel_id' => (int) $order->sales_channel_id,
            ])
            ->unique(fn (array $family): string => $family['sales_channel_id'].':'.$family['root_id'])
            ->values();

        if ($families->isEmpty()) {
            return [];
        }

        return ExternalOrder::query()
            ->where(function (Builder $query) use ($families): void {
                foreach ($families as $family) {
                    $query->orWhere(function (Builder $familyQuery) use ($family): void {
                        $familyQuery
                            ->where('sales_channel_id', $family['sales_channel_id'])
                            ->where(function (Builder $memberQuery) use ($family): void {
                                $memberQuery
                                    ->whereKey($family['root_id'])
                                    ->orWhere('split_root_order_id', $family['root_id']);
                            });
                    });
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->all();
    }

    private function lockKey(int $orderId): string
    {
        return 'packing-fulfillment-order-'.$orderId;
    }

    /** @param list<int> $orderIds */
    private function assertOrdersRemainActive(array $orderIds): void
    {
        $activeOrderIds = ExternalOrder::query()
            ->whereKey($orderIds)
            ->pluck('id')
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->all();
        $missingOrderIds = array_values(array_diff($orderIds, $activeOrderIds));

        if ($missingOrderIds !== []) {
            throw new RuntimeException(
                'Zamówienie albo jedna z jego części zostały w międzyczasie zarchiwizowane. Odśwież widok przed wykonaniem kolejnej operacji.',
            );
        }
    }

    /** @param list<int> $orderIds */
    private function assertNoSplitReversalInProgress(array $orderIds): void
    {
        $families = ExternalOrder::query()
            ->whereKey($orderIds)
            ->get(['id', 'sales_channel_id', 'split_root_order_id'])
            ->map(fn (ExternalOrder $order): array => [
                'root_id' => (int) ($order->split_root_order_id ?: $order->id),
                'sales_channel_id' => (int) $order->sales_channel_id,
            ])
            ->unique(fn (array $family): string => $family['sales_channel_id'].':'.$family['root_id'])
            ->values();

        if ($families->isEmpty()) {
            return;
        }

        $reversingRoot = ExternalOrder::query()
            ->where(function (Builder $query) use ($families): void {
                foreach ($families as $family) {
                    $query->orWhere(function (Builder $familyQuery) use ($family): void {
                        $familyQuery
                            ->whereKey($family['root_id'])
                            ->where('sales_channel_id', $family['sales_channel_id']);
                    });
                }
            })
            ->get(['id', 'external_number', 'raw_payload'])
            ->first(fn (ExternalOrder $root): bool => $root->hasSplitReversalOperation());

        if ($reversingRoot instanceof ExternalOrder) {
            $number = trim((string) ($reversingRoot->external_number ?: $reversingRoot->id));

            throw new RuntimeException(
                "Dla zamówienia {$number} trwa niedokończone cofanie podziału. Dokończ je w widoku zamówienia przed wykonaniem kolejnej operacji.",
            );
        }
    }
}
