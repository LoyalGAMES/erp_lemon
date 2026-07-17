<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $repair = app(WooOwnedVariantAxisRepairService::class);
        $visited = [];

        // Earlier revisions deliberately stopped when a parent exposed more
        // than one generic legacy axis. Requeue only families whose local ERP
        // snapshots prove that at least two distinct aliases (for example
        // `wariant` and `BLVariant`) are exact duplicates of Size. The live
        // multilingual preflight remains authoritative and performs no PUT
        // unless every variation maps 1:1 to the same Size option.
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
            ->with([
                'product.parentRelations',
                'product.variantChildren.parentRelations',
                'product.variantChildren.channelMappings',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($repair, &$visited): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product || isset($visited[$product->id])) {
                        continue;
                    }

                    $visited[$product->id] = true;

                    try {
                        $candidate = $repair->isMultipleLegacySizeAxisCandidate($product);
                    } catch (DomainException) {
                        $candidate = false;
                    }

                    if ($candidate) {
                        $repair->markPending($product);
                    }
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A completed remote repair must never be reverted.
    }
};
