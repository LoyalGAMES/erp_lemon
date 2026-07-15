<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKFILL_PATH = 'product_data_export.legacy_variant_backfill';

    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $visitedProductIds = [];

        ProductChannelMapping::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with('product')
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($backfill, &$visitedProductIds): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($visitedProductIds[$product->id])
                        || ! $this->wasSuccessfullyRepaired($mapping)
                        || $this->alreadyRequested($mapping)
                    ) {
                        continue;
                    }

                    $visitedProductIds[$product->id] = true;
                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION,
                    );
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A completed export may already have corrected
        // global size order, dates and stock in WooCommerce.
    }

    private function wasSuccessfullyRepaired(ProductChannelMapping $mapping): bool
    {
        $state = (array) data_get(
            $mapping->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );

        return ($state['revision'] ?? null) === WooOwnedVariantAxisRepairService::REVISION
            && ($state['status'] ?? null) === 'completed'
            && in_array(data_get($state, 'result.status'), ['repaired', 'already_canonical'], true);
    }

    private function alreadyRequested(ProductChannelMapping $mapping): bool
    {
        return data_get(
            $mapping->metadata,
            self::BACKFILL_PATH.'.revision',
        ) === LegacyVariantFamilyBackfillService::WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION;
    }
};
