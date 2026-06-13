<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\IntegrationSyncLog;
use App\Models\ProductChannelMapping;
use App\Models\StockSyncQueueItem;
use App\Models\WordpressIntegration;
use RuntimeException;
use Throwable;

final class StockSyncExportService
{
    public function __construct(
        private readonly WooCommerceClient $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(StockSyncQueueItem $item): array
    {
        $item = StockSyncQueueItem::query()
            ->with(['product', 'salesChannel'])
            ->findOrFail($item->id);

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

        $quantity = max(0, (float) $item->quantity_to_push);
        $response = $this->client->updateStock($integration, $mapping, $quantity);

        $item->update([
            'status' => 'success',
            'processed_at' => now(),
            'last_error' => null,
            'metadata' => array_merge($item->metadata ?? [], [
                'woocommerce_stock_quantity' => (int) floor($quantity),
                'woocommerce_stock_status' => $quantity > 0 ? 'instock' : 'outofstock',
                'processed_by' => 'ExportStockToWooCommerceJob',
            ]),
        ]);

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
                'quantity_to_push' => $quantity,
                'stock_quantity' => (int) floor($quantity),
            ],
            'response_payload' => [
                'id' => $response['id'] ?? null,
                'sku' => $response['sku'] ?? null,
                'stock_quantity' => $response['stock_quantity'] ?? null,
                'stock_status' => $response['stock_status'] ?? null,
            ],
            'attempts' => 1,
            'started_at' => $item->updated_at ?? now(),
            'finished_at' => now(),
        ]);

        return $response;
    }

    public function markFailed(StockSyncQueueItem $item, Throwable $exception): void
    {
        $item->update([
            'status' => 'failed',
            'last_error' => $exception->getMessage(),
        ]);

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
}
