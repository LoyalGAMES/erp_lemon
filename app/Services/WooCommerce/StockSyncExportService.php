<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\IntegrationSyncLog;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\StockSyncQueueItem;
use App\Models\StockSyncState;
use App\Models\WordpressIntegration;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class StockSyncExportService
{
    public function __construct(
        private readonly WooCommerceClient $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(StockSyncQueueItem $item): array
    {
        $item = StockSyncQueueItem::query()
            ->with(['product', 'salesChannel'])
            ->findOrFail($item->id);

        if ($item->sales_channel_id === null) {
            throw new RuntimeException('Eksport stanu nie ma przypisanego kanału sprzedaży.');
        }

        try {
            return Cache::lock($this->exportLockKey($item), 180)
                ->block(30, fn (): array => $this->exportWhileLocked($item));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Inny eksport stanu tego produktu jest nadal przetwarzany.',
                previous: $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function exportWhileLocked(StockSyncQueueItem $item): array
    {
        $item = $this->claimForExport($item);

        if (! $item instanceof StockSyncQueueItem) {
            return ['skipped' => true, 'reason' => 'already_processed_or_superseded'];
        }

        // Refresh relations after acquiring the per-product export lock. The
        // storefront hold may have changed while this queue item was waiting.
        $item->load(['product', 'salesChannel']);

        $integration = WordpressIntegration::query()
            ->where('sales_channel_id', $item->sales_channel_id)
            ->where('stock_export_enabled', true)
            ->first();

        if ($integration === null) {
            throw new RuntimeException('Brak aktywnej integracji WooCommerce z włączonym eksportem stanów dla tego kanału.');
        }

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $item->product_id)
            ->where('sales_channel_id', $item->sales_channel_id)
            ->where('stock_sync_enabled', true)
            ->first();

        if ($mapping === null) {
            throw new RuntimeException('Brak mapowania produktu do kanału WooCommerce albo synchronizacja produktu jest wyłączona.');
        }

        $forceStorefrontStockZero = $item->product?->forcesStorefrontStockZero() ?? false;
        $quantity = $forceStorefrontStockZero
            ? 0
            : max(0, (float) $item->quantity_to_push);
        $stockQuantity = (int) floor($quantity);
        $targets = collect([$mapping]);

        ProductChannelAlias::query()
            ->where('product_id', $item->product_id)
            ->where('sales_channel_id', $item->sales_channel_id)
            ->get()
            ->filter(fn (ProductChannelAlias $alias): bool => $alias->isOutboundSyncEnabled())
            ->each(function (ProductChannelAlias $alias) use ($targets): void {
                $targets->push(new ProductChannelMapping([
                    'product_id' => $alias->product_id,
                    'sales_channel_id' => $alias->sales_channel_id,
                    'external_product_id' => $alias->external_product_id,
                    'external_variation_id' => $alias->external_variation_id,
                    'external_sku' => $alias->external_sku,
                    'stock_sync_enabled' => true,
                ]));
            });

        $responses = $targets
            ->unique(fn (ProductChannelMapping $target): string => ProductChannelAlias::externalKey(
                (string) $target->external_product_id,
                $target->external_variation_id !== null ? (string) $target->external_variation_id : null,
            ))
            ->values()
            ->map(function (ProductChannelMapping $target) use ($integration, $stockQuantity): array {
                return [
                    'external_product_id' => (string) $target->external_product_id,
                    'external_variation_id' => $target->external_variation_id !== null
                        ? (string) $target->external_variation_id
                        : null,
                    'response' => $this->client->updateStock($integration, $target, $stockQuantity),
                ];
            });
        $response = (array) data_get($responses->first(), 'response', []);

        $metadata = array_merge($item->metadata ?? [], [
            'woocommerce_stock_quantity' => $stockQuantity,
            'woocommerce_stock_status' => $stockQuantity > 0 ? 'instock' : 'outofstock',
            'storefront_stock_forced_zero' => $forceStorefrontStockZero,
            'woocommerce_targets_updated' => $responses->count(),
            'woocommerce_translation_targets' => $responses->skip(1)->values()->all(),
            'processed_by' => 'ExportStockToWooCommerceJob',
        ]);
        $finalStatus = $this->finalizeSuccessfulExport($item, $metadata);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $item->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'export_stock',
            'status' => 'success',
            'external_resource' => $mapping->external_variation_id ? 'product_variation' : 'product',
            'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
            'request_payload' => [
                'sku' => $item->product?->sku,
                'quantity_to_push' => (float) $item->quantity_to_push,
                'effective_quantity_to_push' => $quantity,
                'storefront_stock_forced_zero' => $forceStorefrontStockZero,
                'stock_quantity' => $stockQuantity,
            ],
            'response_payload' => [
                'id' => $response['id'] ?? null,
                'sku' => $response['sku'] ?? null,
                'stock_quantity' => $response['stock_quantity'] ?? null,
                'stock_status' => $response['stock_status'] ?? null,
                'targets' => $responses->all(),
                'queue_item_status' => $finalStatus,
                'queue_item_version' => (int) $item->version,
            ],
            'attempts' => 1,
            'started_at' => $item->updated_at ?? now(),
            'finished_at' => now(),
        ]);

        return $response;
    }

    public function markFailed(StockSyncQueueItem $item, Throwable $exception): void
    {
        $failed = DB::transaction(function () use ($item, $exception): bool {
            $state = $this->stateForItem($item, true);
            $item = StockSyncQueueItem::query()->lockForUpdate()->find($item->id);

            if (! $item instanceof StockSyncQueueItem || in_array($item->status, ['success', 'superseded'], true)) {
                return false;
            }

            if ((int) $item->version < (int) $state->desired_version) {
                $this->markSuperseded($item, $state, 'failed_after_newer_version');

                return false;
            }

            $item->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);

            return true;
        }, 3);

        if (! $failed) {
            return;
        }

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $item->sales_channel_id,
            'direction' => 'out',
            'operation' => 'export_stock',
            'status' => 'failed',
            'external_resource' => 'stock_sync_queue_item',
            'external_id' => (string) $item->id,
            'error_message' => $exception->getMessage(),
            'attempts' => 1,
            'started_at' => $item->updated_at ?? now(),
            'finished_at' => now(),
        ]);
    }

    private function claimForExport(StockSyncQueueItem $item): ?StockSyncQueueItem
    {
        return DB::transaction(function () use ($item): ?StockSyncQueueItem {
            $state = $this->stateForItem($item, true);
            $item = StockSyncQueueItem::query()->lockForUpdate()->findOrFail($item->id);

            if (in_array($item->status, ['success', 'superseded', 'running'], true)) {
                return null;
            }

            if ((int) $item->version < (int) $state->desired_version) {
                $this->markSuperseded($item, $state, 'newer_version_queued');

                return null;
            }

            if ((int) $item->version > (int) $state->desired_version) {
                $state->update([
                    'desired_version' => (int) $item->version,
                    'desired_quantity' => (float) $item->quantity_to_push,
                    'queue_item_id' => $item->id,
                ]);
            }

            $item->update([
                'status' => 'running',
                'last_error' => null,
            ]);

            return $item->refresh();
        }, 3);
    }

    /** @param  array<string, mixed>  $metadata */
    private function finalizeSuccessfulExport(StockSyncQueueItem $item, array $metadata): string
    {
        return DB::transaction(function () use ($item, $metadata): string {
            $state = $this->stateForItem($item, true);
            $item = StockSyncQueueItem::query()->lockForUpdate()->findOrFail($item->id);

            if ((int) $item->version < (int) $state->desired_version) {
                $item->update([
                    'status' => 'superseded',
                    'processed_at' => now(),
                    'last_error' => null,
                    'metadata' => array_merge($metadata, [
                        'superseded_reason' => 'newer_version_queued_during_export',
                        'desired_version_after_export' => (int) $state->desired_version,
                    ]),
                ]);

                return 'superseded';
            }

            $item->update([
                'status' => 'success',
                'processed_at' => now(),
                'last_error' => null,
                'metadata' => $metadata,
            ]);
            $state->update([
                'exported_version' => max((int) $state->exported_version, (int) $item->version),
                'queue_item_id' => $item->id,
            ]);

            return 'success';
        }, 3);
    }

    private function stateForItem(StockSyncQueueItem $item, bool $lock): StockSyncState
    {
        if ($item->sales_channel_id === null) {
            throw new RuntimeException('Eksport stanu nie ma przypisanego kanału sprzedaży.');
        }

        $now = now();
        DB::table('stock_sync_states')->insertOrIgnore([
            'product_id' => $item->product_id,
            'sales_channel_id' => $item->sales_channel_id,
            'desired_version' => (int) $item->version,
            'desired_quantity' => (float) $item->quantity_to_push,
            'exported_version' => 0,
            'queue_item_id' => $item->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return StockSyncState::query()
            ->where('product_id', $item->product_id)
            ->where('sales_channel_id', $item->sales_channel_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->firstOrFail();
    }

    private function markSuperseded(StockSyncQueueItem $item, StockSyncState $state, string $reason): void
    {
        $item->update([
            'status' => 'superseded',
            'processed_at' => now(),
            'last_error' => null,
            'metadata' => array_merge($item->metadata ?? [], [
                'superseded_reason' => $reason,
                'item_version' => (int) $item->version,
                'desired_version' => (int) $state->desired_version,
            ]),
        ]);
    }

    private function exportLockKey(StockSyncQueueItem $item): string
    {
        return 'stock-sync-export:'.$item->sales_channel_id.':'.$item->product_id;
    }
}
