<?php

use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_integrations')
            && Schema::hasTable('sales_channels')
            && Schema::hasTable('product_parameter_definitions')
            && Schema::hasTable('jobs')
        ) {
            // Reapply the exact sequence configured in ERP to every existing
            // PL/EN Size term. The job performs remote writes only after the
            // deployment; this migration merely creates durable queue work.
            SyncWooCommerceGlobalSizeOrderJob::dispatchForActiveIntegrations(
                'erp_size_configuration_order_2026_07_17_000038',
            );
        }

        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $repair = app(WooOwnedVariantAxisRepairService::class);
        $visited = [];

        // Re-audit every locally proven Size family so its parent option list
        // and each existing child variation receive the same ERP-backed
        // menu_order. The later live multilingual preflight remains fail-
        // closed and the repair payload protects SKU, stock and prices.
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
                        $candidate = $repair->isChildSizeAssignmentAuditCandidate($product);
                    } catch (Throwable) {
                        // A dirty historical dictionary must not leave the
                        // whole application in maintenance mode. The live
                        // order synchronizer remains fail-closed and records
                        // the exact conflict for manual cleanup.
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
        // Deliberate no-op. A queued or completed storefront ordering repair
        // must not be reverted to the former inferred size order.
    }
};
