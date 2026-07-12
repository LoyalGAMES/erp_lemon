<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Jobs\ExportStockToWooCommerceJob;
use App\Models\ProductChannelMapping;
use App\Models\StockBalance;
use App\Models\StockSyncQueueItem;
use App\Models\StockSyncState;
use App\Models\Warehouse;
use App\Models\WarehouseChannelRoute;
use App\Models\WordpressIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StockSyncQueueService
{
    public function __construct(
        private readonly ChannelStockAvailabilityService $channelStock,
    ) {}

    /**
     * @param  list<array{warehouse_id:int,product_id:int}>  $triggers
     */
    public function queueForTriggers(array $triggers, string $reason): int
    {
        $triggers = collect($triggers)
            ->unique(fn (array $trigger): string => $trigger['warehouse_id'].':'.$trigger['product_id'])
            ->values();

        if ($triggers->isEmpty()) {
            return 0;
        }

        $exportEnabledSalesChannelIds = $this->exportEnabledSalesChannelIds();

        if ($exportEnabledSalesChannelIds->isEmpty()) {
            return 0;
        }

        $routesByWarehouse = WarehouseChannelRoute::query()
            ->with('warehouse')
            ->whereIn('warehouse_id', $triggers->pluck('warehouse_id')->unique()->values()->all())
            ->whereIn('sales_channel_id', $exportEnabledSalesChannelIds->all())
            ->where('push_stock', true)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->orderBy('priority')
            ->orderBy('warehouse_id')
            ->get()
            ->groupBy('warehouse_id');

        $queued = [];
        $queuedCount = 0;

        $triggers->each(function (array $trigger) use ($routesByWarehouse, &$queued, &$queuedCount, $reason): void {
            $routes = $routesByWarehouse->get($trigger['warehouse_id'], collect());

            foreach ($routes as $route) {
                $key = $trigger['product_id'].':'.$route->sales_channel_id;

                if (isset($queued[$key])) {
                    continue;
                }

                $this->queueItem(
                    (int) $trigger['warehouse_id'],
                    (int) $trigger['product_id'],
                    (int) $route->sales_channel_id,
                    $reason,
                    [
                        'source_warehouse_id' => (int) $trigger['warehouse_id'],
                        'source_warehouse_code' => $route->warehouse?->code,
                    ],
                );

                $queued[$key] = true;
                $queuedCount++;
            }
        });

        return $queuedCount;
    }

    /**
     * @param  list<int>  $beforeSalesChannelIds
     * @param  list<int>  $afterSalesChannelIds
     */
    public function queueForWarehouseRouteChange(
        Warehouse $warehouse,
        array $beforeSalesChannelIds,
        array $afterSalesChannelIds,
        string $reason = 'warehouse_routes_updated',
    ): int {
        $salesChannelIds = collect([...$beforeSalesChannelIds, ...$afterSalesChannelIds])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($salesChannelIds->isEmpty()) {
            return 0;
        }

        $salesChannelIds = $salesChannelIds
            ->intersect($this->exportEnabledSalesChannelIds())
            ->values();

        if ($salesChannelIds->isEmpty()) {
            return 0;
        }

        $productIds = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return 0;
        }

        $mappedPairs = ProductChannelMapping::query()
            ->whereIn('product_id', $productIds->all())
            ->whereIn('sales_channel_id', $salesChannelIds->all())
            ->where('stock_sync_enabled', true)
            ->get(['product_id', 'sales_channel_id']);

        $queued = 0;

        foreach ($mappedPairs as $mapping) {
            $this->queueItem(
                (int) $warehouse->id,
                (int) $mapping->product_id,
                (int) $mapping->sales_channel_id,
                $reason,
                [
                    'source_warehouse_id' => (int) $warehouse->id,
                    'source_warehouse_code' => $warehouse->code,
                    'before_sales_channel_ids' => $beforeSalesChannelIds,
                    'after_sales_channel_ids' => $afterSalesChannelIds,
                ],
            );
            $queued++;
        }

        return $queued;
    }

    private function exportEnabledSalesChannelIds(): Collection
    {
        return WordpressIntegration::query()
            ->where('stock_export_enabled', true)
            ->pluck('sales_channel_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return array{queued:int,skipped:int,sales_channel_ids:list<int>,product_ids:list<int>}
     */
    public function queueFullRebuild(?int $salesChannelId = null, string $reason = 'manual_full_stock_rebuild'): array
    {
        $salesChannelIds = WordpressIntegration::query()
            ->where('stock_export_enabled', true)
            ->when($salesChannelId !== null, fn ($query) => $query->where('sales_channel_id', $salesChannelId))
            ->pluck('sales_channel_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($salesChannelIds->isEmpty()) {
            return [
                'queued' => 0,
                'skipped' => 0,
                'sales_channel_ids' => [],
                'product_ids' => [],
            ];
        }

        $primaryRoutes = WarehouseChannelRoute::query()
            ->with('warehouse')
            ->whereIn('sales_channel_id', $salesChannelIds->all())
            ->where('push_stock', true)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->orderBy('priority')
            ->orderBy('warehouse_id')
            ->get()
            ->groupBy('sales_channel_id')
            ->map(fn ($routes) => $routes->first());

        $mappings = ProductChannelMapping::query()
            ->whereIn('sales_channel_id', $salesChannelIds->all())
            ->where('stock_sync_enabled', true)
            ->orderBy('sales_channel_id')
            ->orderBy('product_id')
            ->get(['product_id', 'sales_channel_id']);

        $queued = 0;
        $skipped = 0;
        $productIds = [];

        foreach ($mappings as $mapping) {
            $route = $primaryRoutes->get((int) $mapping->sales_channel_id);

            if (! $route instanceof WarehouseChannelRoute) {
                $skipped++;

                continue;
            }

            $this->queueItem(
                (int) $route->warehouse_id,
                (int) $mapping->product_id,
                (int) $mapping->sales_channel_id,
                $reason,
                [
                    'source' => 'manual_full_rebuild',
                    'source_warehouse_id' => (int) $route->warehouse_id,
                    'source_warehouse_code' => $route->warehouse?->code,
                ],
            );

            $productIds[] = (int) $mapping->product_id;
            $queued++;
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'sales_channel_ids' => $salesChannelIds->all(),
            'product_ids' => collect($productIds)->unique()->values()->all(),
        ];
    }

    /**
     * @return array{scanned:int,dispatched:int}
     */
    public function dispatchPending(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $ids = StockSyncQueueItem::query()
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->oldest('available_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $dispatched = 0;

        foreach ($ids as $id) {
            $queueItem = DB::transaction(function () use ($id): ?StockSyncQueueItem {
                $item = StockSyncQueueItem::query()
                    ->lockForUpdate()
                    ->find($id);

                if (! $item instanceof StockSyncQueueItem || $item->status !== 'pending') {
                    return null;
                }

                $metadata = $item->metadata ?? [];
                $metadata['dispatch_count'] = (int) ($metadata['dispatch_count'] ?? 0) + 1;
                $metadata['last_dispatch_requested_at'] = now()->toISOString();
                $metadata['dispatch_source'] = 'scheduled_stock_sync_dispatch';

                $item->update([
                    'status' => 'queued',
                    'metadata' => $metadata,
                ]);

                return $item;
            });

            if ($queueItem instanceof StockSyncQueueItem) {
                ExportStockToWooCommerceJob::dispatch($queueItem->id);
                $dispatched++;
            }
        }

        return [
            'scanned' => count($ids),
            'dispatched' => $dispatched,
        ];
    }

    /**
     * @return array{released:int}
     */
    public function releaseStaleRunning(int $minutes = 30, int $limit = 100): array
    {
        $threshold = now()->subMinutes(max(1, $minutes));
        $limit = max(1, min($limit, 500));

        $ids = StockSyncQueueItem::query()
            ->where('status', 'running')
            ->where('updated_at', '<=', $threshold)
            ->oldest('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $released = 0;

        foreach ($ids as $id) {
            $released += DB::transaction(function () use ($id, $minutes): int {
                $item = StockSyncQueueItem::query()
                    ->lockForUpdate()
                    ->find($id);

                if (! $item instanceof StockSyncQueueItem || $item->status !== 'running') {
                    return 0;
                }

                $metadata = $item->metadata ?? [];
                $metadata['stale_running_released_at'] = now()->toISOString();
                $metadata['stale_running_threshold_minutes'] = $minutes;

                $item->update([
                    'status' => 'pending',
                    'available_at' => now(),
                    'last_error' => null,
                    'metadata' => $metadata,
                ]);

                return 1;
            });
        }

        return ['released' => $released];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function queueItem(
        int $warehouseId,
        int $productId,
        int $salesChannelId,
        string $reason,
        array $metadata = [],
    ): StockSyncQueueItem {
        $availability = $this->channelStock->availabilityForProduct($salesChannelId, $productId);
        $quantityToPush = max(0, $availability['quantity']);
        $payloadMetadata = array_merge($metadata, [
            'reason' => $reason,
            'calculation' => 'channel_warehouse_route_aggregate',
            'breakdown' => $availability['breakdown'],
        ]);

        [$queueItem, $shouldDispatch] = DB::transaction(function () use (
            $warehouseId,
            $productId,
            $salesChannelId,
            $reason,
            $quantityToPush,
            $payloadMetadata,
        ): array {
            $now = now();

            DB::table('stock_sync_states')->insertOrIgnore([
                'product_id' => $productId,
                'sales_channel_id' => $salesChannelId,
                'desired_version' => 0,
                'desired_quantity' => 0,
                'exported_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $state = StockSyncState::query()
                ->where('product_id', $productId)
                ->where('sales_channel_id', $salesChannelId)
                ->lockForUpdate()
                ->firstOrFail();
            $version = (int) $state->desired_version + 1;
            $existing = StockSyncQueueItem::query()
                ->where('product_id', $productId)
                ->where('sales_channel_id', $salesChannelId)
                ->whereIn('status', ['pending', 'queued'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($existing instanceof StockSyncQueueItem) {
                $previousMetadata = $existing->metadata ?? [];
                $metadata = array_merge($previousMetadata, $payloadMetadata, [
                    'previous_reason' => $previousMetadata['reason'] ?? null,
                    'latest_reason' => $reason,
                    'previous_quantity_to_push' => (float) $existing->quantity_to_push,
                    'latest_quantity_to_push' => $quantityToPush,
                    'previous_version' => (int) $existing->version,
                    'latest_version' => $version,
                    'coalesced_count' => (int) ($previousMetadata['coalesced_count'] ?? 0) + 1,
                    'coalesced_at' => $now->toISOString(),
                ]);

                $existing->update([
                    'warehouse_id' => $warehouseId,
                    'version' => $version,
                    'quantity_to_push' => $quantityToPush,
                    'available_at' => $now,
                    'last_error' => null,
                    'metadata' => $metadata,
                ]);
                $queueItem = $existing->refresh();
                $shouldDispatch = false;
            } else {
                $queueItem = StockSyncQueueItem::query()->create([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'sales_channel_id' => $salesChannelId,
                    'version' => $version,
                    'status' => 'pending',
                    'quantity_to_push' => $quantityToPush,
                    'available_at' => $now,
                    'metadata' => array_merge($payloadMetadata, [
                        'latest_version' => $version,
                    ]),
                ]);
                $shouldDispatch = true;
            }

            $state->update([
                'desired_version' => $version,
                'desired_quantity' => $quantityToPush,
                'queue_item_id' => $queueItem->id,
            ]);

            return [$queueItem, $shouldDispatch];
        }, 3);

        if ($shouldDispatch) {
            ExportStockToWooCommerceJob::dispatch($queueItem->id)->afterCommit();
        }

        return $queueItem;
    }
}
