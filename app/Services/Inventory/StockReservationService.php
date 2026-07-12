<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ExternalOrder;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\WarehouseDocument;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderStatusPolicyService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class StockReservationService
{
    private const ACTIVE_STATUS = 'active';

    private const WAITING_STATUS = 'waiting';

    private const RELEASED_STATUS = 'released';

    public function __construct(
        private readonly SalesChannelWarehouseResolver $warehouseResolver,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly StockSyncQueueService $stockSyncQueue,
        private readonly OrderStatusPolicyService $statusPolicy,
    ) {}

    /**
     * @return array{reserved:int,released:int,skipped:int}
     */
    public function syncForOrder(ExternalOrder $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = ExternalOrder::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($order->id);

            $openReservations = $this->openReservations($order);
            $reserved = 0;
            $skipped = $this->skippedReservationLines($order);
            $releasedPairs = [];
            $newPairs = [];

            if (! $this->shouldReserve($order->status) || $this->fulfillmentStatus->hasPostedWz($order)) {
                $releasedPairs = $this->releaseReservations($openReservations);
            } else {
                $routes = $this->warehouseResolver->allocationRoutes((int) $order->sales_channel_id);
                $warehouseIds = $routes
                    ->pluck('warehouse_id')
                    ->map(fn (mixed $warehouseId): int => (int) $warehouseId)
                    ->all();

                if ($this->openReservationsMatchOrder($order, $openReservations, $warehouseIds)) {
                    return [
                        'reserved' => 0,
                        'released' => 0,
                        'skipped' => $skipped,
                    ];
                }

                $releasedPairs = $this->releaseReservations($openReservations);

                foreach ($order->lines as $line) {
                    if ($line->product_id === null || (float) $line->quantity <= 0) {
                        continue;
                    }

                    $quantity = (float) $line->quantity;
                    $remainingQuantity = $quantity;
                    $activeQuantity = 0.0;

                    foreach ($routes as $route) {
                        if ($remainingQuantity <= 0) {
                            break;
                        }

                        $warehouseId = (int) $route->warehouse_id;
                        $buffer = max(0, (float) $route->stock_buffer);
                        $available = max(
                            0,
                            $this->availableQuantity($warehouseId, (int) $line->product_id) - $buffer,
                        );
                        $routeQuantity = min($remainingQuantity, $available);

                        if ($routeQuantity <= 0) {
                            continue;
                        }

                        $this->createReservation(
                            $warehouseId,
                            (int) $line->product_id,
                            (int) $order->sales_channel_id,
                            (string) $order->external_id,
                            $routeQuantity,
                            self::ACTIVE_STATUS,
                            [
                                'external_order_number' => $order->external_number,
                                'external_order_line_id' => $line->external_line_id,
                                'source' => 'woocommerce_order_import',
                                'route_priority' => (int) $route->priority,
                                'route_stock_buffer' => $buffer,
                            ],
                        );
                        $activeQuantity += $routeQuantity;
                        $remainingQuantity -= $routeQuantity;
                        $newPairs[] = [$warehouseId, (int) $line->product_id];
                    }

                    if ($remainingQuantity > 0) {
                        $waitingWarehouseId = (int) $routes->first()->warehouse_id;
                        $this->createReservation(
                            $waitingWarehouseId,
                            (int) $line->product_id,
                            (int) $order->sales_channel_id,
                            (string) $order->external_id,
                            $remainingQuantity,
                            self::WAITING_STATUS,
                            [
                                'external_order_number' => $order->external_number,
                                'external_order_line_id' => $line->external_line_id,
                                'source' => 'woocommerce_order_import',
                                'reason' => 'stock_shortage',
                                'requested_quantity' => $quantity,
                                'active_quantity' => $activeQuantity,
                            ],
                        );
                        $newPairs[] = [$waitingWarehouseId, (int) $line->product_id];
                    }

                    $reserved++;
                }
            }

            $changedPairs = $this->uniquePairs([...$releasedPairs, ...$newPairs]);

            foreach ($changedPairs as [$warehouseId, $productId]) {
                $this->recalculateBalance($warehouseId, $productId);
            }

            $this->queueStockSync($changedPairs, 'order_reservation_synced');

            return [
                'reserved' => $reserved,
                'released' => count($releasedPairs),
                'skipped' => $skipped,
            ];
        }, 3);
    }

    public function recalculateBalance(int $warehouseId, int $productId): void
    {
        $balance = $this->lockedBalance($warehouseId, $productId);
        $reservations = StockReservation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('status', self::ACTIVE_STATUS)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $reserved = (float) $reservations->sum(
            fn (StockReservation $reservation): float => (float) $reservation->quantity,
        );
        $onHand = (float) $balance->quantity_on_hand;

        if ($balance->source_sales_channel_id !== null
            && $balance->source_available_quantity !== null
            && $balance->source_observed_at !== null
        ) {
            $reflectedOrderQuantities = $this->reconciledReflectedOrderQuantities(
                $reservations,
                (int) $balance->source_sales_channel_id,
                $balance->source_observed_at,
                (array) $balance->source_reflected_order_quantities,
            );
            $onHand = max(0, (float) $balance->source_available_quantity)
                + array_sum($reflectedOrderQuantities);
        } else {
            $reflectedOrderQuantities = null;
        }

        $balance->update([
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
            'quantity_available' => max(0, $onHand - $reserved),
            'source_reflected_order_quantities' => $reflectedOrderQuantities,
            'recalculated_at' => now(),
        ]);
    }

    public function activeQuantity(int $warehouseId, int $productId): float
    {
        return (float) StockReservation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('status', self::ACTIVE_STATUS)
            ->sum('quantity');
    }

    public function applySourceAvailabilitySnapshot(
        int $warehouseId,
        int $productId,
        int $salesChannelId,
        float $availableQuantity,
        CarbonInterface $observedAt,
    ): void {
        $balance = $this->lockedBalance($warehouseId, $productId);
        $waitingReservations = StockReservation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('sales_channel_id', $salesChannelId)
            ->where('status', self::WAITING_STATUS)
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $orders = $this->ordersForReservations($waitingReservations, $salesChannelId);

        foreach ($waitingReservations as $reservation) {
            if (! $this->reservationWasReflected($reservation, $orders, $observedAt)) {
                continue;
            }

            $reservation->update([
                'status' => self::ACTIVE_STATUS,
                'released_at' => null,
                'metadata' => array_replace_recursive((array) $reservation->metadata, [
                    'source_stock_snapshot' => [
                        'promoted_from_waiting_at' => now()->toISOString(),
                        'source_observed_at' => $observedAt->toISOString(),
                    ],
                ]),
            ]);
        }

        $balance->update([
            'source_sales_channel_id' => $salesChannelId,
            'source_available_quantity' => max(0, $availableQuantity),
            'source_observed_at' => $observedAt,
            // A fresh Woo snapshot supersedes the previous baseline. The
            // recalculation below rebuilds it from reservations that the
            // snapshot already reflects.
            'source_reflected_order_quantities' => [],
        ]);

        $this->recalculateBalance($warehouseId, $productId);
    }

    public function releaseForPostedDocument(WarehouseDocument $document): int
    {
        if ($document->type !== 'WZ') {
            return 0;
        }

        $externalOrderId = $document->metadata['external_order_id'] ?? null;
        $salesChannelId = $document->metadata['sales_channel_id'] ?? null;

        if ($externalOrderId === null || $salesChannelId === null || $document->source_warehouse_id === null) {
            return 0;
        }

        $released = 0;
        $pairs = [];

        foreach ($document->lines as $line) {
            $reservations = StockReservation::query()
                ->where('sales_channel_id', (int) $salesChannelId)
                ->where('external_order_id', (string) $externalOrderId)
                ->where('warehouse_id', $document->source_warehouse_id)
                ->where('product_id', $line->product_id)
                ->where('status', self::ACTIVE_STATUS)
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->update([
                    'status' => self::RELEASED_STATUS,
                    'released_at' => now(),
                ]);
                $released++;
                $pairs[] = [(int) $reservation->warehouse_id, (int) $reservation->product_id];
            }
        }

        foreach ($this->uniquePairs($pairs) as [$warehouseId, $productId]) {
            $this->recalculateBalance($warehouseId, $productId);
        }

        return $released;
    }

    public function allocateWaitingReservations(int $warehouseId, int $productId): int
    {
        $allocated = 0;

        $waitingReservations = StockReservation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('status', self::WAITING_STATUS)
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($waitingReservations as $waitingReservation) {
            $available = $this->availableQuantity($warehouseId, $productId);

            if ($available <= 0) {
                break;
            }

            $waitingQuantity = (float) $waitingReservation->quantity;
            $quantityToActivate = min($waitingQuantity, $available);

            if ($quantityToActivate <= 0) {
                continue;
            }

            if ($quantityToActivate >= $waitingQuantity) {
                $waitingReservation->update([
                    'status' => self::ACTIVE_STATUS,
                    'metadata' => array_replace_recursive((array) $waitingReservation->metadata, [
                        'waiting_allocation' => [
                            'allocated_at' => now()->toISOString(),
                            'allocated_quantity' => $quantityToActivate,
                            'allocation_type' => 'full',
                        ],
                    ]),
                ]);
            } else {
                $waitingReservation->update([
                    'quantity' => $waitingQuantity - $quantityToActivate,
                    'metadata' => array_replace_recursive((array) $waitingReservation->metadata, [
                        'waiting_allocation' => [
                            'last_partial_allocation_at' => now()->toISOString(),
                            'last_allocated_quantity' => $quantityToActivate,
                            'remaining_quantity' => $waitingQuantity - $quantityToActivate,
                        ],
                    ]),
                ]);

                $this->createReservation(
                    $warehouseId,
                    $productId,
                    (int) $waitingReservation->sales_channel_id,
                    (string) $waitingReservation->external_order_id,
                    $quantityToActivate,
                    self::ACTIVE_STATUS,
                    array_replace_recursive((array) $waitingReservation->metadata, [
                        'source' => 'waiting_reservation_allocation',
                        'waiting_reservation_id' => $waitingReservation->id,
                        'waiting_allocation' => [
                            'allocated_at' => now()->toISOString(),
                            'allocated_quantity' => $quantityToActivate,
                            'allocation_type' => 'partial',
                        ],
                    ]),
                );
            }

            $allocated++;
        }

        if ($allocated > 0) {
            $this->recalculateBalance($warehouseId, $productId);
        }

        return $allocated;
    }

    private function shouldReserve(string $status): bool
    {
        return $this->statusPolicy->shouldReserve($status);
    }

    /**
     * @return Collection<int, StockReservation>
     */
    private function openReservations(ExternalOrder $order): Collection
    {
        return StockReservation::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where('external_order_id', $order->external_id)
            ->whereIn('status', [self::ACTIVE_STATUS, self::WAITING_STATUS])
            ->get();
    }

    /**
     * @param  Collection<int, StockReservation>  $reservations
     * @return list<array{0:int,1:int}>
     */
    private function releaseReservations(Collection $reservations): array
    {
        $pairs = [];

        foreach ($reservations as $reservation) {
            $pairs[] = [(int) $reservation->warehouse_id, (int) $reservation->product_id];
            $reservation->update([
                'status' => self::RELEASED_STATUS,
                'released_at' => now(),
            ]);
        }

        return $pairs;
    }

    /**
     * @param  Collection<int, StockReservation>  $reservations
     * @param  list<int>  $warehouseIds
     */
    private function openReservationsMatchOrder(ExternalOrder $order, Collection $reservations, array $warehouseIds): bool
    {
        $desired = $this->reservableLineQuantities($order);

        if ($desired === []) {
            return $reservations->isEmpty();
        }

        $current = [];

        foreach ($reservations as $reservation) {
            if (! in_array((int) $reservation->warehouse_id, $warehouseIds, true)) {
                return false;
            }

            $productId = (int) $reservation->product_id;
            $current[$productId] = ($current[$productId] ?? 0.0) + (float) $reservation->quantity;
        }

        ksort($current);

        if (array_keys($desired) !== array_keys($current)) {
            return false;
        }

        foreach ($desired as $productId => $quantity) {
            if (abs($quantity - (float) ($current[$productId] ?? 0.0)) > 0.00005) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, float>
     */
    private function reservableLineQuantities(ExternalOrder $order): array
    {
        $quantities = [];

        foreach ($order->lines as $line) {
            if ($line->product_id === null || (float) $line->quantity <= 0) {
                continue;
            }

            $productId = (int) $line->product_id;
            $quantities[$productId] = ($quantities[$productId] ?? 0.0) + (float) $line->quantity;
        }

        ksort($quantities);

        return $quantities;
    }

    private function skippedReservationLines(ExternalOrder $order): int
    {
        return $order->lines
            ->filter(fn ($line): bool => $line->product_id === null || (float) $line->quantity <= 0)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createReservation(
        int $warehouseId,
        int $productId,
        int $salesChannelId,
        string $externalOrderId,
        float $quantity,
        string $status,
        array $metadata,
    ): StockReservation {
        return StockReservation::query()->create([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'sales_channel_id' => $salesChannelId,
            'external_order_id' => $externalOrderId,
            'quantity' => $quantity,
            'status' => $status,
            'reserved_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    private function availableQuantity(int $warehouseId, int $productId): float
    {
        $balance = $this->lockedBalance($warehouseId, $productId);

        $onHand = (float) $balance->quantity_on_hand;
        $reserved = $this->activeQuantity($warehouseId, $productId);

        return max(0, $onHand - $reserved);
    }

    private function lockedBalance(int $warehouseId, int $productId): StockBalance
    {
        $now = now();

        DB::table('stock_balances')->insertOrIgnore([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'quantity_available' => 0,
            'recalculated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  Collection<int, StockReservation>  $reservations
     */
    private function reconciledReflectedOrderQuantities(
        Collection $reservations,
        int $salesChannelId,
        CarbonInterface $observedAt,
        array $existingQuantities,
    ): array {
        $quantities = collect($existingQuantities)
            ->filter(fn (mixed $quantity): bool => is_numeric($quantity) && (float) $quantity > 0)
            ->map(fn (mixed $quantity): float => (float) $quantity)
            ->all();
        $channelReservations = $reservations
            ->filter(fn (StockReservation $reservation): bool => (int) $reservation->sales_channel_id === $salesChannelId)
            ->values();

        if ($channelReservations->isEmpty()) {
            return $quantities;
        }

        $orders = $this->ordersForReservations($channelReservations, $salesChannelId);

        $channelReservations
            ->filter(fn (StockReservation $reservation): bool => $this->reservationWasReflected(
                $reservation,
                $orders,
                $observedAt,
            ))
            ->groupBy(fn (StockReservation $reservation): string => (string) $reservation->external_order_id)
            ->each(function (Collection $orderReservations, string|int $externalOrderId) use (&$quantities): void {
                $key = (string) $externalOrderId;

                // Freeze the quantity first reconciled into this source
                // snapshot. Reimports and reservation releases must not add
                // the same order twice or lower physical on-hand stock.
                if (! array_key_exists($key, $quantities)) {
                    $quantities[$key] = (float) $orderReservations->sum(
                        fn (StockReservation $reservation): float => (float) $reservation->quantity,
                    );
                }
            });

        ksort($quantities, SORT_NATURAL);

        return $quantities;
    }

    /**
     * @param  Collection<int, StockReservation>  $reservations
     * @return Collection<string, ExternalOrder>
     */
    private function ordersForReservations(Collection $reservations, int $salesChannelId): Collection
    {
        return ExternalOrder::query()
            ->where('sales_channel_id', $salesChannelId)
            ->whereIn('external_id', $reservations->pluck('external_order_id')->filter()->unique()->all())
            ->get(['external_id', 'external_created_at'])
            ->keyBy(fn (ExternalOrder $order): string => (string) $order->external_id);
    }

    /** @param  Collection<string, ExternalOrder>  $orders */
    private function reservationWasReflected(
        StockReservation $reservation,
        Collection $orders,
        CarbonInterface $observedAt,
    ): bool {
        $order = $orders->get((string) $reservation->external_order_id);
        $eventAt = $order?->external_created_at;

        if ($eventAt !== null) {
            // Woo timestamps are normalized to a UTC database clock before
            // Eloquent casts them, so compare the stored clock values.
            return $eventAt->format('Y-m-d H:i:s') <= $observedAt->format('Y-m-d H:i:s');
        }

        if ($reservation->reserved_at === null) {
            return false;
        }

        // Local ERP timestamps use the application timezone. Normalize the
        // fallback to the same UTC clock used by the Woo observation.
        return $reservation->reserved_at->copy()->utc()->format('Y-m-d H:i:s')
            <= $observedAt->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<array{0:int,1:int}>  $pairs
     */
    private function queueStockSync(array $pairs, string $reason): void
    {
        if ($pairs === []) {
            return;
        }

        $this->stockSyncQueue->queueForTriggers(
            array_map(
                fn (array $pair): array => [
                    'warehouse_id' => (int) $pair[0],
                    'product_id' => (int) $pair[1],
                ],
                $pairs,
            ),
            $reason,
        );
    }

    /**
     * @param  list<array{0:int,1:int}>  $pairs
     * @return list<array{0:int,1:int}>
     */
    private function uniquePairs(array $pairs): array
    {
        $unique = [];

        foreach ($pairs as [$warehouseId, $productId]) {
            $unique["{$warehouseId}:{$productId}"] = [$warehouseId, $productId];
        }

        return array_values($unique);
    }
}
