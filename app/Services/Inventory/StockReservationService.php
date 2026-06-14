<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ExternalOrder;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\WarehouseDocument;
use App\Services\Orders\OrderFulfillmentStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class StockReservationService
{
    private const RESERVING_STATUSES = ['pending', 'processing', 'on-hold'];

    private const ACTIVE_STATUS = 'active';

    private const WAITING_STATUS = 'waiting';

    private const RELEASED_STATUS = 'released';

    public function __construct(
        private readonly SalesChannelWarehouseResolver $warehouseResolver,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
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
                $warehouse = $this->warehouseResolver->resolve($order->sales_channel_id);

                if ($this->openReservationsMatchOrder($order, $openReservations, (int) $warehouse->id)) {
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
                    $available = $this->availableQuantity((int) $warehouse->id, (int) $line->product_id);
                    $activeQuantity = min($quantity, max(0, $available));
                    $waitingQuantity = max(0, $quantity - $activeQuantity);

                    if ($activeQuantity > 0) {
                        $this->createReservation(
                            (int) $warehouse->id,
                            (int) $line->product_id,
                            (int) $order->sales_channel_id,
                            (string) $order->external_id,
                            $activeQuantity,
                            self::ACTIVE_STATUS,
                            [
                                'external_order_number' => $order->external_number,
                                'external_order_line_id' => $line->external_line_id,
                                'source' => 'woocommerce_order_import',
                            ],
                        );
                    }

                    if ($waitingQuantity > 0) {
                        $this->createReservation(
                            (int) $warehouse->id,
                            (int) $line->product_id,
                            (int) $order->sales_channel_id,
                            (string) $order->external_id,
                            $waitingQuantity,
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
                    }

                    $reserved++;
                    $newPairs[] = [$warehouse->id, (int) $line->product_id];
                }
            }

            foreach ($this->uniquePairs([...$releasedPairs, ...$newPairs]) as [$warehouseId, $productId]) {
                $this->recalculateBalance($warehouseId, $productId);
            }

            return [
                'reserved' => $reserved,
                'released' => count($releasedPairs),
                'skipped' => $skipped,
            ];
        });
    }

    public function recalculateBalance(int $warehouseId, int $productId): void
    {
        $reserved = (float) StockReservation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('status', self::ACTIVE_STATUS)
            ->sum('quantity');

        $balance = StockBalance::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
            ],
            [
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'quantity_available' => 0,
            ],
        );

        $onHand = (float) $balance->quantity_on_hand;
        $balance->update([
            'quantity_reserved' => $reserved,
            'quantity_available' => max(0, $onHand - $reserved),
            'recalculated_at' => now(),
        ]);
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
        return in_array($status, self::RESERVING_STATUSES, true);
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
     */
    private function openReservationsMatchOrder(ExternalOrder $order, Collection $reservations, int $warehouseId): bool
    {
        $desired = $this->reservableLineQuantities($order);

        if ($desired === []) {
            return $reservations->isEmpty();
        }

        $current = [];

        foreach ($reservations as $reservation) {
            if ((int) $reservation->warehouse_id !== $warehouseId) {
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
        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $onHand = (float) ($balance?->quantity_on_hand ?? 0);
        $reserved = (float) StockReservation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('status', self::ACTIVE_STATUS)
            ->sum('quantity');

        return max(0, $onHand - $reserved);
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
