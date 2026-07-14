<?php

use App\Models\Product;
use App\Models\ProductRelation;
use App\Services\Products\ProductVariantInheritanceService;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $inheritance = app(ProductVariantInheritanceService::class);
        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $markedParentIds = [];

        // The preceding migrations have repaired local relations and promoted
        // copied variants. Mark only families that carry an unambiguous copy
        // marker or a relation reconstructed from their WooCommerce mappings.
        // Network work is intentionally left to the scheduled queue dispatcher.
        ProductRelation::query()
            ->with(['parentProduct', 'childProduct'])
            ->where('relation_type', 'variant')
            ->orderBy('id')
            ->chunkById(100, function ($relations) use (
                $inheritance,
                $backfill,
                &$markedParentIds,
            ): void {
                foreach ($relations as $relation) {
                    $parent = $relation->parentProduct;

                    if (! $parent instanceof Product
                        || isset($markedParentIds[$parent->id])
                        || (! $inheritance->isCopiedFamily($parent)
                            && data_get($relation->metadata, 'source') !== 'woocommerce_mapping_relation_repair')
                    ) {
                        continue;
                    }

                    $backfill->markPending($parent);
                    $markedParentIds[$parent->id] = true;
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op: a queued or completed external repair must not be
        // silently cancelled when application code is rolled back.
    }
};
