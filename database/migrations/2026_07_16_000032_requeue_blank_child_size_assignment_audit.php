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

        // Revision 000031 required a concrete local child option and therefore
        // missed mixed-ownership families whose child option snapshots had all
        // been erased. Re-audit every safely recognized Size family, including
        // that exact blank-child/duplicated-parent shape. The repair itself is
        // still remote-first and requires an exact multilingual SKU bijection
        // before sending any axis-only PUT.
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
                        $candidate = $repair->isSizeVariantRootCandidate($product)
                            || $repair->isChildSizeAssignmentAuditCandidate($product)
                            || $repair->isComplementaryLanguageSizeRootCandidate($product);
                    } catch (DomainException) {
                        $candidate = false;
                    }

                    if (! $candidate) {
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
