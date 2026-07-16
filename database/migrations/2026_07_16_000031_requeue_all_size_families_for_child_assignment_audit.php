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

        // A parent can already expose the canonical pa_rozmiar taxonomy while
        // one or more variation posts still have no concrete term assigned.
        // Such a family looks canonical at parent level, so re-audit every
        // locally proven Size family against the live child rows. The repair
        // remains remote-first and changes only attributes/menu_order; SKU,
        // stock, prices and all other commercial fields are protected.
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
            ])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($repair, &$visited): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product || isset($visited[$product->id])) {
                        continue;
                    }

                    $visited[$product->id] = true;

                    if (! $repair->isChildSizeAssignmentAuditCandidate($product)) {
                        continue;
                    }

                    $repair->markPending($product);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A remote audit may already have restored concrete
        // child term assignments and must never be rolled back.
    }
};
