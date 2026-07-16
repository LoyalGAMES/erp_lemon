<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RepairWooOwnedVariantAxisJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 70;

    public int $timeout = 300;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 120, 300, 900];
    }

    public function __construct(
        private readonly int $productId,
        private readonly string $token,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        $familyRootId = app(WooOwnedVariantAxisRepairService::class)
            ->familyRootId($this->productId);
        $middleware = [
            (new WithoutOverlapping(ExportWooCommerceProductDataJob::lockKey($familyRootId)))
                ->releaseAfter(60)
                ->expireAfter(ExportWooCommerceProductDataJob::LOCK_SECONDS)
                ->withPrefix('')
                ->shared(),
        ];

        ImportWooCommerceProductsJob::catalogIntegrationIdsForProduct($familyRootId)
            ->each(function (int $integrationId) use (&$middleware): void {
                $middleware[] = ImportWooCommerceProductsJob::catalogLock($integrationId);
            });

        return $middleware;
    }

    public function handle(
        WooOwnedVariantAxisRepairService $repair,
        LegacyVariantFamilyBackfillService $backfill,
    ): void {
        if (! $repair->hasCurrentReservation($this->productId, $this->token)) {
            return;
        }

        $product = Product::query()->find($this->productId);

        if (! $product instanceof Product) {
            $repair->completeReservation($this->productId, $this->token, [
                'status' => 'manual_review',
                'targets' => 0,
                'mutations' => 0,
                'reason' => 'Produkt ERP został usunięty przed wykonaniem naprawy.',
            ]);

            return;
        }

        $result = $repair->repair($product);

        if (in_array(($result['status'] ?? null), ['repaired', 'already_canonical'], true)) {
            // Axis-only repair deliberately protects commercial data. Once
            // the exact PL/EN family and every child alias are verified, run
            // one durable full export so Woo receives global term order,
            // publication dates, inherited content and final stock through
            // the now-canonical identities.
            $result['full_export_queue'] = $backfill->queueProductRevision(
                $product,
                ($result['status'] ?? null) === 'repaired'
                    ? LegacyVariantFamilyBackfillService::CHILD_SIZE_ASSIGNMENT_CATALOG_SYNC_REVISION
                    : LegacyVariantFamilyBackfillService::WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION,
            );
        } elseif (($result['allow_full_export'] ?? false) === true) {
            $result['full_export_queue'] = $backfill->queueProductRevision(
                $product,
                WooOwnedVariantAxisRepairService::REVISION.':missing-translation:'.$this->token,
            );
        }

        $repair->completeReservation($this->productId, $this->token, $result);

        foreach ($product->channelMappings()->get() as $mapping) {
            if (filled($mapping->external_variation_id)) {
                continue;
            }

            $integration = WordpressIntegration::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->first();

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $mapping->sales_channel_id,
                'wordpress_integration_id' => $integration?->id,
                'direction' => 'out',
                'operation' => 'repair_woo_owned_variant_axis',
                'status' => ($result['status'] ?? null) === 'manual_review' ? 'failed' : 'success',
                'external_resource' => 'product',
                'external_id' => $mapping->external_product_id,
                'request_payload' => [
                    'source' => WooOwnedVariantAxisRepairService::REVISION,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'axis_only' => true,
                ],
                'response_payload' => $result,
                'error_message' => ($result['status'] ?? null) === 'manual_review'
                    ? (string) ($result['reason'] ?? 'Rodzina wymaga ręcznej weryfikacji.')
                    : null,
                'attempts' => max(1, $this->attempts()),
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(WooOwnedVariantAxisRepairService::class)->failReservation(
            $this->productId,
            $this->token,
            $exception,
        );

        $product = Product::query()
            ->with('channelMappings')
            ->find($this->productId);

        if (! $product instanceof Product) {
            return;
        }

        foreach ($product->channelMappings as $mapping) {
            if (filled($mapping->external_variation_id)) {
                continue;
            }

            $integration = WordpressIntegration::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->first();

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $mapping->sales_channel_id,
                'wordpress_integration_id' => $integration?->id,
                'direction' => 'out',
                'operation' => 'repair_woo_owned_variant_axis',
                'status' => 'failed',
                'external_resource' => 'product',
                'external_id' => $mapping->external_product_id,
                'request_payload' => [
                    'source' => WooOwnedVariantAxisRepairService::REVISION,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'axis_only' => true,
                ],
                'error_message' => $exception->getMessage(),
                'attempts' => max(1, $this->attempts()),
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        }
    }
}
