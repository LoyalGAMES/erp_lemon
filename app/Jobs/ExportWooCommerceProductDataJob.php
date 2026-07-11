<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ExportWooCommerceProductDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        private readonly int $productId,
    ) {}

    public function handle(ProductDataExportService $exporter): void
    {
        $product = Product::query()
            ->with('channelMappings.salesChannel')
            ->find($this->productId);

        if (! $product instanceof Product || $product->channelMappings->isEmpty()) {
            return;
        }

        $exporter->export($product);
    }

    public function failed(Throwable $exception): void
    {
        $product = Product::query()
            ->with('channelMappings')
            ->find($this->productId);

        if (! $product instanceof Product) {
            return;
        }

        foreach ($product->channelMappings as $mapping) {
            $integration = WordpressIntegration::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->first();

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $mapping->sales_channel_id,
                'wordpress_integration_id' => $integration?->id,
                'direction' => 'out',
                'operation' => 'export_product_data',
                'status' => 'failed',
                'external_resource' => $mapping->external_variation_id ? 'product_variation' : 'product',
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'request_payload' => [
                    'source' => 'erp_product_auto_export',
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                ],
                'error_message' => $exception->getMessage(),
                'attempts' => $this->attempts(),
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        }
    }
}
