<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('product_channel_mappings')) {
            return;
        }

        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $markedProductIds = [];

        ProductChannelMapping::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with(['product.parentRelations'])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($backfill, &$markedProductIds): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($markedProductIds[$product->id])
                        || ! $this->isCanonicalErpRoot($product)
                        || ! $this->hasCustomLabel($product)
                    ) {
                        continue;
                    }

                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::CUSTOM_PRODUCT_LABELS_CATALOG_SYNC_REVISION,
                    );
                    $markedProductIds[$product->id] = true;
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op: a completed remote product export is not reversible.
    }

    private function isCanonicalErpRoot(Product $product): bool
    {
        return ! $product->is_translation
            && trim((string) $product->sku) !== ''
            && $product->masterSource() === 'erp'
            && data_get($product->masterData(), 'product_type') !== 'variation'
            && ! $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            );
    }

    private function hasCustomLabel(Product $product): bool
    {
        $label = (array) data_get($product->masterData(), 'custom_label', []);

        return collect(['pl', 'en'])->contains(
            fn (string $language): bool => trim((string) ($label[$language] ?? '')) !== '',
        );
    }
};
