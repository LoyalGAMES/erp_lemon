<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\ProductDataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExportWooCommerceProductDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 70;

    public int $timeout = 840;

    public const LOCK_SECONDS = 3600;

    public function __construct(
        private readonly int $productId,
        private readonly ?string $syncToken = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        if ($this->syncToken === null) {
            return [];
        }

        return [
            (new WithoutOverlapping(self::lockKey($this->productId)))
                ->releaseAfter(60)
                ->expireAfter(self::LOCK_SECONDS)
                ->withPrefix('')
                ->shared(),
        ];
    }

    public static function lockKey(int $productId): string
    {
        return "woocommerce-product-data:{$productId}";
    }

    public function handle(ProductDataExportService $exporter): void
    {
        try {
            $product = Product::query()
                ->with('channelMappings.salesChannel')
                ->find($this->productId);

            if (! $product instanceof Product || $product->channelMappings->isEmpty()) {
                return;
            }

            if (! $this->hasCurrentSyncToken($product)) {
                return;
            }

            $exporter->export($product);
            $this->clearCurrentSyncToken();
        } catch (Throwable $exception) {
            if ($this->job?->getConnectionName() === 'sync' && $this->syncToken !== null) {
                report($exception);

                return;
            }

            throw $exception;
        }
    }

    private function hasCurrentSyncToken(Product $product): bool
    {
        if ($this->syncToken === null) {
            return true;
        }

        return $product->channelMappings->contains(
            fn (ProductChannelMapping $mapping): bool => data_get(
                $mapping->metadata,
                'product_data_export.pending_token',
            ) === $this->syncToken,
        );
    }

    private function clearCurrentSyncToken(): void
    {
        if ($this->syncToken === null) {
            return;
        }

        DB::transaction(function (): void {
            $product = Product::query()->lockForUpdate()->find($this->productId);
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $this->productId)
                ->lockForUpdate()
                ->get();

            $mappings->each(function (ProductChannelMapping $mapping): void {
                $metadata = (array) $mapping->metadata;

                if (data_get($metadata, 'product_data_export.pending_token') !== $this->syncToken) {
                    return;
                }

                data_forget($metadata, 'product_data_export.pending_token');
                data_forget($metadata, 'product_data_export.requested_at');
                data_forget($metadata, 'product_data_export.stock_release_pending');
                data_set($metadata, 'product_data_export.completed_at', now()->toISOString());

                if (data_get($metadata, 'product_data_export.legacy_variant_backfill.reason')
                    === LegacyVariantFamilyBackfillService::REASON
                ) {
                    data_set($metadata, 'product_data_export.legacy_variant_backfill.status', 'completed');
                    data_set($metadata, 'product_data_export.legacy_variant_backfill.completed_at', now()->toISOString());
                    data_forget($metadata, 'product_data_export.legacy_variant_backfill.next_attempt_at');
                    data_forget($metadata, 'product_data_export.legacy_variant_backfill.failed_at');
                    data_forget($metadata, 'product_data_export.legacy_variant_backfill.error');
                }

                $mapping->forceFill(['metadata' => $metadata])->save();
            });

            $hasPendingExport = $mappings->contains(fn (ProductChannelMapping $mapping): bool => filled(
                data_get($mapping->metadata, 'product_data_export.pending_token'),
            ));

            if ($product instanceof Product && ! $product->isStorefrontHidden() && ! $hasPendingExport) {
                $product->forceFill(['storefront_restore_visibility' => null])->save();
            }
        });
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

        $this->markCurrentSyncTokenFailed($exception);
    }

    private function markCurrentSyncTokenFailed(Throwable $exception): void
    {
        if ($this->syncToken === null) {
            return;
        }

        DB::transaction(function () use ($exception): void {
            ProductChannelMapping::query()
                ->where('product_id', $this->productId)
                ->lockForUpdate()
                ->get()
                ->each(function (ProductChannelMapping $mapping) use ($exception): void {
                    $metadata = (array) $mapping->metadata;

                    if (data_get($metadata, 'product_data_export.pending_token') !== $this->syncToken) {
                        return;
                    }

                    data_forget($metadata, 'product_data_export.pending_token');
                    data_forget($metadata, 'product_data_export.requested_at');
                    data_set($metadata, 'product_data_export.failed_at', now()->toISOString());
                    data_set($metadata, 'product_data_export.error', $exception->getMessage());

                    if (data_get($metadata, 'product_data_export.legacy_variant_backfill.reason')
                        === LegacyVariantFamilyBackfillService::REASON
                    ) {
                        data_set($metadata, 'product_data_export.legacy_variant_backfill.status', 'pending');
                        data_set($metadata, 'product_data_export.legacy_variant_backfill.failed_at', now()->toISOString());
                        data_set(
                            $metadata,
                            'product_data_export.legacy_variant_backfill.next_attempt_at',
                            now()->addMinutes(15)->toISOString(),
                        );
                        data_set($metadata, 'product_data_export.legacy_variant_backfill.error', $exception->getMessage());
                    }

                    $mapping->forceFill(['metadata' => $metadata])->save();
                });
        });
    }
}
